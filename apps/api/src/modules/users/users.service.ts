import { Injectable } from '@nestjs/common';
import {
  ACTIVE_PROJECT_STATUSES,
  OPEN_TASK_STATUSES,
  type CreateUserResponse,
  type Paginated,
  type RoleCode,
  type UserDto,
  type UserWorkloadDto,
  type UserListQuery,
  type createUserSchema,
  type updateUserSchema,
} from '@fluggi/contracts';
import type { Prisma } from '@fluggi/db';
import type { z } from 'zod';
import type { AuthContext, RequestMeta } from '../../core/auth/auth-context';
import { PasswordService } from '../../core/auth/password.service';
import { SessionService } from '../../core/auth/session.service';
import { AuditService, diffFields } from '../../core/audit/audit.service';
import { businessRule, conflict, forbidden, notFound } from '../../core/http/app.exception';
import { OutboxService } from '../../core/outbox/outbox.service';
import { PrismaService, type Tx } from '../../core/prisma/prisma.service';
import { scopeWhere } from '../../core/rbac/scope';
import { toUserDto, userInclude, type UserWithRelations } from './users.mapper';

type CreateUser = z.output<typeof createUserSchema>;
type UpdateUser = z.output<typeof updateUserSchema>;
type ListQuery = UserListQuery & { page: number; pageSize: number };

@Injectable()
export class UsersService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly passwords: PasswordService,
    private readonly sessions: SessionService,
    private readonly audit: AuditService,
    private readonly outbox: OutboxService,
  ) {}

  /** Видимость сотрудников: ALL — все, TEAM — свои отделы, OWN — только себя. */
  private visibleWhere(auth: AuthContext): Prisma.UserWhereInput {
    return {
      deletedAt: null,
      ...scopeWhere<Prisma.UserWhereInput>(auth, 'employee.read', {
        own: (userId) => ({ id: userId }),
        team: (teamIds, userId) => ({ OR: [{ teamId: { in: teamIds } }, { id: userId }] }),
      }),
    };
  }

  async list(auth: AuthContext, query: ListQuery): Promise<Paginated<UserDto>> {
    const filters: Prisma.UserWhereInput[] = [this.visibleWhere(auth)];
    if (query.q) {
      filters.push({
        OR: [
          { fullName: { contains: query.q, mode: 'insensitive' } },
          { email: { contains: query.q, mode: 'insensitive' } },
          { phone: { contains: query.q } },
        ],
      });
    }
    if (query.roleCode) filters.push({ role: { code: query.roleCode } });
    if (query.teamId) filters.push({ teamId: query.teamId });
    if (query.status) filters.push({ status: query.status });
    const where: Prisma.UserWhereInput = { AND: filters };

    const [items, total] = await Promise.all([
      this.prisma.user.findMany({
        where,
        include: userInclude,
        orderBy: [{ status: 'asc' }, { fullName: 'asc' }],
        skip: (query.page - 1) * query.pageSize,
        take: query.pageSize,
      }),
      this.prisma.user.count({ where }),
    ]);
    return { items: items.map(toUserDto), total, page: query.page, pageSize: query.pageSize };
  }

  async get(auth: AuthContext, id: string): Promise<UserDto> {
    const user = await this.prisma.user.findFirst({
      where: { AND: [this.visibleWhere(auth), { id }] },
      include: userInclude,
    });
    if (!user) throw notFound('Сотрудник');
    return toUserDto(user);
  }

  private async assertDirections(ids: string[]) {
    if (!ids.length) return;
    const n = await this.prisma.direction.count({ where: { id: { in: ids } } });
    if (n !== new Set(ids).size)
      throw businessRule('Нет такого направления', [
        { path: 'directionIds', message: 'Выберите направление из списка' },
      ]);
  }

  async create(
    auth: AuthContext,
    input: CreateUser,
    meta: RequestMeta,
  ): Promise<CreateUserResponse> {
    this.assertCanAssignRole(auth, input.roleCode);
    if (await this.prisma.user.findUnique({ where: { email: input.email } })) {
      throw conflict('Сотрудник с таким email уже существует');
    }
    const role = await this.prisma.role.findUniqueOrThrow({ where: { code: input.roleCode } });
    if (input.teamId) await this.assertTeamExists(input.teamId);
    await this.assertDirections(input.directionIds);

    const temporaryPassword = input.password ? undefined : this.passwords.generateTemporary();
    const passwordHash = await this.passwords.hash(input.password ?? temporaryPassword!);

    const user = await this.prisma.$transaction(async (tx) => {
      const created = await tx.user.create({
        data: {
          email: input.email,
          fullName: input.fullName,
          phone: input.phone ?? null,
          passwordHash,
          roleId: role.id,
          teamId: input.teamId ?? null,
          locale: input.locale,
          employee: {
            create: {
              position: input.position ?? null,
              specialty: input.roleCode === 'EXECUTOR' ? (input.specialty ?? null) : null,
            },
          },
          directions: { create: input.directionIds.map((directionId) => ({ directionId })) },
        },
        include: userInclude,
      });
      await this.audit.log(tx, {
        actorId: auth.userId,
        action: 'user.create',
        entityType: 'user',
        entityId: created.id,
        changes: {
          email: { old: null, new: created.email },
          role: { old: null, new: input.roleCode },
          teamId: { old: null, new: created.teamId },
        },
        meta,
      });
      await this.outbox.publish(
        tx,
        'user.created',
        { userId: created.id, roleCode: input.roleCode },
        auth.userId,
      );
      return created;
    });

    return { user: toUserDto(user), temporaryPassword };
  }

  async update(
    auth: AuthContext,
    id: string,
    input: UpdateUser,
    meta: RequestMeta,
  ): Promise<UserDto> {
    const before = await this.findManageable(auth, id);
    const newRole = input.roleCode ?? before.role.code;

    if (input.roleCode && input.roleCode !== before.role.code) {
      if (id === auth.userId) throw businessRule('Нельзя изменить собственную роль');
      this.assertCanAssignRole(auth, input.roleCode);
      if (before.role.code === 'CEO') await this.assertNotLastCeo(id);
      if (before.role.code === 'ROP') await this.assertNotTeamHead(id);
    }
    if (input.teamId) await this.assertTeamExists(input.teamId);
    if (input.directionIds) await this.assertDirections(input.directionIds);
    if (input.email && input.email !== before.email) {
      if (await this.prisma.user.findUnique({ where: { email: input.email } })) {
        throw conflict('Сотрудник с таким email уже существует');
      }
    }

    const role =
      input.roleCode && input.roleCode !== before.role.code
        ? await this.prisma.role.findUniqueOrThrow({ where: { code: input.roleCode } })
        : null;

    const specialty =
      newRole === 'EXECUTOR'
        ? input.specialty === undefined
          ? (before.employee?.specialty ?? null)
          : input.specialty
        : null;
    const position =
      input.position === undefined ? (before.employee?.position ?? null) : input.position;

    return this.prisma.$transaction(async (tx) => {
      const updated = await tx.user.update({
        where: { id },
        data: {
          email: input.email,
          fullName: input.fullName,
          phone: input.phone,
          locale: input.locale,
          teamId: input.teamId === undefined ? undefined : input.teamId,
          roleId: role?.id,
          employee: {
            upsert: {
              create: { position, specialty },
              update: { position, specialty },
            },
          },
          // Направления меняют видимость проектов сразу: контекст сессии читается из БД на каждый запрос
          directions: input.directionIds
            ? {
                deleteMany: {},
                create: input.directionIds.map((directionId) => ({ directionId })),
              }
            : undefined,
        },
        include: userInclude,
      });

      const changes = diffFields(
        {
          email: before.email,
          fullName: before.fullName,
          phone: before.phone,
          locale: before.locale,
          teamId: before.teamId,
          role: before.role.code as string,
          position: before.employee?.position ?? null,
          specialty: before.employee?.specialty ?? null,
          directions: before.directions.map((d) => d.direction.name).join(', '),
        },
        {
          email: updated.email,
          fullName: updated.fullName,
          phone: updated.phone,
          locale: updated.locale,
          teamId: updated.teamId,
          role: updated.role.code,
          position: updated.employee?.position ?? null,
          specialty: updated.employee?.specialty ?? null,
          directions: updated.directions.map((d) => d.direction.name).join(', '),
        },
        [
          'email',
          'fullName',
          'phone',
          'locale',
          'teamId',
          'role',
          'position',
          'specialty',
          'directions',
        ],
      );
      if (changes) {
        await this.audit.log(tx, {
          actorId: auth.userId,
          action: 'user.update',
          entityType: 'user',
          entityId: id,
          changes,
          meta,
        });
      }
      if (role) {
        // Права изменились — все сессии пользователя закрываются.
        await this.sessions.revokeAllForUser(id, undefined, tx);
        await this.outbox.publish(
          tx,
          'user.role_changed',
          { userId: id, from: before.role.code, to: role.code },
          auth.userId,
        );
      }
      return toUserDto(updated);
    });
  }

  async setBlocked(
    auth: AuthContext,
    id: string,
    blocked: boolean,
    meta: RequestMeta,
  ): Promise<UserDto> {
    if (id === auth.userId) throw businessRule('Нельзя заблокировать собственную учётную запись');
    const before = await this.findManageable(auth, id);
    if (blocked && before.role.code === 'CEO') await this.assertNotLastCeo(id);
    const status = blocked ? 'BLOCKED' : 'ACTIVE';
    if (before.status === status) return toUserDto(before);

    return this.prisma.$transaction(async (tx) => {
      const updated = await tx.user.update({
        where: { id },
        data: { status, failedLoginCount: 0, lockedUntil: null },
        include: userInclude,
      });
      if (blocked) {
        await this.sessions.revokeAllForUser(id, undefined, tx);
        await this.outbox.publish(tx, 'user.blocked', { userId: id }, auth.userId);
      }
      await this.audit.log(tx, {
        actorId: auth.userId,
        action: blocked ? 'user.block' : 'user.unblock',
        entityType: 'user',
        entityId: id,
        changes: { status: { old: before.status, new: status } },
        meta,
      });
      return toUserDto(updated);
    });
  }

  /** Открытая работа сотрудника: что нужно передать перед удалением. */
  async workload(auth: AuthContext, id: string): Promise<UserWorkloadDto> {
    await this.findManageable(auth, id);
    const open = { in: [...OPEN_TASK_STATUSES] };
    const [leads, deals, clients, projects, tasks, todos, threads] = await Promise.all([
      this.prisma.lead.count({ where: { ownerId: id, status: 'OPEN', deletedAt: null } }),
      this.prisma.deal.count({ where: { ownerId: id, status: 'OPEN', deletedAt: null } }),
      this.prisma.client.count({ where: { ownerId: id, deletedAt: null } }),
      this.prisma.project.count({
        where: {
          deletedAt: null,
          status: { in: [...ACTIVE_PROJECT_STATUSES] },
          OR: [{ managerId: id }, { ropId: id }],
        },
      }),
      this.prisma.task.count({ where: { assigneeId: id, deletedAt: null, status: open } }),
      this.prisma.todo.count({ where: { ownerId: id, status: 'OPEN', deletedAt: null } }),
      this.prisma.socialThread.count({ where: { ownerId: id } }),
    ]);
    const total = leads + deals + clients + projects + tasks + todos + threads;
    return { leads, deals, clients, projects, tasks, todos, threads, total };
  }

  /**
   * Удаление сотрудника: открытая работа передаётся другому сотруднику, история (оплаты,
   * комиссии, зарплата, аудит) остаётся с его именем. Учётная запись скрывается и не может войти;
   * email освобождается — его можно выдать новому сотруднику.
   */
  async remove(auth: AuthContext, id: string, transferToId: string | null, meta: RequestMeta) {
    if (id === auth.userId) throw businessRule('Нельзя удалить собственную учётную запись');
    const before = await this.findManageable(auth, id);
    if (before.role.code === 'CEO') await this.assertNotLastCeo(id);
    await this.assertNotTeamHead(id);
    const work = await this.workload(auth, id);
    let target: { id: string; teamId: string | null; fullName: string } | null = null;
    if (transferToId) {
      if (transferToId === id) throw businessRule('Выберите другого сотрудника');
      target = await this.prisma.user.findFirst({
        where: { id: transferToId, status: 'ACTIVE', deletedAt: null },
        select: { id: true, teamId: true, fullName: true },
      });
      if (!target)
        throw businessRule('Сотрудник для передачи не найден', [
          { path: 'transferToId', message: 'Выберите активного сотрудника' },
        ]);
    } else if (work.total > 0) {
      throw businessRule('У сотрудника есть открытая работа — выберите, кому её передать', [
        { path: 'transferToId', message: 'Выберите, кому передать работу' },
      ]);
    }

    await this.prisma.$transaction(async (tx) => {
      if (target) {
        const to = target;
        await tx.lead.updateMany({
          where: { ownerId: id, status: 'OPEN', deletedAt: null },
          data: { ownerId: to.id, teamId: to.teamId },
        });
        await tx.deal.updateMany({
          where: { ownerId: id, status: 'OPEN', deletedAt: null },
          data: { ownerId: to.id, teamId: to.teamId },
        });
        await tx.client.updateMany({
          where: { ownerId: id, deletedAt: null },
          data: { ownerId: to.id, teamId: to.teamId },
        });
        const active = { deletedAt: null, status: { in: [...ACTIVE_PROJECT_STATUSES] } };
        await tx.project.updateMany({
          where: { ...active, managerId: id },
          data: { managerId: to.id },
        });
        await tx.project.updateMany({ where: { ...active, ropId: id }, data: { ropId: to.id } });
        // Задачи: новый исполнитель становится участником команды проекта
        const tasks = await tx.task.findMany({
          where: { assigneeId: id, deletedAt: null, status: { in: [...OPEN_TASK_STATUSES] } },
          select: { id: true, projectId: true },
        });
        for (const projectId of new Set(tasks.map((t) => t.projectId))) {
          await tx.projectMember.upsert({
            where: { projectId_userId: { projectId, userId: to.id } },
            update: { status: 'ACTIVE' },
            create: { projectId, userId: to.id, assignedById: auth.userId },
          });
        }
        await tx.task.updateMany({
          where: { id: { in: tasks.map((t) => t.id) } },
          data: { assigneeId: to.id },
        });
        await tx.todo.updateMany({
          where: { ownerId: id, status: 'OPEN', deletedAt: null },
          data: { ownerId: to.id },
        });
        await tx.socialThread.updateMany({
          where: { ownerId: id },
          data: { ownerId: to.id, teamId: to.teamId },
        });
      }
      await tx.projectMember.updateMany({
        where: { userId: id, status: 'ACTIVE' },
        data: { status: 'REMOVED' },
      });
      await tx.recurringTodo.updateMany({
        where: { ownerId: id, deletedAt: null },
        data: { deletedAt: new Date(), isActive: false },
      });
      await tx.leadForm.updateMany({ where: { ownerId: id }, data: { ownerId: null } });
      await tx.userDirection.deleteMany({ where: { userId: id } });
      await tx.user.update({
        where: { id },
        data: {
          status: 'BLOCKED',
          deletedAt: new Date(),
          // email свободен для нового сотрудника; исходный — в аудите
          email: `deleted.${id.slice(0, 8)}.${before.email}`,
          telegramChatId: null,
        },
      });
      await this.sessions.revokeAllForUser(id, undefined, tx);
      await this.outbox.publish(tx, 'user.blocked', { userId: id }, auth.userId);
      await this.audit.log(tx, {
        actorId: auth.userId,
        action: 'user.delete',
        entityType: 'user',
        entityId: id,
        changes: {
          email: { old: before.email, new: null },
          fullName: { old: before.fullName, new: null },
          transferredTo: { old: null, new: target ? target.fullName : null },
          workload: { old: work, new: null },
        },
        meta,
      });
    });
  }

  async resetPassword(auth: AuthContext, id: string, meta: RequestMeta) {
    if (id === auth.userId) throw businessRule('Для смены своего пароля используйте профиль');
    await this.findManageable(auth, id);
    const temporaryPassword = this.passwords.generateTemporary();
    const passwordHash = await this.passwords.hash(temporaryPassword);
    await this.prisma.$transaction(async (tx) => {
      await tx.user.update({
        where: { id },
        data: { passwordHash, failedLoginCount: 0, lockedUntil: null },
      });
      await this.sessions.revokeAllForUser(id, undefined, tx);
      await this.audit.log(tx, {
        actorId: auth.userId,
        action: 'user.reset_password',
        entityType: 'user',
        entityId: id,
        meta,
      });
    });
    return { temporaryPassword };
  }

  // ─── правила ───

  private async findManageable(auth: AuthContext, id: string): Promise<UserWithRelations> {
    const user = await this.prisma.user.findFirst({
      where: { id, deletedAt: null },
      include: userInclude,
    });
    if (!user) throw notFound('Сотрудник');
    if (user.role.code === 'CEO' && auth.roleCode !== 'CEO') {
      throw forbidden('Только CEO может изменять учётные записи CEO');
    }
    return user;
  }

  private assertCanAssignRole(auth: AuthContext, role: RoleCode) {
    if (role === 'CEO' && auth.roleCode !== 'CEO') {
      throw forbidden('Только CEO может назначать роль CEO');
    }
  }

  private async assertTeamExists(teamId: string, tx: Tx = this.prisma) {
    const team = await tx.team.findFirst({ where: { id: teamId, deletedAt: null } });
    if (!team)
      throw businessRule('Отдел не найден', [{ path: 'teamId', message: 'Отдел не найден' }]);
  }

  private async assertNotLastCeo(userId: string) {
    const others = await this.prisma.user.count({
      where: { id: { not: userId }, status: 'ACTIVE', deletedAt: null, role: { code: 'CEO' } },
    });
    if (others === 0) throw businessRule('В системе должен остаться хотя бы один активный CEO');
  }

  private async assertNotTeamHead(userId: string) {
    const headed = await this.prisma.team.count({ where: { headId: userId, deletedAt: null } });
    if (headed > 0) {
      throw businessRule('Сотрудник руководит отделом. Сначала назначьте отделу другого РОП');
    }
  }
}

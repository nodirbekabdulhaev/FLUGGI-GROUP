import { Injectable } from '@nestjs/common';
import {
  ACTIVE_PROJECT_STATUSES,
  OPEN_TASK_STATUSES,
  formatNumber,
  type ActivityDto,
  type addMemberSchema,
  type Paginated,
  type ProjectDetailDto,
  type ProjectDto,
  type projectListQuerySchema,
  type ProjectMemberDto,
  type projectStatusSchema,
  type TaskCountsDto,
  type updateMemberSchema,
  type updateProjectSchema,
} from '@fluggi/contracts';
import { companyDate, projectOverdueDays } from '@fluggi/domain';
import type { Prisma } from '@fluggi/db';
import type { z } from 'zod';
import type { AuthContext, RequestMeta } from '../../core/auth/auth-context';
import { AuditService, diffFields } from '../../core/audit/audit.service';
import { businessRule, forbidden, notFound } from '../../core/http/app.exception';
import { dateOnly, decReq, iso, parseDate } from '../../core/http/serialize';
import { OutboxService } from '../../core/outbox/outbox.service';
import { PrismaService } from '../../core/prisma/prisma.service';
import { ActivityService } from '../crm/activity.service';
import { ProjectAccessService } from './project-access.service';
import { TemplatesService } from './templates.service';

const include = {
  client: { select: { id: true, name: true } },
  deal: { select: { id: true, number: true, title: true } },
  rop: { select: { id: true, fullName: true } },
  manager: { select: { id: true, fullName: true } },
  template: { select: { id: true, name: true } },
  direction: { select: { id: true, name: true } },
} satisfies Prisma.ProjectInclude;

type Row = Prisma.ProjectGetPayload<{ include: typeof include }>;

const memberInclude = {
  user: { select: { id: true, fullName: true } },
} satisfies Prisma.ProjectMemberInclude;

const EMPTY_COUNTS: TaskCountsDto = { total: 0, done: 0, overdue: 0 };

/** Открытые задачи с прошедшим дедлайном (ТЗ §24). */
export const overdueTaskWhere = (now: Date): Prisma.TaskWhereInput => ({
  deletedAt: null,
  status: { in: [...OPEN_TASK_STATUSES] },
  deadline: { lt: now },
});

@Injectable()
export class ProjectsService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly access: ProjectAccessService,
    private readonly templates: TemplatesService,
    private readonly activity: ActivityService,
    private readonly audit: AuditService,
    private readonly outbox: OutboxService,
  ) {}

  private toDto(auth: AuthContext, p: Row, counts: TaskCountsDto, now: Date): ProjectDto {
    const seesMoney = Boolean(auth.permissions['payment.read'] || auth.permissions['finance.read']);
    const deadline = dateOnly(p.deadline);
    return {
      id: p.id,
      number: formatNumber('P', p.number),
      name: p.name,
      client: p.client,
      deal: auth.permissions['deal.read']
        ? { id: p.deal.id, name: p.deal.title, number: formatNumber('D', p.deal.number) }
        : null,
      rop: { id: p.rop.id, name: p.rop.fullName },
      manager: { id: p.manager.id, name: p.manager.fullName },
      status: p.status,
      priority: p.priority,
      price: seesMoney ? decReq(p.price) : null,
      currency: p.currency,
      startDate: dateOnly(p.startDate),
      deadline,
      overdueDays: projectOverdueDays(deadline, ACTIVE_PROJECT_STATUSES.includes(p.status), now),
      description: p.description,
      template: p.template,
      direction: p.direction,
      tasks: counts,
      completedAt: iso(p.completedAt),
      createdAt: p.createdAt.toISOString(),
    };
  }

  /** Счётчики задач по проектам: всего (без отменённых), выполнено, просрочено. */
  private async taskCounts(projectIds: string[], now: Date) {
    const map = new Map<string, TaskCountsDto>();
    if (projectIds.length === 0) return map;
    const [byStatus, overdue] = await Promise.all([
      this.prisma.task.groupBy({
        by: ['projectId', 'status'],
        where: { projectId: { in: projectIds }, deletedAt: null, status: { not: 'CANCELLED' } },
        _count: { _all: true },
      }),
      this.prisma.task.groupBy({
        by: ['projectId'],
        where: { projectId: { in: projectIds }, ...overdueTaskWhere(now) },
        _count: { _all: true },
      }),
    ]);
    for (const g of byStatus) {
      const c = map.get(g.projectId) ?? { ...EMPTY_COUNTS };
      c.total += g._count._all;
      if (g.status === 'DONE') c.done += g._count._all;
      map.set(g.projectId, c);
    }
    for (const g of overdue) {
      const c = map.get(g.projectId) ?? { ...EMPTY_COUNTS };
      c.overdue = g._count._all;
      map.set(g.projectId, c);
    }
    return map;
  }

  async list(
    auth: AuthContext,
    q: z.output<typeof projectListQuerySchema>,
  ): Promise<Paginated<ProjectDto>> {
    const now = new Date();
    const and: Prisma.ProjectWhereInput[] = [this.access.projectWhere(auth)];
    const active = { status: { in: [...ACTIVE_PROJECT_STATUSES] } };
    if (q.view === 'active') and.push(active);
    if (q.view === 'completed') and.push({ status: 'COMPLETED' });
    if (q.view === 'overdue')
      and.push(active, {
        OR: [
          { deadline: { lt: parseDate(companyDate(now))! } },
          { tasks: { some: overdueTaskWhere(now) } },
        ],
      });
    if (q.status) and.push({ status: q.status });
    if (q.ropId) and.push({ ropId: q.ropId });
    if (q.clientId) and.push({ clientId: q.clientId });
    if (q.dealId) and.push({ dealId: q.dealId });
    if (q.directionId) and.push({ directionId: q.directionId });
    if (q.q)
      and.push({
        OR: [{ name: { contains: q.q } }, { client: { name: { contains: q.q } } }],
      });
    const where = { AND: and };
    const [rows, total] = await Promise.all([
      this.prisma.project.findMany({
        where,
        include,
        orderBy:
          q.view === 'completed'
            ? [{ completedAt: 'desc' }]
            : [{ deadline: { sort: 'asc', nulls: 'last' } }, { createdAt: 'desc' }],
        skip: (q.page - 1) * q.pageSize,
        take: q.pageSize,
      }),
      this.prisma.project.count({ where }),
    ]);
    const counts = await this.taskCounts(
      rows.map((r) => r.id),
      now,
    );
    return {
      items: rows.map((r) => this.toDto(auth, r, counts.get(r.id) ?? EMPTY_COUNTS, now)),
      total,
      page: q.page,
      pageSize: q.pageSize,
    };
  }

  async get(auth: AuthContext, id: string): Promise<ProjectDetailDto> {
    await this.access.project(auth, id);
    const now = new Date();
    const p = await this.prisma.project.findUniqueOrThrow({ where: { id }, include });
    const [counts, members, canUpdate, canAssign, canCreateTasks] = await Promise.all([
      this.taskCounts([id], now),
      this.members(id, now),
      this.access.can(auth, id, 'project.update'),
      this.access.can(auth, id, 'project.assign'),
      this.access.can(auth, id, 'task.create'),
    ]);
    return {
      ...this.toDto(auth, p, counts.get(id) ?? EMPTY_COUNTS, now),
      members,
      can: { canUpdate, canAssign, canCreateTasks },
    };
  }

  private async members(projectId: string, now: Date): Promise<ProjectMemberDto[]> {
    const rows = await this.prisma.projectMember.findMany({
      where: { projectId },
      include: memberInclude,
      orderBy: [{ status: 'asc' }, { assignedAt: 'asc' }],
    });
    const userIds = rows.map((r) => r.userId);
    const [byStatus, overdue] = await Promise.all([
      this.prisma.task.groupBy({
        by: ['assigneeId', 'status'],
        where: {
          projectId,
          assigneeId: { in: userIds },
          deletedAt: null,
          status: { not: 'CANCELLED' },
        },
        _count: { _all: true },
      }),
      this.prisma.task.groupBy({
        by: ['assigneeId'],
        where: { projectId, assigneeId: { in: userIds }, ...overdueTaskWhere(now) },
        _count: { _all: true },
      }),
    ]);
    return rows.map((m) => {
      const mine = byStatus.filter((g) => g.assigneeId === m.userId);
      return {
        id: m.id,
        user: { id: m.user.id, name: m.user.fullName },
        role: m.role,
        workloadPct: m.workloadPct,
        deadline: dateOnly(m.deadline),
        status: m.status,
        assignedAt: m.assignedAt.toISOString(),
        tasks: {
          total: mine.reduce((s, g) => s + g._count._all, 0),
          done: mine.filter((g) => g.status === 'DONE').reduce((s, g) => s + g._count._all, 0),
          overdue: overdue.find((g) => g.assigneeId === m.userId)?._count._all ?? 0,
        },
      };
    });
  }

  async update(
    auth: AuthContext,
    id: string,
    input: z.output<typeof updateProjectSchema>,
    meta: RequestMeta,
  ): Promise<ProjectDetailDto> {
    const before = await this.access.project(auth, id, 'project.update');
    // Сменить РОП проекта может только тот, кто видит все проекты (CEO).
    if (input.ropId && input.ropId !== before.ropId) {
      if (auth.permissions['project.update'] !== 'ALL') throw forbidden();
      const rop = await this.prisma.user.findFirst({
        where: {
          id: input.ropId,
          status: 'ACTIVE',
          deletedAt: null,
          role: { code: { in: ['ROP', 'CEO'] } },
        },
      });
      if (!rop) throw businessRule('РОП проекта — сотрудник с ролью РОП или CEO');
    }
    // Направление определяет, кто видит проект, — меняет только CEO (project.update на всю компанию)
    if (input.directionId !== undefined && input.directionId !== before.directionId) {
      if (auth.permissions['project.update'] !== 'ALL') throw forbidden();
      if (input.directionId) {
        const d = await this.prisma.direction.findFirst({
          where: { id: input.directionId, isActive: true },
        });
        if (!d) throw businessRule('Нет такого направления');
      }
    }
    const startDate = input.startDate !== undefined ? parseDate(input.startDate) : undefined;
    const deadline = input.deadline !== undefined ? parseDate(input.deadline) : undefined;
    const start = startDate === undefined ? before.startDate : startDate;
    const end = deadline === undefined ? before.deadline : deadline;
    if (start && end && start > end) throw businessRule('Дедлайн раньше даты начала');

    const data = {
      name: input.name,
      description: input.description,
      priority: input.priority,
      ropId: input.ropId,
      directionId: input.directionId,
      startDate,
      deadline,
    };
    await this.prisma.$transaction(async (tx) => {
      await tx.project.update({ where: { id }, data });
      const changes = diffFields(before, data, [
        'name',
        'description',
        'priority',
        'ropId',
        'directionId',
        'startDate',
        'deadline',
      ]);
      if (changes) {
        await this.audit.log(tx, {
          actorId: auth.userId,
          action: 'project.update',
          entityType: 'project',
          entityId: id,
          changes,
          meta,
        });
        await this.activity.log(tx, {
          type: 'project.updated',
          actorId: auth.userId,
          projectId: id,
          payload: { fields: Object.keys(changes) },
        });
      }
    });
    return this.get(auth, id);
  }

  /**
   * Статус проекта (ТЗ §20). Завершить можно, только когда нет открытых задач;
   * отмена — с комментарием. Закрытый проект можно вернуть в работу.
   */
  async setStatus(
    auth: AuthContext,
    id: string,
    input: z.output<typeof projectStatusSchema>,
    meta: RequestMeta,
  ): Promise<ProjectDetailDto> {
    const p = await this.access.project(auth, id, 'project.update');
    if (p.status === input.status) return this.get(auth, id);
    if (input.status === 'COMPLETED') {
      const open = await this.prisma.task.count({
        where: { projectId: id, deletedAt: null, status: { in: [...OPEN_TASK_STATUSES] } },
      });
      if (open > 0)
        throw businessRule(
          `Нельзя завершить проект: открытых задач — ${open}. Завершите или отмените их`,
        );
    }
    if (input.status === 'CANCELLED' && !input.comment)
      throw businessRule('Укажите причину отмены проекта');

    await this.prisma.$transaction(async (tx) => {
      await tx.project.update({
        where: { id },
        data: {
          status: input.status,
          completedAt: input.status === 'COMPLETED' ? new Date() : null,
        },
      });
      if (input.status === 'COMPLETED')
        await tx.projectMember.updateMany({
          where: { projectId: id, status: 'ACTIVE' },
          data: { status: 'DONE' },
        });
      await this.activity.log(tx, {
        type: 'project.status_changed',
        actorId: auth.userId,
        projectId: id,
        payload: { from: p.status, to: input.status, comment: input.comment ?? null },
      });
      await this.audit.log(tx, {
        actorId: auth.userId,
        action: 'project.status',
        entityType: 'project',
        entityId: id,
        changes: { status: { old: p.status, new: input.status } },
        meta,
      });
      await this.outbox.publish(
        tx,
        'project.status_changed',
        { projectId: id, from: p.status, to: input.status },
        auth.userId,
      );
    });
    return this.get(auth, id);
  }

  async applyTemplate(
    auth: AuthContext,
    id: string,
    templateId: string,
    meta: RequestMeta,
  ): Promise<ProjectDetailDto> {
    const p = await this.access.project(auth, id, 'project.update');
    if (!ACTIVE_PROJECT_STATUSES.includes(p.status)) throw businessRule('Проект закрыт');
    await this.prisma.$transaction(async (tx) => {
      const { created } = await this.templates.apply(tx, p, templateId, auth.userId);
      await this.audit.log(tx, {
        actorId: auth.userId,
        action: 'project.apply_template',
        entityType: 'project',
        entityId: id,
        changes: {
          templateId: { old: p.templateId, new: templateId },
          tasks: { old: null, new: created },
        },
        meta,
      });
    });
    return this.get(auth, id);
  }

  // ─────────────────────────── Команда (ТЗ §21) ───────────────────────────

  async addMember(
    auth: AuthContext,
    id: string,
    input: z.output<typeof addMemberSchema>,
    meta: RequestMeta,
  ): Promise<ProjectDetailDto> {
    const p = await this.access.project(auth, id, 'project.assign');
    if (!ACTIVE_PROJECT_STATUSES.includes(p.status)) throw businessRule('Проект закрыт');
    const user = await this.prisma.user.findFirst({
      where: { id: input.userId, status: 'ACTIVE', deletedAt: null },
      include: { role: true, employee: true },
    });
    if (!user) throw businessRule('Сотрудник не найден или заблокирован');
    if (!['EXECUTOR', 'MANAGER', 'ROP'].includes(user.role.code))
      throw businessRule('В команду проекта назначаются исполнители, менеджеры и РОП');
    const role = input.role ?? user.employee?.specialty ?? null;

    await this.prisma.$transaction(async (tx) => {
      const existing = await tx.projectMember.findUnique({
        where: { projectId_userId: { projectId: id, userId: user.id } },
      });
      if (existing && existing.status === 'ACTIVE')
        throw businessRule('Сотрудник уже в команде проекта');
      const data = {
        role,
        workloadPct: input.workloadPct ?? null,
        deadline: parseDate(input.deadline) ?? null,
        status: 'ACTIVE' as const,
        assignedById: auth.userId,
        assignedAt: new Date(),
      };
      if (existing) await tx.projectMember.update({ where: { id: existing.id }, data });
      else await tx.projectMember.create({ data: { projectId: id, userId: user.id, ...data } });

      // Задачи из шаблона, которые ждали исполнителя этой роли у РОП, переходят к нему.
      const assigned = role
        ? await tx.task.updateMany({
            where: {
              projectId: id,
              assigneeId: p.ropId,
              templateRole: role,
              deletedAt: null,
              status: 'TODO',
            },
            data: { assigneeId: user.id },
          })
        : { count: 0 };
      // Первый исполнитель — проект переходит из «Новый» в «Планирование».
      if (p.status === 'NEW')
        await tx.project.update({ where: { id }, data: { status: 'PLANNING' } });

      await this.activity.log(tx, {
        type: 'project.member_added',
        actorId: auth.userId,
        projectId: id,
        payload: { user: user.fullName, role, tasksAssigned: assigned.count },
      });
      await this.audit.log(tx, {
        actorId: auth.userId,
        action: 'project.member_add',
        entityType: 'project',
        entityId: id,
        changes: { member: { old: null, new: user.fullName }, role: { old: null, new: role } },
        meta,
      });
      await this.outbox.publish(
        tx,
        'project.member_added',
        { projectId: id, userId: user.id },
        auth.userId,
      );
    });
    return this.get(auth, id);
  }

  async updateMember(
    auth: AuthContext,
    id: string,
    memberId: string,
    input: z.output<typeof updateMemberSchema>,
    meta: RequestMeta,
  ): Promise<ProjectDetailDto> {
    const project = await this.access.project(auth, id, 'project.assign');
    const m = await this.prisma.projectMember.findFirst({
      where: { id: memberId, projectId: id },
      include: memberInclude,
    });
    if (!m) throw notFound('Участник');
    const data = {
      role: input.role,
      workloadPct: input.workloadPct,
      deadline: input.deadline !== undefined ? parseDate(input.deadline) : undefined,
      status: input.status,
    };
    await this.prisma.$transaction(async (tx) => {
      await tx.projectMember.update({ where: { id: memberId }, data });
      // Исключённый участник: его открытые задачи переходят к РОП проекта (ТЗ §77, Rule 5).
      let unassigned = 0;
      if (input.status === 'REMOVED' && m.status !== 'REMOVED') {
        const r = await tx.task.updateMany({
          where: {
            projectId: id,
            assigneeId: m.userId,
            deletedAt: null,
            status: { in: [...OPEN_TASK_STATUSES] },
          },
          data: { assigneeId: project.ropId },
        });
        unassigned = r.count;
        await this.activity.log(tx, {
          type: 'project.member_removed',
          actorId: auth.userId,
          projectId: id,
          payload: { user: m.user.fullName, tasksToRop: unassigned },
        });
      }
      const changes = diffFields(m, data, ['role', 'workloadPct', 'deadline', 'status']);
      if (changes)
        await this.audit.log(tx, {
          actorId: auth.userId,
          action: 'project.member_update',
          entityType: 'project',
          entityId: id,
          changes: { member: { old: m.user.fullName, new: m.user.fullName }, ...changes },
          meta,
        });
    });
    return this.get(auth, id);
  }

  /** Таймлайн проекта: действия по проекту и его задачам. */
  async timeline(auth: AuthContext, id: string): Promise<ActivityDto[]> {
    await this.access.project(auth, id);
    const rows = await this.prisma.activity.findMany({
      where: { projectId: id },
      include: { actor: { select: { id: true, fullName: true } } },
      orderBy: { createdAt: 'desc' },
      take: 200,
    });
    return rows.map((a) => ({
      id: a.id,
      type: a.type,
      actor: a.actor ? { id: a.actor.id, name: a.actor.fullName } : null,
      payload: a.payload as Record<string, unknown> | null,
      createdAt: a.createdAt.toISOString(),
    }));
  }
}

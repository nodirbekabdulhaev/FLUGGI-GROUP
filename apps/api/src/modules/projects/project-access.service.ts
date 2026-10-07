import { Injectable } from '@nestjs/common';
import type { PermissionCode } from '@fluggi/contracts';
import type { Prisma, Project } from '@fluggi/db';
import type { AuthContext } from '../../core/auth/auth-context';
import { notFound } from '../../core/http/app.exception';
import { PrismaService, type Tx } from '../../core/prisma/prisma.service';
import { scopeOf } from '../../core/rbac/scope';

type ProjectCode = Extract<
  PermissionCode,
  `project.${string}` | `task.${string}` | 'finance.read' | `expense.${string}`
>;

/**
 * Видимость проектов и задач (docs/PERMISSIONS.md, ТЗ §65).
 *  Проект: OWN — я менеджер/РОП проекта или участник команды; TEAM — проекты моих отделов.
 *  Задача: OWN — я ответственный или автор, либо менеджер проекта; TEAM — задачи проектов отдела.
 * Участие в команде даёт только чтение: менять проект можно по праву project.update/assign.
 * Направления: у сотрудника с направлениями (проект-менеджер) область TEAM включает
 * все проекты этих направлений (например, вся «Медиа»), а проекты других направлений не видны.
 */
@Injectable()
export class ProjectAccessService {
  constructor(private readonly prisma: PrismaService) {}

  private teamIds(auth: AuthContext) {
    return [...new Set([...auth.headedTeamIds, ...(auth.teamId ? [auth.teamId] : [])])];
  }

  /** Проекты, на которые у пользователя есть право code. */
  projectWhere(auth: AuthContext, code: ProjectCode = 'project.read'): Prisma.ProjectWhereInput {
    const scope = scopeOf(auth, code);
    const me = auth.userId;
    const lead: Prisma.ProjectWhereInput[] = [{ managerId: me }, { ropId: me }];
    // Участник команды видит проект (но не управляет им).
    const member: Prisma.ProjectWhereInput[] =
      code === 'project.read' || code === 'task.read'
        ? [{ members: { some: { userId: me, status: { not: 'REMOVED' } } } }]
        : [];
    if (scope === 'ALL') return { deletedAt: null };
    if (scope === 'TEAM')
      return {
        deletedAt: null,
        OR: [
          { teamId: { in: this.teamIds(auth) } },
          ...this.directionScope(auth),
          ...lead,
          ...member,
        ],
      };
    return { deletedAt: null, OR: [...lead, ...member] };
  }

  /** Проекты направлений сотрудника (пусто — у сотрудника нет направлений). */
  private directionScope(auth: AuthContext): Prisma.ProjectWhereInput[] {
    return auth.directionIds.length ? [{ directionId: { in: auth.directionIds } }] : [];
  }

  /** Задачи, на которые у пользователя есть право code. */
  taskWhere(
    auth: AuthContext,
    code: 'task.read' | 'task.update' = 'task.read',
  ): Prisma.TaskWhereInput {
    const scope = scopeOf(auth, code);
    const me = auth.userId;
    const base: Prisma.TaskWhereInput = { deletedAt: null, project: { deletedAt: null } };
    if (scope === 'ALL') return base;
    const mine: Prisma.TaskWhereInput[] = [
      { assigneeId: me },
      { creatorId: me },
      { project: { OR: [{ managerId: me }, { ropId: me }] } },
    ];
    if (scope === 'TEAM')
      return {
        ...base,
        OR: [
          { project: { teamId: { in: this.teamIds(auth) } } },
          ...this.directionScope(auth).map((project) => ({ project })),
          ...mine,
        ],
      };
    return { ...base, OR: mine };
  }

  async project(
    auth: AuthContext,
    id: string,
    code: ProjectCode = 'project.read',
    tx: Tx = this.prisma,
  ): Promise<Project> {
    const p = await tx.project.findFirst({
      where: { AND: [this.projectWhere(auth, code), { id }] },
    });
    if (!p) throw notFound('Проект');
    return p;
  }

  /** Есть ли у пользователя право code на этот проект (без исключения). */
  async can(auth: AuthContext, projectId: string, code: ProjectCode): Promise<boolean> {
    if (!auth.permissions[code]) return false;
    const n = await this.prisma.project.count({
      where: { AND: [this.projectWhere(auth, code), { id: projectId }] },
    });
    return n > 0;
  }

  async task(auth: AuthContext, id: string, code: 'task.read' | 'task.update' = 'task.read') {
    const t = await this.prisma.task.findFirst({
      where: { AND: [this.taskWhere(auth, code), { id }] },
      include: { project: true },
    });
    if (!t) throw notFound('Задача');
    return t;
  }
}

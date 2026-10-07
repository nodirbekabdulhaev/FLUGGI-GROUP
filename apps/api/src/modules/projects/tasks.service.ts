import { Injectable } from '@nestjs/common';
import {
  ACTIVE_PROJECT_STATUSES,
  OPEN_TASK_STATUSES,
  formatNumber,
  type createTaskSchema,
  type moveTaskSchema,
  type Paginated,
  type TaskCommentDto,
  type TaskDetailDto,
  type TaskDto,
  type taskListQuerySchema,
  type TaskStatus,
  type updateTaskSchema,
} from '@fluggi/contracts';
import {
  companyDate,
  companyDayStart,
  isRework,
  sortBetween,
  taskOverdueDays,
} from '@fluggi/domain';
import type { Prisma, Project, Task } from '@fluggi/db';
import type { z } from 'zod';
import type { AuthContext, RequestMeta } from '../../core/auth/auth-context';
import { AuditService, diffFields } from '../../core/audit/audit.service';
import { businessRule, forbidden } from '../../core/http/app.exception';
import { dateOnly, iso, parseDate } from '../../core/http/serialize';
import { OutboxService } from '../../core/outbox/outbox.service';
import { PrismaService, type Tx } from '../../core/prisma/prisma.service';
import { ActivityService } from '../crm/activity.service';
import { ProjectAccessService } from './project-access.service';
import { overdueTaskWhere } from './projects.service';

const include = {
  project: { select: { id: true, number: true, name: true, ropId: true, managerId: true } },
  assignee: { select: { id: true, fullName: true } },
  creator: { select: { id: true, fullName: true } },
  _count: { select: { comments: { where: { deletedAt: null } } } },
} satisfies Prisma.TaskInclude;

type Row = Prisma.TaskGetPayload<{ include: typeof include }>;

const task = (n: number) => formatNumber('T', n);

@Injectable()
export class TasksService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly access: ProjectAccessService,
    private readonly activity: ActivityService,
    private readonly audit: AuditService,
    private readonly outbox: OutboxService,
  ) {}

  /** Проекты, где пользователь управляет задачами (право task.create над проектом). */
  private async managedProjectIds(auth: AuthContext, projectIds: string[]) {
    if (!auth.permissions['task.create'] || projectIds.length === 0) return new Set<string>();
    const rows = await this.prisma.project.findMany({
      where: { AND: [this.access.projectWhere(auth, 'task.create'), { id: { in: projectIds } }] },
      select: { id: true },
    });
    return new Set(rows.map((r) => r.id));
  }

  private toDto(t: Row, canEdit: boolean, now: Date): TaskDto {
    return {
      id: t.id,
      number: task(t.number),
      project: {
        id: t.project.id,
        name: t.project.name,
        number: formatNumber('P', t.project.number),
      },
      title: t.title,
      description: t.description,
      assignee: { id: t.assignee.id, name: t.assignee.fullName },
      waitingForRole:
        Boolean(t.templateRole) && t.assigneeId === t.project.ropId && t.status === 'TODO',
      creator: { id: t.creator.id, name: t.creator.fullName },
      templateRole: t.templateRole,
      priority: t.priority,
      status: t.status,
      startDate: dateOnly(t.startDate),
      deadline: iso(t.deadline),
      overdueDays: taskOverdueDays(t.deadline, OPEN_TASK_STATUSES.includes(t.status), now),
      progressPct: t.progressPct,
      sortOrder: t.sortOrder,
      reworkCount: t.reworkCount,
      startedAt: iso(t.startedAt),
      completedAt: iso(t.completedAt),
      commentsCount: t._count.comments,
      createdAt: t.createdAt.toISOString(),
      canEdit,
    };
  }

  private async toDtos(auth: AuthContext, rows: Row[]): Promise<TaskDto[]> {
    const now = new Date();
    const managed = await this.managedProjectIds(auth, [...new Set(rows.map((r) => r.projectId))]);
    return rows.map((r) =>
      this.toDto(r, managed.has(r.projectId) || r.creatorId === auth.userId, now),
    );
  }

  async list(
    auth: AuthContext,
    q: z.output<typeof taskListQuerySchema>,
  ): Promise<Paginated<TaskDto>> {
    const now = new Date();
    const and: Prisma.TaskWhereInput[] = [this.access.taskWhere(auth)];
    if (q.projectId) and.push({ projectId: q.projectId });
    if (q.assigneeId) and.push({ assigneeId: q.assigneeId });
    if (q.mine) and.push({ assigneeId: auth.userId });
    if (q.status) and.push({ status: q.status });
    if (q.q) and.push({ title: { contains: q.q, mode: 'insensitive' } });
    switch (q.view) {
      case 'today': {
        // Сегодня: дедлайн до конца дня (включая просроченные) или задача начинается сегодня.
        const today = companyDate(now);
        const tomorrow = new Date(companyDayStart(today).getTime() + 86_400_000);
        and.push({
          status: { in: [...OPEN_TASK_STATUSES] },
          OR: [{ deadline: { lt: tomorrow } }, { startDate: parseDate(today)! }],
        });
        break;
      }
      case 'overdue':
        and.push(overdueTaskWhere(now));
        break;
      case 'in_progress':
        and.push({ status: 'IN_PROGRESS' });
        break;
      case 'review':
        and.push({ status: 'REVIEW' });
        break;
      case 'done':
        and.push({ status: 'DONE' });
        break;
      default:
        if (!q.status && !q.projectId) and.push({ status: { not: 'CANCELLED' } });
    }
    const where = { AND: and };
    const [rows, total] = await Promise.all([
      this.prisma.task.findMany({
        where,
        include,
        orderBy: q.projectId
          ? [{ sortOrder: 'asc' }]
          : q.view === 'done'
            ? [{ completedAt: 'desc' }]
            : [{ deadline: { sort: 'asc', nulls: 'last' } }, { createdAt: 'asc' }],
        skip: (q.page - 1) * q.pageSize,
        take: q.pageSize,
      }),
      this.prisma.task.count({ where }),
    ]);
    return { items: await this.toDtos(auth, rows), total, page: q.page, pageSize: q.pageSize };
  }

  async get(auth: AuthContext, id: string): Promise<TaskDetailDto> {
    await this.access.task(auth, id);
    const [row, comments, history] = await Promise.all([
      this.prisma.task.findUniqueOrThrow({ where: { id }, include }),
      this.prisma.taskComment.findMany({
        where: { taskId: id, deletedAt: null },
        include: { author: { select: { id: true, fullName: true } } },
        orderBy: { createdAt: 'asc' },
      }),
      this.prisma.taskStatusHistory.findMany({
        where: { taskId: id },
        include: { changedBy: { select: { id: true, fullName: true } } },
        orderBy: { createdAt: 'desc' },
      }),
    ]);
    const [dto] = await this.toDtos(auth, [row]);
    return {
      ...dto!,
      comments: comments.map((c): TaskCommentDto => ({
        id: c.id,
        author: { id: c.author.id, name: c.author.fullName },
        body: c.body,
        createdAt: c.createdAt.toISOString(),
      })),
      history: history.map((h) => ({
        id: h.id,
        from: h.fromStatus,
        to: h.toStatus,
        changedBy: { id: h.changedBy.id, name: h.changedBy.fullName },
        createdAt: h.createdAt.toISOString(),
      })),
    };
  }

  private assertProjectOpen(p: Project) {
    if (!ACTIVE_PROJECT_STATUSES.includes(p.status))
      throw businessRule('Проект закрыт — задачи нельзя менять');
  }

  /** Ответственный — участник команды, РОП или менеджер проекта. */
  private async assertAssignee(p: Project, userId: string | undefined) {
    if (!userId || userId === p.ropId || userId === p.managerId) return;
    const m = await this.prisma.projectMember.findFirst({
      where: { projectId: p.id, userId, status: 'ACTIVE' },
    });
    if (!m) throw businessRule('Ответственный должен быть в команде проекта');
  }

  async create(
    auth: AuthContext,
    input: z.output<typeof createTaskSchema>,
    meta: RequestMeta,
  ): Promise<TaskDetailDto> {
    const p = await this.access.project(auth, input.projectId, 'task.create');
    this.assertProjectOpen(p);
    await this.assertAssignee(p, input.assigneeId);
    const deadline = input.deadline ? new Date(input.deadline) : null;
    const id = await this.prisma.$transaction(async (tx) => {
      const last = await tx.task.aggregate({
        where: { projectId: p.id, status: 'TODO', deletedAt: null },
        _max: { sortOrder: true },
      });
      const t = await tx.task.create({
        data: {
          projectId: p.id,
          title: input.title,
          description: input.description ?? null,
          assigneeId: input.assigneeId,
          creatorId: auth.userId,
          priority: input.priority,
          startDate: parseDate(input.startDate) ?? null,
          deadline,
          sortOrder: sortBetween(last._max.sortOrder, null),
        },
      });
      await tx.taskStatusHistory.create({
        data: { taskId: t.id, toStatus: 'TODO', changedById: auth.userId },
      });
      await this.activity.log(tx, {
        type: 'task.created',
        actorId: auth.userId,
        projectId: p.id,
        taskId: t.id,
        payload: { number: task(t.number), title: t.title },
      });
      await this.audit.log(tx, {
        actorId: auth.userId,
        action: 'task.create',
        entityType: 'task',
        entityId: t.id,
        changes: {
          title: { old: null, new: t.title },
          assigneeId: { old: null, new: t.assigneeId },
        },
        meta,
      });
      if (t.assigneeId !== auth.userId)
        await this.outbox.publish(
          tx,
          'task.assigned',
          { taskId: t.id, assigneeId: t.assigneeId },
          auth.userId,
        );
      return t.id;
    });
    return this.get(auth, id);
  }

  /**
   * Поля задачи меняет тот, кто управляет задачами проекта, или автор задачи.
   * Ответственный может менять только процент выполнения (и статус — через move).
   */
  async update(
    auth: AuthContext,
    id: string,
    input: z.output<typeof updateTaskSchema>,
    meta: RequestMeta,
  ): Promise<TaskDetailDto> {
    const t = await this.access.task(auth, id, 'task.update');
    this.assertProjectOpen(t.project);
    const canEdit = await this.canEdit(auth, t);
    const onlyProgress = Object.keys(input).every((k) => k === 'progressPct');
    if (!canEdit && !(onlyProgress && t.assigneeId === auth.userId)) throw forbidden();
    if (input.assigneeId !== undefined) await this.assertAssignee(t.project, input.assigneeId);

    const deadline =
      input.deadline !== undefined ? (input.deadline ? new Date(input.deadline) : null) : undefined;
    const data: Prisma.TaskUncheckedUpdateInput = {
      title: input.title,
      description: input.description,
      assigneeId: input.assigneeId,
      priority: input.priority,
      startDate: input.startDate !== undefined ? parseDate(input.startDate) : undefined,
      deadline,
      progressPct: input.progressPct,
    };
    // Новый дедлайн — напоминания о просрочке начинаются заново.
    if (deadline !== undefined && deadline?.getTime() !== t.deadline?.getTime())
      data.overdueNotifiedAt = null;

    await this.prisma.$transaction(async (tx) => {
      await tx.task.update({ where: { id }, data });
      const changes = diffFields(t, data as Partial<Task>, [
        'title',
        'description',
        'assigneeId',
        'priority',
        'startDate',
        'deadline',
        'progressPct',
      ]);
      if (!changes) return;
      await this.audit.log(tx, {
        actorId: auth.userId,
        action: 'task.update',
        entityType: 'task',
        entityId: id,
        changes,
        meta,
      });
      await this.activity.log(tx, {
        type: 'task.updated',
        actorId: auth.userId,
        projectId: t.projectId,
        taskId: id,
        payload: { number: task(t.number), title: t.title, fields: Object.keys(changes) },
      });
      if (changes.assigneeId && input.assigneeId && input.assigneeId !== auth.userId)
        await this.outbox.publish(
          tx,
          'task.assigned',
          { taskId: id, assigneeId: input.assigneeId },
          auth.userId,
        );
    });
    return this.get(auth, id);
  }

  private async canEdit(auth: AuthContext, t: Task): Promise<boolean> {
    if (t.creatorId === auth.userId) return true;
    return this.access.can(auth, t.projectId, 'task.create');
  }

  /**
   * Перемещение карточки Kanban (ТЗ §23): статус + позиция в колонке.
   * Закрытую или отменённую задачу возвращает в работу только тот, кто управляет задачами.
   */
  async move(
    auth: AuthContext,
    id: string,
    input: z.output<typeof moveTaskSchema>,
    meta: RequestMeta,
  ): Promise<TaskDetailDto> {
    const t = await this.access.task(auth, id, 'task.update');
    this.assertProjectOpen(t.project);
    const from = t.status;
    const to = input.status as TaskStatus;
    const canEdit = await this.canEdit(auth, t);
    if (!canEdit) {
      if (t.assigneeId !== auth.userId) throw forbidden();
      if (to === 'CANCELLED') throw businessRule('Отменить задачу может руководитель проекта');
      if (from === 'DONE' || from === 'CANCELLED')
        throw businessRule('Вернуть закрытую задачу в работу может руководитель проекта');
    }

    await this.prisma.$transaction(async (tx) => {
      const sortOrder = await this.positionIn(tx, t.projectId, to, input.beforeId ?? null, id);
      const data: Prisma.TaskUncheckedUpdateInput = { status: to, sortOrder };
      if (from !== to) {
        if (to === 'IN_PROGRESS' && !t.startedAt) data.startedAt = new Date();
        if (to === 'DONE') {
          data.completedAt = new Date();
          data.progressPct = 100;
        } else if (from === 'DONE') data.completedAt = null;
        if (isRework(from, to)) data.reworkCount = { increment: 1 };
      }
      await tx.task.update({ where: { id }, data });
      if (from === to) return;

      await tx.taskStatusHistory.create({
        data: { taskId: id, fromStatus: from, toStatus: to, changedById: auth.userId },
      });
      await this.activity.log(tx, {
        type: 'task.status_changed',
        actorId: auth.userId,
        projectId: t.projectId,
        taskId: id,
        payload: { number: task(t.number), title: t.title, from, to },
      });
      await this.audit.log(tx, {
        actorId: auth.userId,
        action: 'task.status',
        entityType: 'task',
        entityId: id,
        changes: { status: { old: from, new: to } },
        meta,
      });
      // Первая задача в работе — проект переходит в «В работе».
      if (to === 'IN_PROGRESS' && ['NEW', 'PLANNING'].includes(t.project.status)) {
        await tx.project.update({ where: { id: t.projectId }, data: { status: 'IN_PROGRESS' } });
        await this.activity.log(tx, {
          type: 'project.status_changed',
          actorId: auth.userId,
          projectId: t.projectId,
          payload: { from: t.project.status, to: 'IN_PROGRESS', auto: true },
        });
      }
      await this.outbox.publish(
        tx,
        'task.status_changed',
        { taskId: id, projectId: t.projectId, from, to },
        auth.userId,
      );
    });
    return this.get(auth, id);
  }

  /** sort_order для карточки перед beforeId (или в конце колонки). */
  private async positionIn(
    tx: Tx,
    projectId: string,
    status: TaskStatus,
    beforeId: string | null,
    selfId: string,
  ): Promise<number> {
    const column = { projectId, status, deletedAt: null, id: { not: selfId } };
    if (beforeId) {
      const before = await tx.task.findFirst({ where: { ...column, id: beforeId } });
      if (before) {
        const prev = await tx.task.findFirst({
          where: { ...column, sortOrder: { lt: before.sortOrder } },
          orderBy: { sortOrder: 'desc' },
        });
        return sortBetween(prev?.sortOrder ?? null, before.sortOrder);
      }
    }
    const last = await tx.task.aggregate({ where: column, _max: { sortOrder: true } });
    return sortBetween(last._max.sortOrder, null);
  }

  /** Удаление (soft) — только тот, кто управляет задачами проекта. */
  async remove(auth: AuthContext, id: string, meta: RequestMeta): Promise<void> {
    const t = await this.access.task(auth, id, 'task.update');
    this.assertProjectOpen(t.project);
    if (!(await this.access.can(auth, t.projectId, 'task.create'))) throw forbidden();
    await this.prisma.$transaction(async (tx) => {
      await tx.task.update({ where: { id }, data: { deletedAt: new Date() } });
      await this.activity.log(tx, {
        type: 'task.deleted',
        actorId: auth.userId,
        projectId: t.projectId,
        taskId: id,
        payload: { number: task(t.number), title: t.title },
      });
      await this.audit.log(tx, {
        actorId: auth.userId,
        action: 'task.delete',
        entityType: 'task',
        entityId: id,
        changes: { title: { old: t.title, new: null } },
        meta,
      });
    });
  }

  /** Комментарий может оставить любой, кто видит задачу (ТЗ §3.4). */
  async comment(auth: AuthContext, id: string, body: string): Promise<TaskCommentDto> {
    const t = await this.access.task(auth, id);
    return this.prisma.$transaction(async (tx) => {
      const c = await tx.taskComment.create({
        data: { taskId: id, authorId: auth.userId, body },
        include: { author: { select: { id: true, fullName: true } } },
      });
      await this.activity.log(tx, {
        type: 'task.commented',
        actorId: auth.userId,
        projectId: t.projectId,
        taskId: id,
        payload: { number: task(t.number), title: t.title },
      });
      return {
        id: c.id,
        author: { id: c.author.id, name: c.author.fullName },
        body: c.body,
        createdAt: c.createdAt.toISOString(),
      };
    });
  }
}

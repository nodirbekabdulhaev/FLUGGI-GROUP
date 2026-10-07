import { Injectable } from '@nestjs/common';
import {
  formatNumber,
  OPEN_TASK_STATUSES,
  type createTodoSchema,
  type Paginated,
  type TodoDockDto,
  type TodoDto,
  type todoListQuerySchema,
  type updateTodoSchema,
} from '@fluggi/contracts';
import type { Prisma } from '@fluggi/db';
import type { z } from 'zod';
import type { AuthContext } from '../../core/auth/auth-context';
import { businessRule, forbidden, notFound } from '../../core/http/app.exception';
import { PrismaService } from '../../core/prisma/prisma.service';
import { CrmAccessService } from '../crm/crm-access.service';
import { NotificationsService } from '../notifications/notifications.service';

export const todoInclude = {
  owner: { select: { id: true, fullName: true } },
  creator: { select: { id: true, fullName: true } },
  client: { select: { id: true, name: true } },
  deal: { select: { id: true, number: true, title: true } },
  lead: { select: { id: true, number: true, title: true } },
} satisfies Prisma.TodoInclude;

type Row = Prisma.TodoGetPayload<{ include: typeof todoInclude }>;

/** Все дела видит только тот, у кого задачи на всю компанию (CEO). */
const seesAll = (auth: AuthContext) => auth.permissions['task.read'] === 'ALL';

/**
 * Личные дела (ТЗ: «задачи не только по проектам»): звонки, письма, отчёты, платежи.
 * Видит и меняет дело исполнитель и тот, кто поручил; CEO — все.
 */
@Injectable()
export class TodosService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly crm: CrmAccessService,
    private readonly notifications: NotificationsService,
  ) {}

  toDto(t: Row, auth: AuthContext, now = new Date()): TodoDto {
    return {
      id: t.id,
      number: formatNumber('TD', t.number),
      title: t.title,
      description: t.description,
      kind: t.kind,
      priority: t.priority,
      status: t.status,
      dueAt: t.dueAt?.toISOString() ?? null,
      overdue: t.status === 'OPEN' && Boolean(t.dueAt && t.dueAt < now),
      owner: { id: t.owner.id, name: t.owner.fullName },
      creator: { id: t.creator.id, name: t.creator.fullName },
      client: t.client,
      deal: t.deal
        ? { id: t.deal.id, number: formatNumber('D', t.deal.number), name: t.deal.title }
        : null,
      lead: t.lead
        ? { id: t.lead.id, number: formatNumber('L', t.lead.number), name: t.lead.title }
        : null,
      recurring: Boolean(t.recurringId),
      completedAt: t.completedAt?.toISOString() ?? null,
      createdAt: t.createdAt.toISOString(),
      can: { update: this.canEdit(auth, t) },
    };
  }

  private canEdit(auth: AuthContext, t: { ownerId: string; creatorId: string }) {
    return t.ownerId === auth.userId || t.creatorId === auth.userId || seesAll(auth);
  }

  /** Поручить дело можно себе; другим — в пределах права task.create (РОП — отделу, CEO — всем). */
  async assertAssignable(auth: AuthContext, ownerId: string) {
    if (ownerId === auth.userId) return;
    const scope = auth.permissions['task.create'];
    const user = await this.prisma.user.findFirst({
      where: { id: ownerId, status: 'ACTIVE', deletedAt: null },
      select: { teamId: true },
    });
    if (!user) throw notFound('Сотрудник');
    const teams = [...auth.headedTeamIds, ...(auth.teamId ? [auth.teamId] : [])];
    if (scope === 'ALL' || (scope === 'TEAM' && user.teamId && teams.includes(user.teamId))) return;
    throw forbidden('Поручать дела можно только себе и своему отделу');
  }

  /** Связь с клиентом / сделкой / лидом — только с теми, что пользователь видит. */
  private async links(
    auth: AuthContext,
    input: { clientId?: string | null; dealId?: string | null; leadId?: string | null },
  ) {
    const out: { clientId?: string | null; dealId?: string | null; leadId?: string | null } = {};
    if (input.dealId) {
      const deal = await this.crm.deal(auth, input.dealId);
      out.dealId = deal.id;
      out.clientId = deal.clientId;
    } else if (input.dealId === null) out.dealId = null;
    if (input.clientId) out.clientId = (await this.crm.client(auth, input.clientId)).id;
    else if (input.clientId === null && !input.dealId) out.clientId = null;
    if (input.leadId) out.leadId = (await this.crm.lead(auth, input.leadId)).id;
    else if (input.leadId === null) out.leadId = null;
    return out;
  }

  private async row(auth: AuthContext, id: string) {
    const t = await this.prisma.todo.findFirst({
      where: { id, deletedAt: null },
      include: todoInclude,
    });
    if (!t || !this.canEdit(auth, t)) throw notFound('Дело');
    return t;
  }

  async list(
    auth: AuthContext,
    q: z.output<typeof todoListQuerySchema>,
  ): Promise<Paginated<TodoDto>> {
    if (q.view === 'all' && !seesAll(auth)) throw forbidden();
    const and: Prisma.TodoWhereInput[] = [{ deletedAt: null }];
    if (q.view === 'mine') and.push({ ownerId: auth.userId });
    if (q.view === 'assigned') and.push({ creatorId: auth.userId, ownerId: { not: auth.userId } });
    if (q.status) and.push({ status: q.status });
    if (q.clientId) and.push({ clientId: q.clientId });
    if (q.dealId) and.push({ dealId: q.dealId });
    const where = { AND: and };
    const [rows, total] = await Promise.all([
      this.prisma.todo.findMany({
        where,
        include: todoInclude,
        orderBy: [
          { status: 'asc' },
          { dueAt: { sort: 'asc', nulls: 'last' } },
          { createdAt: 'desc' },
        ],
        skip: (q.page - 1) * q.pageSize,
        take: q.pageSize,
      }),
      this.prisma.todo.count({ where }),
    ]);
    return {
      items: rows.map((r) => this.toDto(r, auth)),
      total,
      page: q.page,
      pageSize: q.pageSize,
    };
  }

  /** Панель «Список дел»: открытые личные дела и задачи по проектам, где я исполнитель. */
  async dock(auth: AuthContext): Promise<TodoDockDto> {
    const now = new Date();
    const [todos, tasks] = await Promise.all([
      this.prisma.todo.findMany({
        where: { ownerId: auth.userId, status: 'OPEN', deletedAt: null },
        include: todoInclude,
        orderBy: [{ dueAt: { sort: 'asc', nulls: 'last' } }, { createdAt: 'desc' }],
        take: 200,
      }),
      this.prisma.task.findMany({
        where: {
          assigneeId: auth.userId,
          deletedAt: null,
          status: { in: [...OPEN_TASK_STATUSES] },
          project: { deletedAt: null },
        },
        select: {
          id: true,
          number: true,
          title: true,
          priority: true,
          status: true,
          deadline: true,
          project: { select: { id: true, number: true, name: true } },
        },
        orderBy: [{ deadline: { sort: 'asc', nulls: 'last' } }],
        take: 200,
      }),
    ]);
    return {
      todos: todos.map((t) => this.toDto(t, auth, now)),
      projectTasks: tasks.map((t) => ({
        id: t.id,
        number: formatNumber('T', t.number),
        title: t.title,
        priority: t.priority,
        status: t.status,
        deadline: t.deadline?.toISOString() ?? null,
        overdue: Boolean(t.deadline && t.deadline < now),
        project: {
          id: t.project.id,
          number: formatNumber('P', t.project.number),
          name: t.project.name,
        },
      })),
    };
  }

  async create(auth: AuthContext, input: z.output<typeof createTodoSchema>): Promise<TodoDto> {
    const ownerId = input.ownerId ?? auth.userId;
    await this.assertAssignable(auth, ownerId);
    const links = await this.links(auth, input);
    const t = await this.prisma.todo.create({
      data: {
        title: input.title,
        description: input.description ?? null,
        kind: input.kind,
        priority: input.priority,
        dueAt: input.dueAt ? new Date(input.dueAt) : null,
        ownerId,
        creatorId: auth.userId,
        ...links,
      },
      include: todoInclude,
    });
    if (ownerId !== auth.userId)
      await this.notifications.notify([ownerId], {
        type: 'todo.assigned',
        title: `${t.creator.fullName} поручил дело`,
        body: t.title,
        link: '/todos',
      });
    return this.toDto(t, auth);
  }

  async update(
    auth: AuthContext,
    id: string,
    input: z.output<typeof updateTodoSchema>,
  ): Promise<TodoDto> {
    const before = await this.row(auth, id);
    if (input.ownerId && input.ownerId !== before.ownerId)
      await this.assertAssignable(auth, input.ownerId);
    const links = await this.links(auth, input);
    const t = await this.prisma.todo.update({
      where: { id },
      data: {
        title: input.title,
        description: input.description,
        kind: input.kind,
        priority: input.priority,
        dueAt: input.dueAt === undefined ? undefined : input.dueAt ? new Date(input.dueAt) : null,
        ownerId: input.ownerId,
        ...links,
      },
      include: todoInclude,
    });
    if (input.ownerId && input.ownerId !== before.ownerId && input.ownerId !== auth.userId)
      await this.notifications.notify([input.ownerId], {
        type: 'todo.assigned',
        title: 'Вам передано дело',
        body: t.title,
        link: '/todos',
      });
    return this.toDto(t, auth);
  }

  async setStatus(
    auth: AuthContext,
    id: string,
    status: 'OPEN' | 'DONE' | 'CANCELLED',
  ): Promise<TodoDto> {
    const before = await this.row(auth, id);
    if (before.status === status) throw businessRule('Статус уже установлен');
    const t = await this.prisma.todo.update({
      where: { id },
      data: { status, completedAt: status === 'DONE' ? new Date() : null },
      include: todoInclude,
    });
    // Поручившему — что дело выполнено
    if (status === 'DONE' && t.creatorId !== auth.userId)
      await this.notifications.notify([t.creatorId], {
        type: 'todo.assigned',
        title: `${t.owner.fullName} выполнил дело`,
        body: t.title,
        link: '/todos?view=assigned',
      });
    return this.toDto(t, auth);
  }

  async remove(auth: AuthContext, id: string) {
    const t = await this.row(auth, id);
    if (t.creatorId !== auth.userId && !seesAll(auth))
      throw forbidden('Удалить дело может тот, кто его создал');
    await this.prisma.todo.update({ where: { id }, data: { deletedAt: new Date() } });
  }
}

import { Injectable, Logger } from '@nestjs/common';
import { TAX_CALENDAR, type recurringTodoSchema, type RecurringTodoDto } from '@fluggi/contracts';
import { addDays, atTashkent, companyDate, fillPeriod, nextDue } from '@fluggi/domain';
import { Prisma } from '@fluggi/db';
import type { z } from 'zod';
import type { AuthContext } from '../../core/auth/auth-context';
import { parseDate } from '../../core/http/serialize';
import { forbidden, notFound } from '../../core/http/app.exception';
import { PrismaService } from '../../core/prisma/prisma.service';
import { NotificationsService } from '../notifications/notifications.service';
import { TodosService } from './todos.service';

type Input = z.output<typeof recurringTodoSchema>;
const include = {
  owner: { select: { id: true, fullName: true } },
} satisfies Prisma.RecurringTodoInclude;
type Row = Prisma.RecurringTodoGetPayload<{ include: typeof include }>;

/** Срок дела по правилу — конец рабочего дня по Ташкенту. */
const DUE_TIME = '18:00';
const dateLabel = (d: string) => d.split('-').reverse().join('.');

/**
 * Регулярные дела (налоговый календарь и т.п.): за N дней до срока создаётся дело
 * исполнителю, уведомление — в CRM и Telegram.
 */
@Injectable()
export class RecurringTodosService {
  private readonly logger = new Logger(RecurringTodosService.name);

  constructor(
    private readonly prisma: PrismaService,
    private readonly todos: TodosService,
    private readonly notifications: NotificationsService,
  ) {}

  private toDto(r: Row, today = companyDate(new Date())): RecurringTodoDto {
    const due = nextDue(r, today);
    return {
      id: r.id,
      title: r.title,
      description: r.description,
      kind: r.kind,
      priority: r.priority,
      owner: { id: r.owner.id, name: r.owner.fullName },
      frequency: r.frequency,
      dayOfMonth: r.dayOfMonth,
      month: r.month,
      remindDaysBefore: r.remindDaysBefore,
      isActive: r.isActive,
      nextDue: due,
      nextTitle: fillPeriod(r.title, due),
    };
  }

  private where(auth: AuthContext): Prisma.RecurringTodoWhereInput {
    return { deletedAt: null, OR: [{ ownerId: auth.userId }, { createdById: auth.userId }] };
  }

  async list(auth: AuthContext): Promise<RecurringTodoDto[]> {
    const rows = await this.prisma.recurringTodo.findMany({
      where: this.where(auth),
      include,
      orderBy: [{ frequency: 'asc' }, { dayOfMonth: 'asc' }, { createdAt: 'asc' }],
    });
    return rows
      .map((r) => this.toDto(r))
      .sort((a, b) => a.nextDue.localeCompare(b.nextDue) || a.title.localeCompare(b.title));
  }

  private data(input: Input) {
    return {
      title: input.title,
      description: input.description ?? null,
      kind: input.kind,
      priority: input.priority,
      frequency: input.frequency,
      dayOfMonth: input.dayOfMonth,
      month: input.frequency === 'MONTHLY' ? null : (input.month ?? 1),
      remindDaysBefore: input.remindDaysBefore,
      isActive: input.isActive,
    };
  }

  async create(auth: AuthContext, input: Input): Promise<RecurringTodoDto> {
    const ownerId = input.ownerId ?? auth.userId;
    // Регулярные дела другим сотрудникам ставит только CEO; остальные — себе
    if (ownerId !== auth.userId && auth.roleCode !== 'CEO')
      throw forbidden('Регулярные дела можно ставить только себе');
    await this.todos.assertAssignable(auth, ownerId);
    const r = await this.prisma.recurringTodo.create({
      data: { ...this.data(input), ownerId, createdById: auth.userId },
      include,
    });
    return this.toDto(r);
  }

  async update(auth: AuthContext, id: string, input: Input): Promise<RecurringTodoDto> {
    const before = await this.prisma.recurringTodo.findFirst({
      where: { AND: [this.where(auth), { id }] },
    });
    if (!before) throw notFound('Регулярное дело');
    const ownerId = input.ownerId ?? before.ownerId;
    if (ownerId !== before.ownerId) await this.todos.assertAssignable(auth, ownerId);
    const r = await this.prisma.recurringTodo.update({
      where: { id },
      data: { ...this.data(input), ownerId },
      include,
    });
    return this.toDto(r);
  }

  async remove(auth: AuthContext, id: string) {
    const r = await this.prisma.recurringTodo.findFirst({
      where: { AND: [this.where(auth), { id }] },
    });
    if (!r) throw notFound('Регулярное дело');
    await this.prisma.recurringTodo.update({
      where: { id },
      data: { deletedAt: new Date(), isActive: false },
    });
  }

  /** Налоговый календарь IT-Park одним нажатием; уже добавленные пункты не дублируются. */
  async addTaxCalendar(auth: AuthContext): Promise<RecurringTodoDto[]> {
    if (auth.roleCode !== 'CEO') throw forbidden('Налоговый календарь доступен только CEO');
    const existing = await this.prisma.recurringTodo.findMany({
      where: { ownerId: auth.userId, deletedAt: null },
      select: { title: true },
    });
    const have = new Set(existing.map((e) => e.title));
    for (const item of TAX_CALENDAR) {
      if (have.has(item.title)) continue;
      await this.prisma.recurringTodo.create({
        data: {
          title: item.title,
          description: item.description ?? null,
          kind: item.kind ?? 'REPORT',
          priority: item.priority ?? 'HIGH',
          frequency: item.frequency,
          dayOfMonth: Number(item.dayOfMonth),
          month: item.frequency === 'MONTHLY' ? null : Number(item.month ?? 1),
          remindDaysBefore: Number(item.remindDaysBefore ?? 3),
          ownerId: auth.userId,
          createdById: auth.userId,
        },
      });
    }
    return this.list(auth);
  }

  /** Задача планировщика: создать дела, срок которых наступает в ближайшие N дней. */
  async generate(now = new Date()) {
    const today = companyDate(now);
    const rules = await this.prisma.recurringTodo.findMany({
      where: { isActive: true, deletedAt: null, owner: { status: 'ACTIVE', deletedAt: null } },
    });
    let created = 0;
    for (const r of rules) {
      const due = nextDue(r, today);
      if (addDays(today, r.remindDaysBefore) < due) continue;
      const title = fillPeriod(r.title, due);
      try {
        await this.prisma.todo.create({
          data: {
            title,
            description: r.description,
            kind: r.kind,
            priority: r.priority,
            dueAt: atTashkent(due, DUE_TIME),
            ownerId: r.ownerId,
            creatorId: r.createdById,
            recurringId: r.id,
            recurringDue: parseDate(due)!,
          },
        });
      } catch (err) {
        if (err instanceof Prisma.PrismaClientKnownRequestError && err.code === 'P2002') continue;
        throw err;
      }
      created += 1;
      await this.notifications.notify([r.ownerId], {
        type: 'todo.recurring',
        title: `📅 ${title}`,
        body: `Срок: ${dateLabel(due)}`,
        link: '/todos',
      });
    }
    if (created) this.logger.log(`Recurring todos created: ${created}`);
    return { created };
  }
}

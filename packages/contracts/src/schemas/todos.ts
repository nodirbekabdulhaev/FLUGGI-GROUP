import { z } from 'zod';
import { PRIORITIES, type Priority } from '../enums';
import { dateTime } from './fields';
import type { NamedRef } from './references';
import type { NumberedRef } from './sales';

// ─────────────────────────── Личные дела («Список дел») ───────────────────────────

export const TODO_KINDS = ['TASK', 'CALL', 'EMAIL', 'MEETING', 'PAYMENT', 'REPORT'] as const;
export type TodoKind = (typeof TODO_KINDS)[number];
export const TODO_STATUSES = ['OPEN', 'DONE', 'CANCELLED'] as const;
export type TodoStatus = (typeof TODO_STATUSES)[number];

const text = (max: number) => z.string().trim().max(max);

export const createTodoSchema = z.object({
  title: text(300).min(1, 'Введите название'),
  description: text(4000).nullish(),
  kind: z.enum(TODO_KINDS).default('TASK'),
  priority: z.enum(PRIORITIES).default('MEDIUM'),
  dueAt: dateTime.nullish(),
  /** Исполнитель; по умолчанию — я */
  ownerId: z.uuid().optional(),
  clientId: z.uuid().nullish(),
  dealId: z.uuid().nullish(),
  leadId: z.uuid().nullish(),
});
export type CreateTodoInput = z.input<typeof createTodoSchema>;

export const updateTodoSchema = createTodoSchema.partial().extend({
  title: text(300).min(1).optional(),
});
export type UpdateTodoInput = z.input<typeof updateTodoSchema>;

export const todoListQuerySchema = z.object({
  /** mine — мои; assigned — поручил я другим; all — все (только CEO) */
  view: z.enum(['mine', 'assigned', 'all']).default('mine'),
  status: z.enum(TODO_STATUSES).optional(),
  clientId: z.uuid().optional(),
  dealId: z.uuid().optional(),
  page: z.coerce.number().int().min(1).default(1),
  pageSize: z.coerce.number().int().min(1).max(200).default(50),
});
export type TodoListQuery = z.input<typeof todoListQuerySchema>;

export interface TodoDto {
  id: string;
  number: string;
  title: string;
  description: string | null;
  kind: TodoKind;
  priority: Priority;
  status: TodoStatus;
  dueAt: string | null;
  overdue: boolean;
  owner: NamedRef;
  creator: NamedRef;
  client: NamedRef | null;
  deal: NumberedRef | null;
  lead: NumberedRef | null;
  recurring: boolean;
  completedAt: string | null;
  createdAt: string;
  can: { update: boolean };
}

/** Панель «Список дел»: мои открытые дела и мои задачи по проектам. */
export interface TodoDockDto {
  todos: TodoDto[];
  projectTasks: {
    id: string;
    number: string;
    title: string;
    priority: Priority;
    status: string;
    deadline: string | null;
    overdue: boolean;
    project: NumberedRef;
  }[];
}

// ─────────────────────────── Регулярные дела ───────────────────────────

export const RECURRENCE_FREQUENCIES = ['MONTHLY', 'QUARTERLY', 'YEARLY'] as const;
export type RecurrenceFrequency = (typeof RECURRENCE_FREQUENCIES)[number];

export const recurringTodoSchema = z
  .object({
    title: text(300).min(1, 'Введите название'),
    description: text(4000).nullish(),
    kind: z.enum(TODO_KINDS).default('REPORT'),
    priority: z.enum(PRIORITIES).default('HIGH'),
    ownerId: z.uuid().optional(),
    frequency: z.enum(RECURRENCE_FREQUENCIES),
    dayOfMonth: z.coerce.number().int().min(1).max(28),
    month: z.coerce.number().int().min(1).max(12).nullish(),
    remindDaysBefore: z.coerce.number().int().min(0).max(30).default(3),
    isActive: z.boolean().default(true),
  })
  .refine((v) => v.frequency !== 'QUARTERLY' || !v.month || v.month <= 3, {
    path: ['month'],
    message: 'Для квартала — месяц квартала 1–3',
  });
export type RecurringTodoInput = z.input<typeof recurringTodoSchema>;

export interface RecurringTodoDto {
  id: string;
  title: string;
  description: string | null;
  kind: TodoKind;
  priority: Priority;
  owner: NamedRef;
  frequency: RecurrenceFrequency;
  dayOfMonth: number;
  month: number | null;
  remindDaysBefore: number;
  isActive: boolean;
  /** Ближайший срок YYYY-MM-DD и название с подставленным периодом */
  nextDue: string;
  nextTitle: string;
}

/**
 * Налоговый календарь резидента IT-Park (по списку CEO). Названия — шаблоны:
 * {прошлый_месяц}, {прошлый_квартал}, {прошлый_год}, {год} подставляются от срока.
 */
export const TAX_CALENDAR: Omit<RecurringTodoInput, 'ownerId'>[] = [
  {
    title: 'Налог с оборота за {прошлый_месяц}',
    kind: 'PAYMENT',
    frequency: 'MONTHLY',
    dayOfMonth: 4,
  },
  {
    title: 'Налог на доходы работников (НДФЛ) за {прошлый_месяц}',
    kind: 'PAYMENT',
    frequency: 'MONTHLY',
    dayOfMonth: 4,
  },
  {
    title: 'Отчёт IT-Park об обороте за {прошлый_месяц}',
    kind: 'REPORT',
    frequency: 'MONTHLY',
    dayOfMonth: 4,
  },
  {
    title: 'ИНПС — посчитать за {прошлый_месяц}',
    kind: 'PAYMENT',
    frequency: 'MONTHLY',
    dayOfMonth: 10,
  },
  {
    title: 'Квартальный отчёт IT-Park за {прошлый_квартал}',
    kind: 'REPORT',
    frequency: 'QUARTERLY',
    dayOfMonth: 4,
    month: 1,
  },
  {
    title: 'Статотчёт за {прошлый_квартал}',
    kind: 'REPORT',
    frequency: 'QUARTERLY',
    dayOfMonth: 4,
    month: 1,
  },
  {
    title: 'Баланс (форма №1) и отчёт о финансовых результатах (форма №2) за {год}',
    description: 'Скачать «Пакет для бухгалтера» в разделе «Финансы» и отправить бухгалтеру',
    kind: 'REPORT',
    frequency: 'YEARLY',
    dayOfMonth: 1,
    month: 12,
  },
  {
    title: 'Годовой статотчёт за {прошлый_год}',
    kind: 'REPORT',
    frequency: 'YEARLY',
    dayOfMonth: 1,
    month: 2,
  },
];

// ─────────────────────────── Чат сотрудников ───────────────────────────

export const chatMessageSchema = z.object({ body: z.string().trim().min(1).max(4000) });
export const directChatSchema = z.object({ userId: z.uuid() });
export const chatMessagesQuerySchema = z.object({
  before: dateTime.optional(),
  limit: z.coerce.number().int().min(1).max(100).default(50),
});

export interface ChatMessageDto {
  id: string;
  conversationId: string;
  author: NamedRef;
  body: string;
  createdAt: string;
  mine: boolean;
}

export interface ConversationDto {
  id: string;
  /** Собеседник (личная переписка) */
  peer: NamedRef & { role: string };
  lastMessage: { body: string; createdAt: string; mine: boolean } | null;
  unread: number;
}

export interface ChatContactDto extends NamedRef {
  role: string;
  team: string | null;
}

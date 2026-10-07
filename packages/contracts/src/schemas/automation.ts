import { z } from 'zod';
import type { RoleCode } from '../enums';
import type { NamedRef } from './references';
import type { NumberedRef } from './sales';

// ─────────────────────────── Каталог уведомлений (ТЗ §14) ───────────────────────────

export const NOTIFICATION_CHANNELS = ['IN_APP', 'TELEGRAM'] as const;
export type NotificationChannel = (typeof NOTIFICATION_CHANNELS)[number];

/** Типы уведомлений и роли, которым они приходят. Используется в настройках пользователя. */
export const NOTIFICATION_EVENTS: { type: string; label: string; roles: RoleCode[] }[] = [
  { type: 'lead.created', label: 'Новый лид', roles: ['MANAGER', 'ROP', 'CEO'] },
  { type: 'lead.assigned', label: 'Вам назначен лид', roles: ['MANAGER', 'ROP'] },
  { type: 'lead.large', label: 'Крупный лид', roles: ['ROP', 'CEO'] },
  { type: 'deal.created', label: 'Новая сделка', roles: ['ROP', 'CEO'] },
  { type: 'deal.large', label: 'Крупная сделка', roles: ['ROP', 'CEO'] },
  { type: 'deal.large_lost', label: 'Потеря крупного клиента', roles: ['ROP', 'CEO'] },
  { type: 'meeting.created', label: 'Новая встреча', roles: ['MANAGER', 'ROP'] },
  { type: 'meeting.reminder', label: 'Встреча завтра / через 30 минут', roles: ['MANAGER', 'ROP'] },
  {
    type: 'client.no_contact',
    label: 'Клиенту не звонили 3 дня / клиент не отвечает',
    roles: ['MANAGER', 'ROP'],
  },
  { type: 'proposal.approval', label: 'КП на согласовании', roles: ['ROP', 'CEO'] },
  { type: 'proposal.reminder', label: 'КП отправлено 2 дня назад', roles: ['MANAGER'] },
  { type: 'contract.reminder', label: 'Договор не подписан', roles: ['MANAGER', 'ROP'] },
  { type: 'contract.signed', label: 'Договор подписан', roles: ['MANAGER', 'ROP'] },
  { type: 'payment.paid', label: 'Оплата получена', roles: ['MANAGER', 'ROP', 'CEO'] },
  { type: 'payment.overdue', label: 'Оплата просрочена', roles: ['MANAGER', 'ROP'] },
  { type: 'plan.achieved', label: 'Достижение месячного плана', roles: ['CEO', 'ROP'] },
  { type: 'project.created', label: 'Новый проект', roles: ['MANAGER', 'ROP'] },
  {
    type: 'project.member_added',
    label: 'Вас добавили в проект',
    roles: ['EXECUTOR', 'MANAGER', 'ROP'],
  },
  { type: 'project.ending', label: 'Проект заканчивается через 3 дня', roles: ['MANAGER', 'ROP'] },
  { type: 'project.overdue', label: 'Просроченный проект', roles: ['ROP', 'CEO'] },
  { type: 'project.completed', label: 'Проект завершён', roles: ['MANAGER', 'ROP', 'CEO'] },
  { type: 'task.assigned', label: 'Новая задача', roles: ['EXECUTOR', 'MANAGER', 'ROP'] },
  { type: 'task.deadline_changed', label: 'Изменение дедлайна', roles: ['EXECUTOR', 'MANAGER'] },
  { type: 'task.review', label: 'Задача на проверке', roles: ['ROP', 'MANAGER', 'CEO'] },
  { type: 'task.returned', label: 'Задача возвращена / принята', roles: ['EXECUTOR'] },
  { type: 'task.overdue', label: 'Просроченная задача', roles: ['EXECUTOR', 'MANAGER', 'ROP'] },
  { type: 'client.risk', label: 'Клиент в зоне риска', roles: ['MANAGER', 'ROP', 'CEO'] },
  { type: 'followup.due', label: 'Повторный контакт с клиентом', roles: ['MANAGER', 'ROP'] },
  {
    type: 'todo.assigned',
    label: 'Вам поручено дело',
    roles: ['CEO', 'ROP', 'MANAGER', 'EXECUTOR', 'HR_ADMIN'],
  },
  {
    type: 'todo.due',
    label: 'Срок дела / регулярная задача',
    roles: ['CEO', 'ROP', 'MANAGER', 'EXECUTOR', 'HR_ADMIN'],
  },
  {
    type: 'chat.message',
    label: 'Сообщение в чате',
    roles: ['CEO', 'ROP', 'MANAGER', 'EXECUTOR', 'HR_ADMIN'],
  },
  { type: 'report.daily', label: 'Ежедневный отчёт', roles: ['CEO', 'ROP'] },
  { type: 'report.weekly', label: 'Еженедельный отчёт', roles: ['CEO'] },
  { type: 'payroll.calculated', label: 'Предварительный расчёт зарплаты', roles: ['CEO'] },
];

/** Близкие типы уведомлений настраиваются одним переключателем. */
export const NOTIFICATION_TYPE_GROUP: Record<string, string> = {
  'task.accepted': 'task.returned',
  'project.cancelled': 'project.completed',
  'todo.recurring': 'todo.due',
  'todo.overdue': 'todo.due',
};

export const notificationSettingsSchema = z.object({
  settings: z
    .array(
      z.object({
        eventType: z.string().min(1).max(60),
        channel: z.enum(NOTIFICATION_CHANNELS),
        enabled: z.boolean(),
      }),
    )
    .max(200),
});
export type NotificationSettingsInput = z.input<typeof notificationSettingsSchema>;

export interface NotificationSettingDto {
  type: string;
  label: string;
  inApp: boolean;
  telegram: boolean;
}

// ─────────────────────────── Telegram (ТЗ §53) ───────────────────────────

export interface TelegramStatusDto {
  /** Бот настроен на сервере (есть токен). */
  botConfigured: boolean;
  botUsername: string | null;
  linked: boolean;
  username: string | null;
  /** Бот настроен, но не работает: причина для пользователя (неверный токен, нет связи и т.п.). */
  problem: string | null;
}

export interface TelegramLinkDto {
  /** Ссылка https://t.me/<bot>?start=<код> */
  deepLink: string;
  /** Команда, которую можно отправить боту вручную */
  command: string;
  expiresAt: string;
}

// ─────────────────────────── Follow-up (ТЗ §38) ───────────────────────────

export const FOLLOW_UP_KINDS = ['CONTACT', 'NEW_PROJECT', 'REPEAT_SALE'] as const;
export type FollowUpKind = (typeof FOLLOW_UP_KINDS)[number];
export const FOLLOW_UP_STATUSES = ['PENDING', 'DONE', 'SKIPPED'] as const;
export type FollowUpStatus = (typeof FOLLOW_UP_STATUSES)[number];

export const followUpListQuerySchema = z.object({
  status: z.enum(FOLLOW_UP_STATUSES).optional(),
  /** Только просроченные и на сегодня */
  due: z
    .enum(['true', 'false'])
    .optional()
    .transform((v) => v === 'true'),
  clientId: z.uuid().optional(),
  page: z.coerce.number().int().min(1).default(1),
  pageSize: z.coerce.number().int().min(1).max(100).default(25),
});
export type FollowUpListQuery = z.input<typeof followUpListQuerySchema>;

export const completeFollowUpSchema = z.object({
  status: z.enum(['DONE', 'SKIPPED']),
  result: z.string().trim().max(2000).optional(),
  /** Создать сделку «Повторная продажа» у клиента */
  createDeal: z.boolean().default(false),
});
export type CompleteFollowUpInput = z.input<typeof completeFollowUpSchema>;

export interface FollowUpDto {
  id: string;
  client: NamedRef;
  project: NumberedRef | null;
  owner: NamedRef;
  kind: FollowUpKind;
  dueDate: string;
  overdueDays: number;
  status: FollowUpStatus;
  result: string | null;
  resultDeal: NumberedRef | null;
  completedAt: string | null;
}

// ─────────────────────────── Настройки автоматизации ───────────────────────────

export const automationSettingsSchema = z.object({
  /** Порог «крупного» лида/сделки, UZS (ТЗ §14) */
  largeAmountUzs: z.coerce.number().int().min(0).max(1e13),
  /** Интервалы follow-up после завершения проекта, дней (ТЗ §38) */
  followUps: z
    .array(
      z.object({ days: z.coerce.number().int().min(1).max(730), kind: z.enum(FOLLOW_UP_KINDS) }),
    )
    .max(10),
  /** Включены ли отчёты руководителю */
  dailyReport: z.boolean(),
  weeklyReport: z.boolean(),
});
export type AutomationSettings = z.output<typeof automationSettingsSchema>;

export const DEFAULT_AUTOMATION_SETTINGS: AutomationSettings = {
  largeAmountUzs: 50_000_000,
  followUps: [
    { days: 30, kind: 'CONTACT' },
    { days: 60, kind: 'NEW_PROJECT' },
    { days: 90, kind: 'REPEAT_SALE' },
  ],
  dailyReport: true,
  weeklyReport: true,
};

export interface JobRunDto {
  job: string;
  slot: string;
  startedAt: string;
  finishedAt: string | null;
  error: string | null;
}

export interface ReportPreviewDto {
  text: string;
}

// ─────────────────────────── Реквизиты компании (для бухгалтера) ───────────────────────────

export const TAX_REGIMES = ['IT_PARK', 'TURNOVER', 'GENERAL', 'OTHER'] as const;
export type TaxRegime = (typeof TAX_REGIMES)[number];
export const TAX_REGIME_LABELS: Record<TaxRegime, string> = {
  IT_PARK: 'Резидент IT-Park',
  TURNOVER: 'Налог с оборота',
  GENERAL: 'Общий режим (НДС и налог на прибыль)',
  OTHER: 'Другой',
};

export const companySettingsSchema = z.object({
  name: z.string().trim().max(200),
  /** ИНН (СТИР) — 9 цифр */
  inn: z
    .string()
    .trim()
    .regex(/^(\d{9})?$/, 'ИНН — 9 цифр'),
  director: z.string().trim().max(120),
  accountant: z.string().trim().max(120),
  taxRegime: z.enum(TAX_REGIMES),
  // Реквизиты для КП и договоров (подставляются в документы автоматически)
  legalName: z.string().trim().max(300).default(''),
  directorPosition: z.string().trim().max(120).default('Директор'),
  /** «в лице …» — родительный падеж: «директора Иванова Ивана Ивановича» */
  signerGenitive: z.string().trim().max(200).default(''),
  basis: z.string().trim().max(200).default('Устава'),
  address: z.string().trim().max(300).default(''),
  phone: z.string().trim().max(60).default(''),
  email: z.string().trim().max(120).default(''),
  website: z.string().trim().max(120).default(''),
  bank: z.string().trim().max(200).default(''),
  /** МФО банка — 5 цифр */
  mfo: z
    .string()
    .trim()
    .regex(/^(\d{5})?$/, 'МФО — 5 цифр')
    .default(''),
  /** Расчётный счёт — 20 цифр */
  account: z
    .string()
    .trim()
    .regex(/^(\d{20})?$/, 'Расчётный счёт — 20 цифр')
    .default(''),
  oked: z.string().trim().max(10).default(''),
  vatCode: z.string().trim().max(20).default(''),
});
export type CompanySettings = z.output<typeof companySettingsSchema>;

export const DEFAULT_COMPANY_SETTINGS: CompanySettings = {
  name: '',
  inn: '',
  director: '',
  accountant: '',
  taxRegime: 'OTHER',
  legalName: '',
  directorPosition: 'Директор',
  signerGenitive: '',
  basis: 'Устава',
  address: '',
  phone: '',
  email: '',
  website: '',
  bank: '',
  mfo: '',
  account: '',
  oked: '',
  vatCode: '',
};

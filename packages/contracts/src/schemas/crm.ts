import { z } from 'zod';
import {
  CLIENT_TYPES,
  CLOSE_STATUSES,
  COMPANY_SIZES,
  CURRENCIES,
  DEAL_STAGE_CODES,
  DEAL_STATUSES,
  LEAD_STAGE_CODES,
  LEAD_STATUSES,
  MEETING_STATUSES,
  MEETING_TYPES,
  PRIORITIES,
  REASON_REQUIRED_STATUSES,
  SCORE_LEVELS,
  type ClientHealth,
  type ClientType,
  type CloseStatus,
  type CompanySize,
  type Currency,
  type DealStatus,
  type LeadStatus,
  type MeetingStatus,
  type MeetingType,
  type Priority,
  type ScoreLevelCode,
} from '../enums';
import { paginationQuerySchema } from './common';
import { dateOnly, dateTime, emailText, moneySchema, optMoney, optText, phoneText } from './fields';
import type { NamedRef, StageDto } from './references';

// ─────────────────────────── Лиды ───────────────────────────

const leadContactFields = {
  contactName: optText(120),
  companyName: optText(160),
  phone: phoneText,
  telegram: optText(64),
  whatsapp: phoneText,
  instagram: optText(64),
  email: emailText,
  website: optText(200),
  city: optText(80),
  country: optText(80),
};

const leadBusinessFields = {
  title: optText(200),
  sourceId: z.uuid('Выберите источник'),
  serviceId: z.uuid('Выберите услугу'),
  budget: optMoney,
  currency: z.enum(CURRENCIES).default('UZS'),
  desiredDate: dateOnly.optional(),
  priority: z.enum(PRIORITIES).default('MEDIUM'),
  companySize: z.enum(COMPANY_SIZES).optional(),
  interest: z.coerce.number().int().min(1).max(5).optional(),
  nextContactAt: dateTime.optional(),
  comment: optText(4000),
};

/**
 * Создание лида — минимум полей (ТЗ §78): имя или компания, телефон или Telegram,
 * источник, услуга. Остальное заполняется позже.
 */
export const createLeadSchema = z
  .object({ ...leadContactFields, ...leadBusinessFields, ownerId: z.uuid().optional() })
  .refine((v) => v.contactName || v.companyName, {
    path: ['contactName'],
    message: 'Укажите имя контакта или компанию',
  })
  .refine((v) => v.phone || v.telegram, {
    path: ['phone'],
    message: 'Укажите телефон или Telegram',
  });
export type CreateLeadInput = z.input<typeof createLeadSchema>;

/** Обновление: поля можно очистить (null). Владелец меняется через /assign. */
const nullable = <T extends z.ZodType>(s: T) => s.nullable();
export const updateLeadSchema = z
  .object({
    title: z.string().trim().min(1).max(200),
    contactName: nullable(z.string().trim().max(120)),
    companyName: nullable(z.string().trim().max(160)),
    phone: nullable(
      z
        .string()
        .trim()
        .max(30)
        .regex(/^[+0-9\s\-()]*$/, 'Некорректный телефон'),
    ),
    telegram: nullable(z.string().trim().max(64)),
    whatsapp: nullable(z.string().trim().max(30)),
    instagram: nullable(z.string().trim().max(64)),
    email: nullable(z.string().trim().toLowerCase().max(254)),
    website: nullable(z.string().trim().max(200)),
    city: nullable(z.string().trim().max(80)),
    country: nullable(z.string().trim().max(80)),
    sourceId: z.uuid(),
    serviceId: nullable(z.uuid()),
    budget: nullable(moneySchema),
    currency: z.enum(CURRENCIES),
    desiredDate: nullable(dateOnly),
    priority: z.enum(PRIORITIES),
    companySize: nullable(z.enum(COMPANY_SIZES)),
    interest: nullable(z.coerce.number().int().min(1).max(5)),
    nextContactAt: nullable(dateTime),
    comment: nullable(z.string().trim().max(4000)),
  })
  .partial()
  .transform((v) => {
    // Пустые строки из формы → null
    const out: Record<string, unknown> = {};
    for (const [k, val] of Object.entries(v)) out[k] = val === '' ? null : val;
    return out as typeof v;
  });
export type UpdateLeadInput = z.input<typeof updateLeadSchema>;

export const leadListQuerySchema = paginationQuerySchema.extend({
  q: z.string().trim().max(100).optional(),
  stageCode: z.enum(LEAD_STAGE_CODES).optional(),
  status: z.enum(LEAD_STATUSES).optional(),
  ownerId: z.uuid().optional(),
  teamId: z.uuid().optional(),
  sourceId: z.uuid().optional(),
  serviceId: z.uuid().optional(),
  scoreLevel: z.enum(SCORE_LEVELS).optional(),
  dateFrom: dateOnly.optional(),
  dateTo: dateOnly.optional(),
});
export type LeadListQuery = Partial<z.output<typeof leadListQuerySchema>>;

export const changeLeadStageSchema = z.object({ stageCode: z.enum(LEAD_STAGE_CODES) });
export const changeDealStageSchema = z.object({ stageCode: z.enum(DEAL_STAGE_CODES) });
export const assignSchema = z.object({ ownerId: z.uuid('Выберите ответственного') });

/** Закрытие лида/сделки (ТЗ §7, §39): для «Потеряно» и «Отказ» причина обязательна. */
export const closeSchema = z
  .object({
    status: z.enum(CLOSE_STATUSES),
    lossReasonId: z.uuid().optional(),
    comment: z.string().trim().max(2000).optional(),
  })
  .refine((v) => !REASON_REQUIRED_STATUSES.includes(v.status) || v.lossReasonId, {
    path: ['lossReasonId'],
    message: 'Укажите причину',
  });
export type CloseInput = z.input<typeof closeSchema>;

/** Квалификация лида → клиент + сделка (BUSINESS_RULES §2). */
export const convertLeadSchema = z
  .object({
    clientId: z.uuid().optional(),
    clientName: optText(160),
    clientType: z.enum(CLIENT_TYPES).default('COMPANY'),
    title: optText(200),
    amount: moneySchema,
    currency: z.enum(CURRENCIES).default('UZS'),
    expectedCloseDate: dateOnly.optional(),
  })
  .refine((v) => v.clientId || v.clientName, {
    path: ['clientName'],
    message: 'Выберите клиента или укажите название нового',
  });
export type ConvertLeadInput = z.input<typeof convertLeadSchema>;

export interface StageRef {
  id: string;
  code: string;
  name: string;
  color: string;
}

export interface LeadDto {
  id: string;
  number: string;
  title: string;
  contactName: string | null;
  companyName: string | null;
  phone: string | null;
  telegram: string | null;
  whatsapp: string | null;
  instagram: string | null;
  email: string | null;
  website: string | null;
  city: string | null;
  country: string | null;
  source: NamedRef;
  owner: NamedRef;
  team: NamedRef | null;
  service: NamedRef | null;
  budget: string | null;
  currency: Currency;
  budgetUzs: string | null;
  desiredDate: string | null;
  priority: Priority;
  companySize: CompanySize | null;
  interest: number | null;
  stage: StageRef;
  status: LeadStatus;
  score: number;
  scoreLevel: ScoreLevelCode;
  nextContactAt: string | null;
  lastContactAt: string | null;
  comment: string | null;
  clientId: string | null;
  dealId: string | null;
  lossReason: NamedRef | null;
  lossComment: string | null;
  createdAt: string;
  updatedAt: string;
}

// ─────────────────────────── Клиенты ───────────────────────────

const contactFields = {
  fullName: z.string().trim().min(1, 'Укажите имя').max(120),
  position: optText(120),
  phone: phoneText,
  telegram: optText(64),
  whatsapp: phoneText,
  instagram: optText(64),
  email: emailText,
  isPrimary: z.boolean().default(false),
};
export const contactSchema = z.object(contactFields);
export type ContactInput = z.input<typeof contactSchema>;

export const createClientSchema = z.object({
  name: z.string().trim().min(1, 'Укажите название').max(160),
  type: z.enum(CLIENT_TYPES).default('COMPANY'),
  industry: optText(120),
  phone: phoneText,
  email: emailText,
  telegram: optText(64),
  website: optText(200),
  city: optText(80),
  country: optText(80),
  sourceId: z.uuid().optional(),
  ownerId: z.uuid().optional(),
  comment: optText(4000),
  contact: contactSchema.optional(),
});
export type CreateClientInput = z.input<typeof createClientSchema>;

export const updateClientSchema = createClientSchema
  .omit({ contact: true, ownerId: true })
  .partial();
export type UpdateClientInput = z.input<typeof updateClientSchema>;

export const clientListQuerySchema = paginationQuerySchema.extend({
  q: z.string().trim().max(100).optional(),
  ownerId: z.uuid().optional(),
  teamId: z.uuid().optional(),
  type: z.enum(CLIENT_TYPES).optional(),
});
export type ClientListQuery = Partial<z.output<typeof clientListQuerySchema>>;

export interface ContactDto {
  id: string;
  fullName: string;
  position: string | null;
  phone: string | null;
  telegram: string | null;
  whatsapp: string | null;
  instagram: string | null;
  email: string | null;
  isPrimary: boolean;
}

export interface ClientDto {
  id: string;
  number: string;
  name: string;
  type: ClientType;
  industry: string | null;
  phone: string | null;
  email: string | null;
  telegram: string | null;
  website: string | null;
  city: string | null;
  country: string | null;
  owner: NamedRef;
  team: NamedRef | null;
  source: NamedRef | null;
  health: ClientHealth;
  comment: string | null;
  dealsCount: number;
  openDealsCount: number;
  createdAt: string;
}

export interface ClientDetailDto extends ClientDto {
  contacts: ContactDto[];
  deals: DealDto[];
}

// ─────────────────────────── Сделки ───────────────────────────

export const createDealSchema = z.object({
  clientId: z.uuid('Выберите клиента'),
  contactId: z.uuid().optional(),
  title: z.string().trim().min(1, 'Укажите название').max(200),
  serviceId: z.uuid().optional(),
  amount: moneySchema,
  currency: z.enum(CURRENCIES).default('UZS'),
  ownerId: z.uuid().optional(),
  expectedCloseDate: dateOnly.optional(),
  isRepeat: z.boolean().optional(),
});
export type CreateDealInput = z.input<typeof createDealSchema>;

export const updateDealSchema = z
  .object({
    title: z.string().trim().min(1).max(200),
    contactId: z.uuid().nullable(),
    serviceId: z.uuid().nullable(),
    amount: moneySchema,
    currency: z.enum(CURRENCIES),
    expectedCloseDate: dateOnly.nullable(),
    probabilityOverride: z.coerce.number().int().min(0).max(100).nullable(),
  })
  .partial();
export type UpdateDealInput = z.input<typeof updateDealSchema>;

export const dealListQuerySchema = paginationQuerySchema.extend({
  q: z.string().trim().max(100).optional(),
  stageCode: z.enum(DEAL_STAGE_CODES).optional(),
  status: z.enum(DEAL_STATUSES).optional(),
  ownerId: z.uuid().optional(),
  teamId: z.uuid().optional(),
  clientId: z.uuid().optional(),
  serviceId: z.uuid().optional(),
  amountMin: z.coerce.number().min(0).optional(),
  amountMax: z.coerce.number().min(0).optional(),
  dateFrom: dateOnly.optional(),
  dateTo: dateOnly.optional(),
});
export type DealListQuery = Partial<z.output<typeof dealListQuerySchema>>;

export interface DealDto {
  id: string;
  number: string;
  title: string;
  client: NamedRef;
  contact: NamedRef | null;
  owner: NamedRef;
  team: NamedRef | null;
  service: NamedRef | null;
  amount: string;
  currency: Currency;
  exchangeRate: string;
  amountUzs: string;
  stage: StageRef;
  status: DealStatus;
  probability: number;
  probabilityOverride: number | null;
  expectedCloseDate: string | null;
  isRepeat: boolean;
  leadId: string | null;
  lossReason: NamedRef | null;
  lossComment: string | null;
  wonAt: string | null;
  closedAt: string | null;
  createdAt: string;
  updatedAt: string;
}

// ─────────────────────────── Воронка ───────────────────────────

export const pipelineQuerySchema = z.object({
  ownerId: z.uuid().optional(),
  teamId: z.uuid().optional(),
  serviceId: z.uuid().optional(),
  sourceId: z.uuid().optional(),
});
export type PipelineQuery = z.output<typeof pipelineQuerySchema>;

export interface PipelineCard {
  id: string;
  kind: 'lead' | 'deal';
  number: string;
  title: string;
  subtitle: string | null;
  amount: string | null;
  currency: Currency;
  amountUzs: string | null;
  owner: NamedRef;
  scoreLevel: ScoreLevelCode | null;
  nextContactAt: string | null;
  updatedAt: string;
}

export interface PipelineColumn {
  stage: StageDto;
  items: PipelineCard[];
  count: number;
  totalUzs: string;
}

export interface PipelineDto {
  columns: PipelineColumn[];
  pipelineUzs: string;
  weightedUzs: string;
}

// ─────────────────────────── Встречи ───────────────────────────

export const createMeetingSchema = z
  .object({
    leadId: z.uuid().optional(),
    dealId: z.uuid().optional(),
    startsAt: dateTime,
    durationMin: z.coerce.number().int().min(5).max(600).default(60),
    type: z.enum(MEETING_TYPES),
    link: optText(500),
    comment: optText(2000),
  })
  .refine((v) => Boolean(v.leadId) !== Boolean(v.dealId), {
    path: ['leadId'],
    message: 'Встреча привязывается к лиду или к сделке',
  });
export type CreateMeetingInput = z.input<typeof createMeetingSchema>;

export const updateMeetingSchema = z
  .object({
    startsAt: dateTime,
    durationMin: z.coerce.number().int().min(5).max(600),
    type: z.enum(MEETING_TYPES),
    link: z.string().trim().max(500).nullable(),
    comment: z.string().trim().max(2000).nullable(),
    status: z.enum(['SCHEDULED', 'CONFIRMED', 'RESCHEDULED', 'CANCELLED', 'NO_SHOW']),
  })
  .partial();
export type UpdateMeetingInput = z.input<typeof updateMeetingSchema>;

export const completeMeetingSchema = z.object({
  result: z.string().trim().min(1, 'Опишите результат встречи').max(4000),
});
export type CompleteMeetingInput = z.input<typeof completeMeetingSchema>;

export const meetingListQuerySchema = paginationQuerySchema.extend({
  status: z.enum(MEETING_STATUSES).optional(),
  managerId: z.uuid().optional(),
  leadId: z.uuid().optional(),
  dealId: z.uuid().optional(),
  dateFrom: dateOnly.optional(),
  dateTo: dateOnly.optional(),
});
export type MeetingListQuery = Partial<z.output<typeof meetingListQuerySchema>>;

export interface MeetingDto {
  id: string;
  lead: (NamedRef & { number: string }) | null;
  deal: (NamedRef & { number: string }) | null;
  client: NamedRef | null;
  manager: NamedRef;
  rop: NamedRef | null;
  startsAt: string;
  durationMin: number;
  type: MeetingType;
  link: string | null;
  status: MeetingStatus;
  comment: string | null;
  result: string | null;
  createdAt: string;
}

// ─────────────────────── Таймлайн и комментарии ───────────────────────

export const timelineQuerySchema = z
  .object({
    leadId: z.uuid().optional(),
    dealId: z.uuid().optional(),
    clientId: z.uuid().optional(),
  })
  .refine(
    (v) => [v.leadId, v.dealId, v.clientId].filter(Boolean).length === 1,
    'Укажите одну запись',
  );
export type TimelineQuery = z.output<typeof timelineQuerySchema>;

export interface ActivityDto {
  id: string;
  type: string;
  actor: NamedRef | null;
  payload: Record<string, unknown> | null;
  createdAt: string;
}

export interface StageHistoryDto {
  id: string;
  from: StageRef | null;
  to: StageRef;
  changedBy: NamedRef;
  durationSec: number | null;
  createdAt: string;
}

export const createCommentSchema = z
  .object({
    leadId: z.uuid().optional(),
    dealId: z.uuid().optional(),
    clientId: z.uuid().optional(),
    body: z.string().trim().min(1, 'Напишите комментарий').max(4000),
  })
  .refine(
    (v) => [v.leadId, v.dealId, v.clientId].filter(Boolean).length === 1,
    'Укажите одну запись',
  );
export type CreateCommentInput = z.input<typeof createCommentSchema>;

export interface CommentDto {
  id: string;
  author: NamedRef;
  body: string;
  createdAt: string;
  canDelete: boolean;
}

// ─────────────────────────── Уведомления ───────────────────────────

export interface NotificationDto {
  id: string;
  type: string;
  title: string;
  body: string | null;
  link: string | null;
  readAt: string | null;
  createdAt: string;
}

export type { CloseStatus };

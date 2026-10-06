export const ROLE_CODES = ['CEO', 'ROP', 'MANAGER', 'EXECUTOR', 'HR_ADMIN'] as const;
export type RoleCode = (typeof ROLE_CODES)[number];

/** Область видимости права: свои записи, записи своего отдела, все записи. */
export const SCOPES = ['OWN', 'TEAM', 'ALL'] as const;
export type Scope = (typeof SCOPES)[number];

export const USER_STATUSES = ['ACTIVE', 'BLOCKED'] as const;
export type UserStatus = (typeof USER_STATUSES)[number];

export const EXECUTOR_SPECIALTIES = [
  'SMM',
  'DESIGNER',
  'VIDEOGRAPHER',
  'EDITOR',
  'TARGETOLOGIST',
  'DEVELOPER',
  'PHOTOGRAPHER',
  'COPYWRITER',
] as const;
export type ExecutorSpecialty = (typeof EXECUTOR_SPECIALTIES)[number];

export const LOCALES = ['ru', 'uz', 'en'] as const;
export type Locale = (typeof LOCALES)[number];
export const DEFAULT_LOCALE: Locale = 'ru';

export const CURRENCIES = ['UZS', 'USD'] as const;
export type Currency = (typeof CURRENCIES)[number];
export const BASE_CURRENCY: Currency = 'UZS';

/** Компания работает в Ташкенте (UTC+5, без перехода на летнее время). */
export const COMPANY_TIMEZONE = 'Asia/Tashkent';

// ─── CRM (Phase 2) ───

export const PRIORITIES = ['LOW', 'MEDIUM', 'HIGH', 'URGENT'] as const;
export type Priority = (typeof PRIORITIES)[number];

export const COMPANY_SIZES = ['SOLO', 'SMALL', 'MEDIUM', 'LARGE'] as const;
export type CompanySize = (typeof COMPANY_SIZES)[number];

export const SCORE_LEVELS = ['LOW', 'MEDIUM', 'HIGH', 'HOT'] as const;
export type ScoreLevelCode = (typeof SCORE_LEVELS)[number];

export const LEAD_STATUSES = [
  'OPEN',
  'CONVERTED',
  'LOST',
  'REJECTED',
  'PAUSED',
  'NO_RESPONSE',
] as const;
export type LeadStatus = (typeof LEAD_STATUSES)[number];

export const DEAL_STATUSES = ['OPEN', 'WON', 'LOST', 'REJECTED', 'PAUSED', 'NO_RESPONSE'] as const;
export type DealStatus = (typeof DEAL_STATUSES)[number];

/** Финальные статусы, в которые запись переводится вручную (ТЗ §7). */
export const CLOSE_STATUSES = ['LOST', 'REJECTED', 'PAUSED', 'NO_RESPONSE'] as const;
export type CloseStatus = (typeof CLOSE_STATUSES)[number];
/** Для этих статусов причина обязательна (ТЗ §39). */
export const REASON_REQUIRED_STATUSES: readonly CloseStatus[] = ['LOST', 'REJECTED'];

export const LEAD_STAGE_CODES = [
  'NEW',
  'CONTACTED',
  'QUALIFICATION',
  'MEETING_SCHEDULED',
  'MEETING_DONE',
] as const;
export type LeadStageCode = (typeof LEAD_STAGE_CODES)[number];

export const DEAL_STAGE_CODES = [
  'NEED_DEFINED',
  'PROPOSAL_SENT',
  'NEGOTIATION',
  'CONTRACT',
  'AWAITING_PAYMENT',
  'PAID',
] as const;
export type DealStageCode = (typeof DEAL_STAGE_CODES)[number];

export const CLIENT_TYPES = ['COMPANY', 'PERSON'] as const;
export type ClientType = (typeof CLIENT_TYPES)[number];

export const CLIENT_HEALTH = ['HEALTHY', 'ATTENTION', 'RISK', 'LOST'] as const;
export type ClientHealth = (typeof CLIENT_HEALTH)[number];

export const MEETING_TYPES = [
  'ONLINE',
  'OFFLINE',
  'PHONE',
  'TELEGRAM',
  'GOOGLE_MEET',
  'ZOOM',
] as const;
export type MeetingType = (typeof MEETING_TYPES)[number];

export const MEETING_STATUSES = [
  'SCHEDULED',
  'CONFIRMED',
  'DONE',
  'RESCHEDULED',
  'CANCELLED',
  'NO_SHOW',
] as const;
export type MeetingStatus = (typeof MEETING_STATUSES)[number];

export const PRICING_TYPES = ['FIXED', 'MONTHLY', 'HOURLY', 'CUSTOM'] as const;
export type PricingType = (typeof PRICING_TYPES)[number];

/** Человекочитаемые номера: L-00001, C-00001, D-00001. */
export const formatNumber = (prefix: string, n: number) =>
  `${prefix}-${String(n).padStart(5, '0')}`;

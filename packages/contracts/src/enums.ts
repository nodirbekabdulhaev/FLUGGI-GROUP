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
  'MOBILOGRAPHER',
  'BRANDFACE',
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

// ─── Продажи (Phase 3) ───

export const PROPOSAL_STATUSES = [
  'DRAFT',
  'SENT',
  'VIEWED',
  'IN_APPROVAL',
  'ACCEPTED',
  'REJECTED',
  'EXPIRED',
] as const;
export type ProposalStatus = (typeof PROPOSAL_STATUSES)[number];

export const CONTRACT_STATUSES = ['DRAFT', 'SENT', 'IN_APPROVAL', 'SIGNED', 'CANCELLED'] as const;
export type ContractStatus = (typeof CONTRACT_STATUSES)[number];

export const PAYMENT_TYPES = ['PREPAYMENT', 'PARTIAL', 'FULL', 'FINAL', 'REFUND'] as const;
export type PaymentType = (typeof PAYMENT_TYPES)[number];

export const PAYMENT_METHODS = ['CASH', 'BANK', 'CARD', 'TRANSFER', 'OTHER'] as const;
export type PaymentMethod = (typeof PAYMENT_METHODS)[number];

export const PAYMENT_STATUSES = ['PENDING', 'PAID', 'CANCELLED'] as const;
export type PaymentStatus = (typeof PAYMENT_STATUSES)[number];

export const PROJECT_STATUSES = [
  'NEW',
  'PLANNING',
  'IN_PROGRESS',
  'REVIEW',
  'WAITING_CLIENT',
  'PAUSED',
  'COMPLETED',
  'CANCELLED',
] as const;
export type ProjectStatus = (typeof PROJECT_STATUSES)[number];

export const ACTIVE_PROJECT_STATUSES: readonly ProjectStatus[] = [
  'NEW',
  'PLANNING',
  'IN_PROGRESS',
  'REVIEW',
  'WAITING_CLIENT',
  'PAUSED',
];

export const TASK_STATUSES = [
  'TODO',
  'IN_PROGRESS',
  'REVIEW',
  'DONE',
  'BLOCKED',
  'CANCELLED',
] as const;
export type TaskStatus = (typeof TASK_STATUSES)[number];
/** Колонки Kanban (ТЗ §23). */
export const KANBAN_STATUSES = ['TODO', 'IN_PROGRESS', 'REVIEW', 'DONE'] as const;
/** Задача «открыта» — может стать просроченной (ТЗ §24). */
export const OPEN_TASK_STATUSES: readonly TaskStatus[] = [
  'TODO',
  'IN_PROGRESS',
  'REVIEW',
  'BLOCKED',
];

export const PROJECT_MEMBER_STATUSES = ['ACTIVE', 'DONE', 'REMOVED'] as const;
export type ProjectMemberStatus = (typeof PROJECT_MEMBER_STATUSES)[number];

export const KPI_METRICS = ['REVENUE', 'ORDERS', 'LEADS', 'MEETINGS', 'TASKS'] as const;
export type KpiMetric = (typeof KPI_METRICS)[number];

export const ATTENDANCE_STATUSES = [
  'PRESENT',
  'LATE',
  'ABSENT',
  'DAY_OFF',
  'VACATION',
  'SICK',
] as const;
export type AttendanceStatus = (typeof ATTENDANCE_STATUSES)[number];

export const PAYROLL_STATUSES = ['DRAFT', 'APPROVED', 'PAID'] as const;
export type PayrollStatus = (typeof PAYROLL_STATUSES)[number];

export const EXPENSE_SCOPES = ['PROJECT', 'COMPANY'] as const;
export type ExpenseScope = (typeof EXPENSE_SCOPES)[number];

/** Код категории из справочника finance_categories (редактирует CEO). */
export type ExpenseCategory = string;

export const COMMISSION_STATUSES = ['ACCRUED', 'APPROVED', 'PAID', 'CANCELLED'] as const;
export type CommissionStatus = (typeof COMMISSION_STATUSES)[number];

export const COMMISSION_CALC_TYPES = [
  'PERCENT_OF_PAYMENT',
  'PERCENT_OF_PROFIT',
  'FIXED_PER_DEAL',
] as const;
export type CommissionCalcType = (typeof COMMISSION_CALC_TYPES)[number];

export const FILE_CATEGORIES = [
  'PROPOSAL',
  'CONTRACT',
  'PAYMENT',
  'PHOTO',
  'VIDEO',
  'DESIGN',
  'DOCUMENT',
  'OTHER',
] as const;
export type FileCategory = (typeof FILE_CATEGORIES)[number];

/** Разрешённые типы файлов и лимит (ТЗ §46: secure file upload). */
export const ALLOWED_FILE_TYPES: Record<string, string[]> = {
  'application/pdf': ['pdf'],
  'image/png': ['png'],
  'image/jpeg': ['jpg', 'jpeg'],
  'image/webp': ['webp'],
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document': ['docx'],
  'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': ['xlsx'],
  'application/zip': ['zip'],
};
export const MAX_FILE_BYTES = 25 * 1024 * 1024;

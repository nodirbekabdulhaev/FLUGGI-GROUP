export declare const ROLE_CODES: readonly ["CEO", "ROP", "MANAGER", "EXECUTOR", "HR_ADMIN", "PROJECT_MANAGER"];
export type RoleCode = (typeof ROLE_CODES)[number];
/** Область видимости права: свои записи, записи своего отдела, все записи. */
export declare const SCOPES: readonly ["OWN", "TEAM", "ALL"];
export type Scope = (typeof SCOPES)[number];
export declare const USER_STATUSES: readonly ["ACTIVE", "BLOCKED"];
export type UserStatus = (typeof USER_STATUSES)[number];
export declare const EXECUTOR_SPECIALTIES: readonly ["SMM", "DESIGNER", "VIDEOGRAPHER", "EDITOR", "TARGETOLOGIST", "DEVELOPER", "PHOTOGRAPHER", "COPYWRITER", "MOBILOGRAPHER", "BRANDFACE"];
export type ExecutorSpecialty = (typeof EXECUTOR_SPECIALTIES)[number];
export declare const LOCALES: readonly ["ru", "uz", "en"];
export type Locale = (typeof LOCALES)[number];
export declare const DEFAULT_LOCALE: Locale;
export declare const CURRENCIES: readonly ["UZS", "USD"];
export type Currency = (typeof CURRENCIES)[number];
export declare const BASE_CURRENCY: Currency;
/** Компания работает в Ташкенте (UTC+5, без перехода на летнее время). */
export declare const COMPANY_TIMEZONE = "Asia/Tashkent";
export declare const PRIORITIES: readonly ["LOW", "MEDIUM", "HIGH", "URGENT"];
export type Priority = (typeof PRIORITIES)[number];
export declare const COMPANY_SIZES: readonly ["SOLO", "SMALL", "MEDIUM", "LARGE"];
export type CompanySize = (typeof COMPANY_SIZES)[number];
export declare const SCORE_LEVELS: readonly ["LOW", "MEDIUM", "HIGH", "HOT"];
export type ScoreLevelCode = (typeof SCORE_LEVELS)[number];
export declare const LEAD_STATUSES: readonly ["OPEN", "CONVERTED", "LOST", "REJECTED", "PAUSED", "NO_RESPONSE"];
export type LeadStatus = (typeof LEAD_STATUSES)[number];
export declare const DEAL_STATUSES: readonly ["OPEN", "WON", "LOST", "REJECTED", "PAUSED", "NO_RESPONSE"];
export type DealStatus = (typeof DEAL_STATUSES)[number];
/** Финальные статусы, в которые запись переводится вручную (ТЗ §7). */
export declare const CLOSE_STATUSES: readonly ["LOST", "REJECTED", "PAUSED", "NO_RESPONSE"];
export type CloseStatus = (typeof CLOSE_STATUSES)[number];
/** Для этих статусов причина обязательна (ТЗ §39). */
export declare const REASON_REQUIRED_STATUSES: readonly CloseStatus[];
export declare const LEAD_STAGE_CODES: readonly ["NEW", "CONTACTED", "QUALIFICATION", "MEETING_SCHEDULED", "MEETING_DONE"];
export type LeadStageCode = (typeof LEAD_STAGE_CODES)[number];
export declare const DEAL_STAGE_CODES: readonly ["NEED_DEFINED", "PROPOSAL_SENT", "NEGOTIATION", "CONTRACT", "AWAITING_PAYMENT", "PAID"];
export type DealStageCode = (typeof DEAL_STAGE_CODES)[number];
export declare const CLIENT_TYPES: readonly ["COMPANY", "PERSON"];
export type ClientType = (typeof CLIENT_TYPES)[number];
export declare const CLIENT_HEALTH: readonly ["HEALTHY", "ATTENTION", "RISK", "LOST"];
export type ClientHealth = (typeof CLIENT_HEALTH)[number];
export declare const MEETING_TYPES: readonly ["ONLINE", "OFFLINE", "PHONE", "TELEGRAM", "GOOGLE_MEET", "ZOOM"];
export type MeetingType = (typeof MEETING_TYPES)[number];
export declare const MEETING_STATUSES: readonly ["SCHEDULED", "CONFIRMED", "DONE", "RESCHEDULED", "CANCELLED", "NO_SHOW"];
export type MeetingStatus = (typeof MEETING_STATUSES)[number];
export declare const PRICING_TYPES: readonly ["FIXED", "MONTHLY", "HOURLY", "CUSTOM"];
export type PricingType = (typeof PRICING_TYPES)[number];
/** Человекочитаемые номера: L-00001, C-00001, D-00001. */
export declare const formatNumber: (prefix: string, n: number) => string;
export declare const PROPOSAL_STATUSES: readonly ["DRAFT", "SENT", "VIEWED", "IN_APPROVAL", "ACCEPTED", "REJECTED", "EXPIRED"];
export type ProposalStatus = (typeof PROPOSAL_STATUSES)[number];
export declare const CONTRACT_STATUSES: readonly ["DRAFT", "SENT", "IN_APPROVAL", "SIGNED", "CANCELLED"];
export type ContractStatus = (typeof CONTRACT_STATUSES)[number];
export declare const PAYMENT_TYPES: readonly ["PREPAYMENT", "PARTIAL", "FULL", "FINAL", "REFUND"];
export type PaymentType = (typeof PAYMENT_TYPES)[number];
export declare const PAYMENT_METHODS: readonly ["CASH", "BANK", "CARD", "TRANSFER", "OTHER"];
export type PaymentMethod = (typeof PAYMENT_METHODS)[number];
export declare const PAYMENT_STATUSES: readonly ["PENDING", "PAID", "CANCELLED"];
export type PaymentStatus = (typeof PAYMENT_STATUSES)[number];
export declare const PROJECT_STATUSES: readonly ["NEW", "PLANNING", "IN_PROGRESS", "REVIEW", "WAITING_CLIENT", "PAUSED", "COMPLETED", "CANCELLED"];
export type ProjectStatus = (typeof PROJECT_STATUSES)[number];
export declare const ACTIVE_PROJECT_STATUSES: readonly ProjectStatus[];
export declare const TASK_STATUSES: readonly ["TODO", "IN_PROGRESS", "REVIEW", "DONE", "BLOCKED", "CANCELLED"];
export type TaskStatus = (typeof TASK_STATUSES)[number];
/** Колонки Kanban (ТЗ §23). */
export declare const KANBAN_STATUSES: readonly ["TODO", "IN_PROGRESS", "REVIEW", "DONE"];
/** Задача «открыта» — может стать просроченной (ТЗ §24). */
export declare const OPEN_TASK_STATUSES: readonly TaskStatus[];
export declare const PROJECT_MEMBER_STATUSES: readonly ["ACTIVE", "DONE", "REMOVED"];
export type ProjectMemberStatus = (typeof PROJECT_MEMBER_STATUSES)[number];
export declare const KPI_METRICS: readonly ["REVENUE", "ORDERS", "LEADS", "MEETINGS", "TASKS"];
export type KpiMetric = (typeof KPI_METRICS)[number];
export declare const ATTENDANCE_STATUSES: readonly ["PRESENT", "LATE", "ABSENT", "DAY_OFF", "VACATION", "SICK"];
export type AttendanceStatus = (typeof ATTENDANCE_STATUSES)[number];
export declare const PAYROLL_STATUSES: readonly ["DRAFT", "APPROVED", "PAID"];
export type PayrollStatus = (typeof PAYROLL_STATUSES)[number];
export declare const EXPENSE_SCOPES: readonly ["PROJECT", "COMPANY"];
export type ExpenseScope = (typeof EXPENSE_SCOPES)[number];
/** Код категории из справочника finance_categories (редактирует CEO). */
export type ExpenseCategory = string;
export declare const COMMISSION_STATUSES: readonly ["ACCRUED", "APPROVED", "PAID", "CANCELLED"];
export type CommissionStatus = (typeof COMMISSION_STATUSES)[number];
export declare const COMMISSION_CALC_TYPES: readonly ["PERCENT_OF_PAYMENT", "PERCENT_OF_PROFIT", "FIXED_PER_DEAL"];
export type CommissionCalcType = (typeof COMMISSION_CALC_TYPES)[number];
export declare const FILE_CATEGORIES: readonly ["PROPOSAL", "CONTRACT", "PAYMENT", "PHOTO", "VIDEO", "DESIGN", "DOCUMENT", "OTHER"];
export type FileCategory = (typeof FILE_CATEGORIES)[number];
/** Разрешённые типы файлов и лимит (ТЗ §46: secure file upload). */
export declare const ALLOWED_FILE_TYPES: Record<string, string[]>;
export declare const MAX_FILE_BYTES: number;

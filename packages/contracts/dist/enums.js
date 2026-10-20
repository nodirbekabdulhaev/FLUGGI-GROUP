"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.MAX_FILE_BYTES = exports.ALLOWED_FILE_TYPES = exports.FILE_CATEGORIES = exports.COMMISSION_CALC_TYPES = exports.COMMISSION_STATUSES = exports.EXPENSE_SCOPES = exports.PAYROLL_STATUSES = exports.ATTENDANCE_STATUSES = exports.KPI_METRICS = exports.PROJECT_MEMBER_STATUSES = exports.OPEN_TASK_STATUSES = exports.KANBAN_STATUSES = exports.TASK_STATUSES = exports.ACTIVE_PROJECT_STATUSES = exports.PROJECT_STATUSES = exports.PAYMENT_STATUSES = exports.PAYMENT_METHODS = exports.PAYMENT_TYPES = exports.CONTRACT_STATUSES = exports.PROPOSAL_STATUSES = exports.formatNumber = exports.PRICING_TYPES = exports.MEETING_STATUSES = exports.MEETING_TYPES = exports.CLIENT_HEALTH = exports.CLIENT_TYPES = exports.DEAL_STAGE_CODES = exports.LEAD_STAGE_CODES = exports.REASON_REQUIRED_STATUSES = exports.CLOSE_STATUSES = exports.DEAL_STATUSES = exports.LEAD_STATUSES = exports.SCORE_LEVELS = exports.COMPANY_SIZES = exports.PRIORITIES = exports.COMPANY_TIMEZONE = exports.BASE_CURRENCY = exports.CURRENCIES = exports.DEFAULT_LOCALE = exports.LOCALES = exports.EXECUTOR_SPECIALTIES = exports.USER_STATUSES = exports.SCOPES = exports.ROLE_CODES = void 0;
exports.ROLE_CODES = [
    'CEO',
    'ROP',
    'MANAGER',
    'EXECUTOR',
    'HR_ADMIN',
    'PROJECT_MANAGER',
];
/** Область видимости права: свои записи, записи своего отдела, все записи. */
exports.SCOPES = ['OWN', 'TEAM', 'ALL'];
exports.USER_STATUSES = ['ACTIVE', 'BLOCKED'];
exports.EXECUTOR_SPECIALTIES = [
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
];
exports.LOCALES = ['ru', 'uz', 'en'];
exports.DEFAULT_LOCALE = 'ru';
exports.CURRENCIES = ['UZS', 'USD'];
exports.BASE_CURRENCY = 'UZS';
/** Компания работает в Ташкенте (UTC+5, без перехода на летнее время). */
exports.COMPANY_TIMEZONE = 'Asia/Tashkent';
// ─── CRM (Phase 2) ───
exports.PRIORITIES = ['LOW', 'MEDIUM', 'HIGH', 'URGENT'];
exports.COMPANY_SIZES = ['SOLO', 'SMALL', 'MEDIUM', 'LARGE'];
exports.SCORE_LEVELS = ['LOW', 'MEDIUM', 'HIGH', 'HOT'];
exports.LEAD_STATUSES = [
    'OPEN',
    'CONVERTED',
    'LOST',
    'REJECTED',
    'PAUSED',
    'NO_RESPONSE',
];
exports.DEAL_STATUSES = ['OPEN', 'WON', 'LOST', 'REJECTED', 'PAUSED', 'NO_RESPONSE'];
/** Финальные статусы, в которые запись переводится вручную (ТЗ §7). */
exports.CLOSE_STATUSES = ['LOST', 'REJECTED', 'PAUSED', 'NO_RESPONSE'];
/** Для этих статусов причина обязательна (ТЗ §39). */
exports.REASON_REQUIRED_STATUSES = ['LOST', 'REJECTED'];
exports.LEAD_STAGE_CODES = [
    'NEW',
    'CONTACTED',
    'QUALIFICATION',
    'MEETING_SCHEDULED',
    'MEETING_DONE',
];
exports.DEAL_STAGE_CODES = [
    'NEED_DEFINED',
    'PROPOSAL_SENT',
    'NEGOTIATION',
    'CONTRACT',
    'AWAITING_PAYMENT',
    'PAID',
];
exports.CLIENT_TYPES = ['COMPANY', 'PERSON'];
exports.CLIENT_HEALTH = ['HEALTHY', 'ATTENTION', 'RISK', 'LOST'];
exports.MEETING_TYPES = [
    'ONLINE',
    'OFFLINE',
    'PHONE',
    'TELEGRAM',
    'GOOGLE_MEET',
    'ZOOM',
];
exports.MEETING_STATUSES = [
    'SCHEDULED',
    'CONFIRMED',
    'DONE',
    'RESCHEDULED',
    'CANCELLED',
    'NO_SHOW',
];
exports.PRICING_TYPES = ['FIXED', 'MONTHLY', 'HOURLY', 'CUSTOM'];
/** Человекочитаемые номера: L-00001, C-00001, D-00001. */
const formatNumber = (prefix, n) => `${prefix}-${String(n).padStart(5, '0')}`;
exports.formatNumber = formatNumber;
// ─── Продажи (Phase 3) ───
exports.PROPOSAL_STATUSES = [
    'DRAFT',
    'SENT',
    'VIEWED',
    'IN_APPROVAL',
    'ACCEPTED',
    'REJECTED',
    'EXPIRED',
];
exports.CONTRACT_STATUSES = ['DRAFT', 'SENT', 'IN_APPROVAL', 'SIGNED', 'CANCELLED'];
exports.PAYMENT_TYPES = ['PREPAYMENT', 'PARTIAL', 'FULL', 'FINAL', 'REFUND'];
exports.PAYMENT_METHODS = ['CASH', 'BANK', 'CARD', 'TRANSFER', 'OTHER'];
exports.PAYMENT_STATUSES = ['PENDING', 'PAID', 'CANCELLED'];
exports.PROJECT_STATUSES = [
    'NEW',
    'PLANNING',
    'IN_PROGRESS',
    'REVIEW',
    'WAITING_CLIENT',
    'PAUSED',
    'COMPLETED',
    'CANCELLED',
];
exports.ACTIVE_PROJECT_STATUSES = [
    'NEW',
    'PLANNING',
    'IN_PROGRESS',
    'REVIEW',
    'WAITING_CLIENT',
    'PAUSED',
];
exports.TASK_STATUSES = [
    'TODO',
    'IN_PROGRESS',
    'REVIEW',
    'DONE',
    'BLOCKED',
    'CANCELLED',
];
/** Колонки Kanban (ТЗ §23). */
exports.KANBAN_STATUSES = ['TODO', 'IN_PROGRESS', 'REVIEW', 'DONE'];
/** Задача «открыта» — может стать просроченной (ТЗ §24). */
exports.OPEN_TASK_STATUSES = [
    'TODO',
    'IN_PROGRESS',
    'REVIEW',
    'BLOCKED',
];
exports.PROJECT_MEMBER_STATUSES = ['ACTIVE', 'DONE', 'REMOVED'];
exports.KPI_METRICS = ['REVENUE', 'ORDERS', 'LEADS', 'MEETINGS', 'TASKS'];
exports.ATTENDANCE_STATUSES = [
    'PRESENT',
    'LATE',
    'ABSENT',
    'DAY_OFF',
    'VACATION',
    'SICK',
];
exports.PAYROLL_STATUSES = ['DRAFT', 'APPROVED', 'PAID'];
exports.EXPENSE_SCOPES = ['PROJECT', 'COMPANY'];
exports.COMMISSION_STATUSES = ['ACCRUED', 'APPROVED', 'PAID', 'CANCELLED'];
exports.COMMISSION_CALC_TYPES = [
    'PERCENT_OF_PAYMENT',
    'PERCENT_OF_PROFIT',
    'FIXED_PER_DEAL',
];
exports.FILE_CATEGORIES = [
    'PROPOSAL',
    'CONTRACT',
    'PAYMENT',
    'PHOTO',
    'VIDEO',
    'DESIGN',
    'DOCUMENT',
    'OTHER',
];
/** Разрешённые типы файлов и лимит (ТЗ §46: secure file upload). */
exports.ALLOWED_FILE_TYPES = {
    'application/pdf': ['pdf'],
    'image/png': ['png'],
    'image/jpeg': ['jpg', 'jpeg'],
    'image/webp': ['webp'],
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document': ['docx'],
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': ['xlsx'],
    'application/zip': ['zip'],
};
exports.MAX_FILE_BYTES = 25 * 1024 * 1024;
//# sourceMappingURL=enums.js.map
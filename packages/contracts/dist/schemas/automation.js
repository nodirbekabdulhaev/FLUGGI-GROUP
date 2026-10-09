"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.DEFAULT_COMPANY_SETTINGS = exports.companySettingsSchema = exports.TAX_REGIME_LABELS = exports.TAX_REGIMES = exports.DEFAULT_AUTOMATION_SETTINGS = exports.automationSettingsSchema = exports.completeFollowUpSchema = exports.followUpListQuerySchema = exports.FOLLOW_UP_STATUSES = exports.FOLLOW_UP_KINDS = exports.notificationSettingsSchema = exports.NOTIFICATION_TYPE_GROUP = exports.NOTIFICATION_EVENTS = exports.NOTIFICATION_CHANNELS = void 0;
const zod_1 = require("zod");
// ─────────────────────────── Каталог уведомлений (ТЗ §14) ───────────────────────────
exports.NOTIFICATION_CHANNELS = ['IN_APP', 'TELEGRAM'];
/** Типы уведомлений и роли, которым они приходят. Используется в настройках пользователя. */
exports.NOTIFICATION_EVENTS = [
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
    {
        type: 'social.message',
        label: 'Instagram: Директ и комментарии',
        roles: ['CEO', 'ROP', 'MANAGER'],
    },
    {
        type: 'lead.inbound_repeat',
        label: 'Повторная заявка клиента',
        roles: ['CEO', 'ROP', 'MANAGER'],
    },
    { type: 'report.daily', label: 'Ежедневный отчёт', roles: ['CEO', 'ROP'] },
    { type: 'report.weekly', label: 'Еженедельный отчёт', roles: ['CEO'] },
    { type: 'payroll.calculated', label: 'Предварительный расчёт зарплаты', roles: ['CEO'] },
];
/** Близкие типы уведомлений настраиваются одним переключателем. */
exports.NOTIFICATION_TYPE_GROUP = {
    'task.accepted': 'task.returned',
    'project.cancelled': 'project.completed',
    'todo.recurring': 'todo.due',
    'todo.overdue': 'todo.due',
};
exports.notificationSettingsSchema = zod_1.z.object({
    settings: zod_1.z
        .array(zod_1.z.object({
        eventType: zod_1.z.string().min(1).max(60),
        channel: zod_1.z.enum(exports.NOTIFICATION_CHANNELS),
        enabled: zod_1.z.boolean(),
    }))
        .max(200),
});
// ─────────────────────────── Follow-up (ТЗ §38) ───────────────────────────
exports.FOLLOW_UP_KINDS = ['CONTACT', 'NEW_PROJECT', 'REPEAT_SALE'];
exports.FOLLOW_UP_STATUSES = ['PENDING', 'DONE', 'SKIPPED'];
exports.followUpListQuerySchema = zod_1.z.object({
    status: zod_1.z.enum(exports.FOLLOW_UP_STATUSES).optional(),
    /** Только просроченные и на сегодня */
    due: zod_1.z
        .enum(['true', 'false'])
        .optional()
        .transform((v) => v === 'true'),
    clientId: zod_1.z.uuid().optional(),
    page: zod_1.z.coerce.number().int().min(1).default(1),
    pageSize: zod_1.z.coerce.number().int().min(1).max(100).default(25),
});
exports.completeFollowUpSchema = zod_1.z.object({
    status: zod_1.z.enum(['DONE', 'SKIPPED']),
    result: zod_1.z.string().trim().max(2000).optional(),
    /** Создать сделку «Повторная продажа» у клиента */
    createDeal: zod_1.z.boolean().default(false),
});
// ─────────────────────────── Настройки автоматизации ───────────────────────────
exports.automationSettingsSchema = zod_1.z.object({
    /** Порог «крупного» лида/сделки, UZS (ТЗ §14) */
    largeAmountUzs: zod_1.z.coerce.number().int().min(0).max(1e13),
    /** Интервалы follow-up после завершения проекта, дней (ТЗ §38) */
    followUps: zod_1.z
        .array(zod_1.z.object({ days: zod_1.z.coerce.number().int().min(1).max(730), kind: zod_1.z.enum(exports.FOLLOW_UP_KINDS) }))
        .max(10),
    /** Включены ли отчёты руководителю */
    dailyReport: zod_1.z.boolean(),
    weeklyReport: zod_1.z.boolean(),
});
exports.DEFAULT_AUTOMATION_SETTINGS = {
    largeAmountUzs: 50_000_000,
    followUps: [
        { days: 30, kind: 'CONTACT' },
        { days: 60, kind: 'NEW_PROJECT' },
        { days: 90, kind: 'REPEAT_SALE' },
    ],
    dailyReport: true,
    weeklyReport: true,
};
// ─────────────────────────── Реквизиты компании (для бухгалтера) ───────────────────────────
exports.TAX_REGIMES = ['IT_PARK', 'TURNOVER', 'GENERAL', 'OTHER'];
exports.TAX_REGIME_LABELS = {
    IT_PARK: 'Резидент IT-Park',
    TURNOVER: 'Налог с оборота',
    GENERAL: 'Общий режим (НДС и налог на прибыль)',
    OTHER: 'Другой',
};
exports.companySettingsSchema = zod_1.z.object({
    name: zod_1.z.string().trim().max(200),
    /** ИНН (СТИР) — 9 цифр */
    inn: zod_1.z
        .string()
        .trim()
        .regex(/^(\d{9})?$/, 'ИНН — 9 цифр'),
    director: zod_1.z.string().trim().max(120),
    accountant: zod_1.z.string().trim().max(120),
    taxRegime: zod_1.z.enum(exports.TAX_REGIMES),
    // Реквизиты для КП и договоров (подставляются в документы автоматически)
    legalName: zod_1.z.string().trim().max(300).default(''),
    directorPosition: zod_1.z.string().trim().max(120).default('Директор'),
    /** «в лице …» — родительный падеж: «директора Иванова Ивана Ивановича» */
    signerGenitive: zod_1.z.string().trim().max(200).default(''),
    basis: zod_1.z.string().trim().max(200).default('Устава'),
    address: zod_1.z.string().trim().max(300).default(''),
    phone: zod_1.z.string().trim().max(60).default(''),
    email: zod_1.z.string().trim().max(120).default(''),
    website: zod_1.z.string().trim().max(120).default(''),
    bank: zod_1.z.string().trim().max(200).default(''),
    /** МФО банка — 5 цифр */
    mfo: zod_1.z
        .string()
        .trim()
        .regex(/^(\d{5})?$/, 'МФО — 5 цифр')
        .default(''),
    /** Расчётный счёт — 20 цифр */
    account: zod_1.z
        .string()
        .trim()
        .regex(/^(\d{20})?$/, 'Расчётный счёт — 20 цифр')
        .default(''),
    oked: zod_1.z.string().trim().max(10).default(''),
    vatCode: zod_1.z.string().trim().max(20).default(''),
});
exports.DEFAULT_COMPANY_SETTINGS = {
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
//# sourceMappingURL=automation.js.map
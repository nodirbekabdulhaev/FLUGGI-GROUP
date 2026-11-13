"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.chatMessagesQuerySchema = exports.directChatSchema = exports.chatMessageSchema = exports.TAX_CALENDAR = exports.recurringTodoSchema = exports.RECURRENCE_FREQUENCIES = exports.todoListQuerySchema = exports.updateTodoSchema = exports.createTodoSchema = exports.TODO_STATUSES = exports.TODO_KINDS = void 0;
const zod_1 = require("zod");
const enums_1 = require("../enums");
const fields_1 = require("./fields");
// ─────────────────────────── Личные дела («Список дел») ───────────────────────────
exports.TODO_KINDS = ['TASK', 'CALL', 'EMAIL', 'MEETING', 'PAYMENT', 'REPORT'];
exports.TODO_STATUSES = ['OPEN', 'DONE', 'CANCELLED'];
const text = (max) => zod_1.z.string().trim().max(max);
exports.createTodoSchema = zod_1.z.object({
    title: text(300).min(1, 'Введите название'),
    description: text(4000).nullish(),
    kind: zod_1.z.enum(exports.TODO_KINDS).default('TASK'),
    priority: zod_1.z.enum(enums_1.PRIORITIES).default('MEDIUM'),
    dueAt: fields_1.dateTime.nullish(),
    /** Исполнитель; по умолчанию — я */
    ownerId: zod_1.z.uuid().optional(),
    clientId: zod_1.z.uuid().nullish(),
    dealId: zod_1.z.uuid().nullish(),
    leadId: zod_1.z.uuid().nullish(),
});
exports.updateTodoSchema = exports.createTodoSchema.partial().extend({
    title: text(300).min(1).optional(),
});
exports.todoListQuerySchema = zod_1.z.object({
    /** mine — мои; assigned — поручил я другим; all — все (CEO — компания, РОП — свой отдел) */
    view: zod_1.z.enum(['mine', 'assigned', 'all']).default('mine'),
    status: zod_1.z.enum(exports.TODO_STATUSES).optional(),
    clientId: zod_1.z.uuid().optional(),
    dealId: zod_1.z.uuid().optional(),
    page: zod_1.z.coerce.number().int().min(1).default(1),
    pageSize: zod_1.z.coerce.number().int().min(1).max(200).default(50),
});
// ─────────────────────────── Регулярные дела ───────────────────────────
exports.RECURRENCE_FREQUENCIES = ['MONTHLY', 'QUARTERLY', 'YEARLY'];
exports.recurringTodoSchema = zod_1.z
    .object({
    title: text(300).min(1, 'Введите название'),
    description: text(4000).nullish(),
    kind: zod_1.z.enum(exports.TODO_KINDS).default('REPORT'),
    priority: zod_1.z.enum(enums_1.PRIORITIES).default('HIGH'),
    ownerId: zod_1.z.uuid().optional(),
    frequency: zod_1.z.enum(exports.RECURRENCE_FREQUENCIES),
    dayOfMonth: zod_1.z.coerce.number().int().min(1).max(28),
    month: zod_1.z.coerce.number().int().min(1).max(12).nullish(),
    remindDaysBefore: zod_1.z.coerce.number().int().min(0).max(30).default(3),
    isActive: zod_1.z.boolean().default(true),
})
    .refine((v) => v.frequency !== 'QUARTERLY' || !v.month || v.month <= 3, {
    path: ['month'],
    message: 'Для квартала — месяц квартала 1–3',
});
/**
 * Налоговый календарь резидента IT-Park (по списку CEO). Названия — шаблоны:
 * {прошлый_месяц}, {прошлый_квартал}, {прошлый_год}, {год} подставляются от срока.
 */
exports.TAX_CALENDAR = [
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
exports.chatMessageSchema = zod_1.z.object({ body: zod_1.z.string().trim().min(1).max(4000) });
exports.directChatSchema = zod_1.z.object({ userId: zod_1.z.uuid() });
exports.chatMessagesQuerySchema = zod_1.z.object({
    before: fields_1.dateTime.optional(),
    limit: zod_1.z.coerce.number().int().min(1).max(100).default(50),
});
//# sourceMappingURL=todos.js.map
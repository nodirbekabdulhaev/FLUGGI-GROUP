"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.createCommentSchema = exports.timelineQuerySchema = exports.meetingListQuerySchema = exports.completeMeetingSchema = exports.updateMeetingSchema = exports.createMeetingSchema = exports.pipelineQuerySchema = exports.dealListQuerySchema = exports.updateDealSchema = exports.createDealSchema = exports.clientListQuerySchema = exports.updateClientSchema = exports.createClientSchema = exports.contactSchema = exports.convertLeadSchema = exports.closeSchema = exports.assignSchema = exports.changeDealStageSchema = exports.changeLeadStageSchema = exports.leadListQuerySchema = exports.updateLeadSchema = exports.createLeadSchema = void 0;
const zod_1 = require("zod");
const enums_1 = require("../enums");
const common_1 = require("./common");
const fields_1 = require("./fields");
// ─────────────────────────── Лиды ───────────────────────────
const leadContactFields = {
    contactName: (0, fields_1.optText)(120),
    companyName: (0, fields_1.optText)(160),
    phone: fields_1.phoneText,
    telegram: (0, fields_1.optText)(64),
    whatsapp: fields_1.phoneText,
    instagram: (0, fields_1.optText)(64),
    email: fields_1.emailText,
    website: (0, fields_1.optText)(200),
    city: (0, fields_1.optText)(80),
    country: (0, fields_1.optText)(80),
};
const leadBusinessFields = {
    title: (0, fields_1.optText)(200),
    sourceId: zod_1.z.uuid('Выберите источник'),
    serviceId: zod_1.z.uuid('Выберите услугу'),
    budget: fields_1.optMoney,
    currency: zod_1.z.enum(enums_1.CURRENCIES).default('UZS'),
    desiredDate: fields_1.dateOnly.optional(),
    priority: zod_1.z.enum(enums_1.PRIORITIES).default('MEDIUM'),
    companySize: zod_1.z.enum(enums_1.COMPANY_SIZES).optional(),
    interest: zod_1.z.coerce.number().int().min(1).max(5).optional(),
    nextContactAt: fields_1.dateTime.optional(),
    comment: (0, fields_1.optText)(4000),
};
/**
 * Создание лида — минимум полей (ТЗ §78): имя или компания, телефон или Telegram,
 * источник, услуга. Остальное заполняется позже.
 */
exports.createLeadSchema = zod_1.z
    .object({ ...leadContactFields, ...leadBusinessFields, ownerId: zod_1.z.uuid().optional() })
    .refine((v) => v.contactName || v.companyName, {
    path: ['contactName'],
    message: 'Укажите имя контакта или компанию',
})
    .refine((v) => v.phone || v.telegram, {
    path: ['phone'],
    message: 'Укажите телефон или Telegram',
});
/** Обновление: поля можно очистить (null). Владелец меняется через /assign. */
const nullable = (s) => s.nullable();
exports.updateLeadSchema = zod_1.z
    .object({
    title: zod_1.z.string().trim().min(1).max(200),
    contactName: nullable(zod_1.z.string().trim().max(120)),
    companyName: nullable(zod_1.z.string().trim().max(160)),
    phone: nullable(zod_1.z
        .string()
        .trim()
        .max(30)
        .regex(/^[+0-9\s\-()]*$/, 'Некорректный телефон')),
    telegram: nullable(zod_1.z.string().trim().max(64)),
    whatsapp: nullable(zod_1.z.string().trim().max(30)),
    instagram: nullable(zod_1.z.string().trim().max(64)),
    email: nullable(zod_1.z.string().trim().toLowerCase().max(254)),
    website: nullable(zod_1.z.string().trim().max(200)),
    city: nullable(zod_1.z.string().trim().max(80)),
    country: nullable(zod_1.z.string().trim().max(80)),
    sourceId: zod_1.z.uuid(),
    serviceId: nullable(zod_1.z.uuid()),
    budget: nullable(fields_1.moneySchema),
    currency: zod_1.z.enum(enums_1.CURRENCIES),
    desiredDate: nullable(fields_1.dateOnly),
    priority: zod_1.z.enum(enums_1.PRIORITIES),
    companySize: nullable(zod_1.z.enum(enums_1.COMPANY_SIZES)),
    interest: nullable(zod_1.z.coerce.number().int().min(1).max(5)),
    nextContactAt: nullable(fields_1.dateTime),
    comment: nullable(zod_1.z.string().trim().max(4000)),
})
    .partial()
    .transform((v) => {
    // Пустые строки из формы → null
    const out = {};
    for (const [k, val] of Object.entries(v))
        out[k] = val === '' ? null : val;
    return out;
});
exports.leadListQuerySchema = common_1.paginationQuerySchema.extend({
    q: zod_1.z.string().trim().max(100).optional(),
    stageCode: zod_1.z.enum(enums_1.LEAD_STAGE_CODES).optional(),
    status: zod_1.z.enum(enums_1.LEAD_STATUSES).optional(),
    ownerId: zod_1.z.uuid().optional(),
    teamId: zod_1.z.uuid().optional(),
    sourceId: zod_1.z.uuid().optional(),
    serviceId: zod_1.z.uuid().optional(),
    scoreLevel: zod_1.z.enum(enums_1.SCORE_LEVELS).optional(),
    dateFrom: fields_1.dateOnly.optional(),
    dateTo: fields_1.dateOnly.optional(),
});
exports.changeLeadStageSchema = zod_1.z.object({ stageCode: zod_1.z.enum(enums_1.LEAD_STAGE_CODES) });
exports.changeDealStageSchema = zod_1.z.object({ stageCode: zod_1.z.enum(enums_1.DEAL_STAGE_CODES) });
exports.assignSchema = zod_1.z.object({ ownerId: zod_1.z.uuid('Выберите ответственного') });
/** Закрытие лида/сделки (ТЗ §7, §39): для «Потеряно» и «Отказ» причина обязательна. */
exports.closeSchema = zod_1.z
    .object({
    status: zod_1.z.enum(enums_1.CLOSE_STATUSES),
    lossReasonId: zod_1.z.uuid().optional(),
    comment: zod_1.z.string().trim().max(2000).optional(),
})
    .refine((v) => !enums_1.REASON_REQUIRED_STATUSES.includes(v.status) || v.lossReasonId, {
    path: ['lossReasonId'],
    message: 'Укажите причину',
});
/** Квалификация лида → клиент + сделка (BUSINESS_RULES §2). */
exports.convertLeadSchema = zod_1.z
    .object({
    clientId: zod_1.z.uuid().optional(),
    clientName: (0, fields_1.optText)(160),
    clientType: zod_1.z.enum(enums_1.CLIENT_TYPES).default('COMPANY'),
    title: (0, fields_1.optText)(200),
    amount: fields_1.moneySchema,
    currency: zod_1.z.enum(enums_1.CURRENCIES).default('UZS'),
    expectedCloseDate: fields_1.dateOnly.optional(),
})
    .refine((v) => v.clientId || v.clientName, {
    path: ['clientName'],
    message: 'Выберите клиента или укажите название нового',
});
// ─────────────────────────── Клиенты ───────────────────────────
const contactFields = {
    fullName: zod_1.z.string().trim().min(1, 'Укажите имя').max(120),
    position: (0, fields_1.optText)(120),
    phone: fields_1.phoneText,
    telegram: (0, fields_1.optText)(64),
    whatsapp: fields_1.phoneText,
    instagram: (0, fields_1.optText)(64),
    email: fields_1.emailText,
    isPrimary: zod_1.z.boolean().default(false),
};
exports.contactSchema = zod_1.z.object(contactFields);
exports.createClientSchema = zod_1.z.object({
    name: zod_1.z.string().trim().min(1, 'Укажите название').max(160),
    type: zod_1.z.enum(enums_1.CLIENT_TYPES).default('COMPANY'),
    industry: (0, fields_1.optText)(120),
    phone: fields_1.phoneText,
    email: fields_1.emailText,
    telegram: (0, fields_1.optText)(64),
    website: (0, fields_1.optText)(200),
    city: (0, fields_1.optText)(80),
    country: (0, fields_1.optText)(80),
    sourceId: zod_1.z.uuid().optional(),
    ownerId: zod_1.z.uuid().optional(),
    comment: (0, fields_1.optText)(4000),
    contact: exports.contactSchema.optional(),
});
exports.updateClientSchema = exports.createClientSchema
    .omit({ contact: true, ownerId: true })
    .partial();
exports.clientListQuerySchema = common_1.paginationQuerySchema.extend({
    q: zod_1.z.string().trim().max(100).optional(),
    ownerId: zod_1.z.uuid().optional(),
    teamId: zod_1.z.uuid().optional(),
    type: zod_1.z.enum(enums_1.CLIENT_TYPES).optional(),
});
// ─────────────────────────── Сделки ───────────────────────────
exports.createDealSchema = zod_1.z.object({
    clientId: zod_1.z.uuid('Выберите клиента'),
    contactId: zod_1.z.uuid().optional(),
    title: zod_1.z.string().trim().min(1, 'Укажите название').max(200),
    serviceId: zod_1.z.uuid().optional(),
    amount: fields_1.moneySchema,
    currency: zod_1.z.enum(enums_1.CURRENCIES).default('UZS'),
    ownerId: zod_1.z.uuid().optional(),
    expectedCloseDate: fields_1.dateOnly.optional(),
    isRepeat: zod_1.z.boolean().optional(),
});
exports.updateDealSchema = zod_1.z
    .object({
    title: zod_1.z.string().trim().min(1).max(200),
    contactId: zod_1.z.uuid().nullable(),
    serviceId: zod_1.z.uuid().nullable(),
    amount: fields_1.moneySchema,
    currency: zod_1.z.enum(enums_1.CURRENCIES),
    expectedCloseDate: fields_1.dateOnly.nullable(),
    probabilityOverride: zod_1.z.coerce.number().int().min(0).max(100).nullable(),
})
    .partial();
exports.dealListQuerySchema = common_1.paginationQuerySchema.extend({
    q: zod_1.z.string().trim().max(100).optional(),
    stageCode: zod_1.z.enum(enums_1.DEAL_STAGE_CODES).optional(),
    status: zod_1.z.enum(enums_1.DEAL_STATUSES).optional(),
    ownerId: zod_1.z.uuid().optional(),
    teamId: zod_1.z.uuid().optional(),
    clientId: zod_1.z.uuid().optional(),
    serviceId: zod_1.z.uuid().optional(),
    amountMin: zod_1.z.coerce.number().min(0).optional(),
    amountMax: zod_1.z.coerce.number().min(0).optional(),
    dateFrom: fields_1.dateOnly.optional(),
    dateTo: fields_1.dateOnly.optional(),
});
// ─────────────────────────── Воронка ───────────────────────────
exports.pipelineQuerySchema = zod_1.z.object({
    ownerId: zod_1.z.uuid().optional(),
    teamId: zod_1.z.uuid().optional(),
    serviceId: zod_1.z.uuid().optional(),
    sourceId: zod_1.z.uuid().optional(),
});
// ─────────────────────────── Встречи ───────────────────────────
exports.createMeetingSchema = zod_1.z
    .object({
    leadId: zod_1.z.uuid().optional(),
    dealId: zod_1.z.uuid().optional(),
    startsAt: fields_1.dateTime,
    durationMin: zod_1.z.coerce.number().int().min(5).max(600).default(60),
    type: zod_1.z.enum(enums_1.MEETING_TYPES),
    link: (0, fields_1.optText)(500),
    comment: (0, fields_1.optText)(2000),
})
    .refine((v) => Boolean(v.leadId) !== Boolean(v.dealId), {
    path: ['leadId'],
    message: 'Встреча привязывается к лиду или к сделке',
});
exports.updateMeetingSchema = zod_1.z
    .object({
    startsAt: fields_1.dateTime,
    durationMin: zod_1.z.coerce.number().int().min(5).max(600),
    type: zod_1.z.enum(enums_1.MEETING_TYPES),
    link: zod_1.z.string().trim().max(500).nullable(),
    comment: zod_1.z.string().trim().max(2000).nullable(),
    status: zod_1.z.enum(['SCHEDULED', 'CONFIRMED', 'RESCHEDULED', 'CANCELLED', 'NO_SHOW']),
})
    .partial();
exports.completeMeetingSchema = zod_1.z.object({
    result: zod_1.z.string().trim().min(1, 'Опишите результат встречи').max(4000),
});
exports.meetingListQuerySchema = common_1.paginationQuerySchema.extend({
    status: zod_1.z.enum(enums_1.MEETING_STATUSES).optional(),
    managerId: zod_1.z.uuid().optional(),
    leadId: zod_1.z.uuid().optional(),
    dealId: zod_1.z.uuid().optional(),
    dateFrom: fields_1.dateOnly.optional(),
    dateTo: fields_1.dateOnly.optional(),
});
// ─────────────────────── Таймлайн и комментарии ───────────────────────
exports.timelineQuerySchema = zod_1.z
    .object({
    leadId: zod_1.z.uuid().optional(),
    dealId: zod_1.z.uuid().optional(),
    clientId: zod_1.z.uuid().optional(),
})
    .refine((v) => [v.leadId, v.dealId, v.clientId].filter(Boolean).length === 1, 'Укажите одну запись');
exports.createCommentSchema = zod_1.z
    .object({
    leadId: zod_1.z.uuid().optional(),
    dealId: zod_1.z.uuid().optional(),
    clientId: zod_1.z.uuid().optional(),
    body: zod_1.z.string().trim().min(1, 'Напишите комментарий').max(4000),
})
    .refine((v) => [v.leadId, v.dealId, v.clientId].filter(Boolean).length === 1, 'Укажите одну запись');
//# sourceMappingURL=crm.js.map
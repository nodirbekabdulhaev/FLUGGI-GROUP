"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.integrationSettingsSchema = exports.socialReplySchema = exports.inboxListQuerySchema = exports.SOCIAL_CHANNELS = exports.INTAKE_RESULTS = exports.formSubmitSchema = exports.DEFAULT_FORM_FIELDS = exports.leadFormSchema = exports.formFieldSchema = exports.FORM_FIELD_TYPES = void 0;
const zod_1 = require("zod");
// ─────────────────────────── Формы для сайта ───────────────────────────
exports.FORM_FIELD_TYPES = ['text', 'phone', 'email', 'textarea', 'select'];
/**
 * Поле формы. Ключи name/phone/email/company/message/service попадают в одноимённые поля лида,
 * остальные — в комментарий лида.
 */
exports.formFieldSchema = zod_1.z.object({
    key: zod_1.z
        .string()
        .trim()
        .min(1)
        .max(40)
        .regex(/^[a-z][a-z0-9_]*$/, 'Ключ — латиница, цифры и _'),
    label: zod_1.z.string().trim().min(1, 'Подпись поля').max(120),
    type: zod_1.z.enum(exports.FORM_FIELD_TYPES),
    required: zod_1.z.boolean().default(false),
    options: zod_1.z.array(zod_1.z.string().trim().min(1).max(120)).max(30).optional(),
});
exports.leadFormSchema = zod_1.z.object({
    name: zod_1.z.string().trim().min(1, 'Название для списка').max(120),
    title: zod_1.z.string().trim().min(1, 'Заголовок формы').max(200),
    description: zod_1.z.string().trim().max(1000).nullish(),
    buttonText: zod_1.z.string().trim().min(1).max(60).default('Отправить'),
    successMessage: zod_1.z
        .string()
        .trim()
        .min(1)
        .max(500)
        .default('Спасибо! Мы свяжемся с вами в ближайшее время.'),
    fields: zod_1.z
        .array(exports.formFieldSchema)
        .min(1, 'Добавьте поля')
        .max(20)
        .refine((f) => new Set(f.map((x) => x.key)).size === f.length, 'Ключи полей повторяются')
        .refine((f) => f.some((x) => x.type === 'phone' || x.type === 'email'), 'Нужно поле «Телефон» или «Email», чтобы связаться с клиентом'),
    serviceId: zod_1.z.uuid().nullish(),
    sourceId: zod_1.z.uuid().nullish(),
    ownerId: zod_1.z.uuid().nullish(),
    teamId: zod_1.z.uuid().nullish(),
    isActive: zod_1.z.boolean().default(true),
});
/** Поля новой формы по умолчанию */
exports.DEFAULT_FORM_FIELDS = [
    { key: 'name', label: 'Ваше имя', type: 'text', required: true },
    { key: 'phone', label: 'Телефон', type: 'phone', required: true },
    { key: 'company', label: 'Компания', type: 'text', required: false },
    { key: 'message', label: 'Что вас интересует?', type: 'textarea', required: false },
];
exports.formSubmitSchema = zod_1.z.object({
    data: zod_1.z.record(zod_1.z.string().max(40), zod_1.z.string().max(4000)),
    /** utm_source, utm_medium, utm_campaign, utm_content, utm_term */
    utm: zod_1.z.record(zod_1.z.string().max(40), zod_1.z.string().max(300)).optional(),
    page: zod_1.z.string().max(1000).optional(),
    /** Ловушка для ботов: поле скрыто, человек его не заполняет */
    website: zod_1.z.string().max(200).optional(),
    /** Время показа формы (мс); слишком быстрая отправка — бот */
    renderedAt: zod_1.z.coerce.number().optional(),
});
exports.INTAKE_RESULTS = ['LEAD_CREATED', 'DUPLICATE', 'SPAM'];
// ─────────────────────────── Instagram / Facebook ───────────────────────────
exports.SOCIAL_CHANNELS = ['INSTAGRAM_DM', 'INSTAGRAM_COMMENT', 'FACEBOOK_COMMENT'];
exports.inboxListQuerySchema = zod_1.z.object({
    channel: zod_1.z.enum(exports.SOCIAL_CHANNELS).optional(),
    unread: zod_1.z.enum(['true', 'false']).optional(),
    page: zod_1.z.coerce.number().int().min(1).default(1),
    pageSize: zod_1.z.coerce.number().int().min(1).max(100).default(30),
});
exports.socialReplySchema = zod_1.z.object({
    text: zod_1.z.string().trim().min(1, 'Введите ответ').max(1000),
    /** Комментарии: ответить на этот комментарий публично или в Директ (private reply) */
    commentId: zod_1.z.string().max(100).optional(),
    mode: zod_1.z.enum(['public', 'private']).default('public'),
});
/** Настройки интеграций (секреты Meta — только в .env). */
exports.integrationSettingsSchema = zod_1.z.object({
    /** Ответственный за лиды из соцсетей и таргета; иначе — по очереди в отделе */
    ownerId: zod_1.z.uuid().nullable().default(null),
    teamId: zod_1.z.uuid().nullable().default(null),
    /** Лид из Директа: сразу при первом сообщении */
    autoLeadFromDirect: zod_1.z.boolean().default(true),
    /** Лид из комментария: off — вручную, keywords — если есть слова из списка, all — всегда */
    autoLeadFromComments: zod_1.z.enum(['off', 'keywords', 'all']).default('keywords'),
    commentKeywords: zod_1.z
        .array(zod_1.z.string().trim().min(2).max(40))
        .max(50)
        .default(['цена', 'стоимость', 'сколько', 'прайс', 'нарх', 'qancha', 'price', 'директ']),
    serviceId: zod_1.z.uuid().nullable().default(null),
});
//# sourceMappingURL=integrations.js.map
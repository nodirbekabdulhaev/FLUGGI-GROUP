"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.deleteUserSchema = exports.userListQuerySchema = exports.updateUserSchema = exports.createUserSchema = void 0;
const zod_1 = require("zod");
const enums_1 = require("../enums");
const auth_1 = require("./auth");
const common_1 = require("./common");
const phoneSchema = zod_1.z
    .string()
    .trim()
    .regex(/^\+?[0-9\s\-()]{7,20}$/, 'Некорректный телефон');
const optionalText = (max) => zod_1.z
    .string()
    .trim()
    .max(max)
    .optional()
    .transform((v) => (v === '' ? undefined : v));
exports.createUserSchema = zod_1.z.object({
    email: auth_1.emailSchema,
    fullName: zod_1.z.string().trim().min(2, 'Укажите имя').max(120),
    phone: phoneSchema.optional().or(zod_1.z.literal('').transform(() => undefined)),
    roleCode: zod_1.z.enum(enums_1.ROLE_CODES, 'Выберите роль'),
    teamId: zod_1.z.uuid().nullish(),
    position: optionalText(120),
    specialty: zod_1.z.enum(enums_1.EXECUTOR_SPECIALTIES).nullish(),
    locale: zod_1.z.enum(enums_1.LOCALES).default('ru'),
    /** Направления бизнеса (проект-менеджер видит проекты этих направлений) */
    directionIds: zod_1.z.array(zod_1.z.uuid()).max(20).default([]),
    /** Если не указан — сервер сгенерирует временный пароль и вернёт его один раз. */
    password: auth_1.passwordSchema.optional(),
});
exports.updateUserSchema = zod_1.z
    .object({
    email: auth_1.emailSchema,
    fullName: zod_1.z.string().trim().min(2, 'Укажите имя').max(120),
    phone: phoneSchema.nullable().or(zod_1.z.literal('').transform(() => null)),
    roleCode: zod_1.z.enum(enums_1.ROLE_CODES),
    teamId: zod_1.z.uuid().nullable(),
    position: zod_1.z
        .string()
        .trim()
        .max(120)
        .nullable()
        .transform((v) => (v === '' ? null : v)),
    specialty: zod_1.z.enum(enums_1.EXECUTOR_SPECIALTIES).nullable(),
    locale: zod_1.z.enum(enums_1.LOCALES),
    directionIds: zod_1.z.array(zod_1.z.uuid()).max(20),
})
    .partial()
    .refine((v) => Object.values(v).some((x) => x !== undefined), 'Нет изменений');
exports.userListQuerySchema = common_1.paginationQuerySchema.extend({
    q: zod_1.z.string().trim().max(100).optional(),
    roleCode: zod_1.z.enum(enums_1.ROLE_CODES).optional(),
    teamId: zod_1.z.uuid().optional(),
    status: zod_1.z.enum(enums_1.USER_STATUSES).optional(),
});
exports.deleteUserSchema = zod_1.z.object({
    /** Кому передать открытые лиды, сделки, клиентов, проекты, задачи и переписки */
    transferToId: zod_1.z.uuid().nullish(),
});
//# sourceMappingURL=users.js.map
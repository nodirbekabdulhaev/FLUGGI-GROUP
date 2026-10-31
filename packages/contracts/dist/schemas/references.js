"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.setExchangeRateSchema = exports.updateStageSchema = exports.upsertServiceSchema = exports.upsertReferenceItemSchema = exports.directionSchema = void 0;
const zod_1 = require("zod");
const enums_1 = require("../enums");
const fields_1 = require("./fields");
exports.directionSchema = zod_1.z.object({
    name: zod_1.z.string().trim().min(1, 'Введите название').max(80),
    sort: zod_1.z.coerce.number().int().min(0).max(1000).default(100),
    isActive: zod_1.z.boolean().default(true),
});
const code = zod_1.z
    .string()
    .trim()
    .min(2)
    .max(40)
    .regex(/^[A-Z0-9_]+$/, 'Только латинские заглавные буквы, цифры и _');
exports.upsertReferenceItemSchema = zod_1.z.object({
    code,
    name: zod_1.z.string().trim().min(1, 'Укажите название').max(120),
    nameUz: zod_1.z.string().trim().max(120).nullish(),
    nameEn: zod_1.z.string().trim().max(120).nullish(),
    isActive: zod_1.z.boolean().default(true),
    sort: zod_1.z.coerce.number().int().min(0).max(10000).default(0),
    requiresComment: zod_1.z.boolean().optional(),
});
exports.upsertServiceSchema = exports.upsertReferenceItemSchema
    .omit({ requiresComment: true })
    .extend({
    description: zod_1.z.string().trim().max(2000).nullish(),
    basePrice: fields_1.optMoney,
    minPrice: fields_1.optMoney,
    currency: zod_1.z.enum(enums_1.CURRENCIES).default('UZS'),
    pricingType: zod_1.z.enum(enums_1.PRICING_TYPES).default('FIXED'),
    directionId: zod_1.z.uuid().nullish(),
});
exports.updateStageSchema = zod_1.z.object({
    name: zod_1.z.string().trim().min(1).max(80).optional(),
    probability: zod_1.z.coerce.number().int().min(0).max(100).optional(),
    color: zod_1.z
        .string()
        .regex(/^#[0-9a-fA-F]{6}$/)
        .optional(),
});
exports.setExchangeRateSchema = zod_1.z.object({
    currency: zod_1.z.literal('USD'),
    rateToUzs: zod_1.z.coerce.number().positive('Курс должен быть больше нуля').max(1_000_000),
    date: zod_1.z.iso.date().optional(),
});
//# sourceMappingURL=references.js.map
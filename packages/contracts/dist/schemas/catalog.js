"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.updateCostLineSchema = exports.COST_LINE_STATUSES = exports.financeSettingsSchema = exports.tariffSchema = exports.tariffItemSchema = exports.TARIFF_ITEM_KINDS = exports.employeeRatesSchema = exports.workItemSchema = exports.EXECUTOR_SPECIALTIES_ALL = exports.otherIncomeListQuerySchema = exports.otherIncomeSchema = exports.financeCategorySchema = exports.FINANCE_CATEGORY_KINDS = void 0;
const zod_1 = require("zod");
const enums_1 = require("../enums");
const fields_1 = require("./fields");
// ─────────────────────────── Категории доходов и расходов ───────────────────────────
exports.FINANCE_CATEGORY_KINDS = ['EXPENSE', 'INCOME'];
exports.financeCategorySchema = zod_1.z.object({
    kind: zod_1.z.enum(exports.FINANCE_CATEGORY_KINDS),
    name: zod_1.z.string().trim().min(1, 'Введите название').max(80),
    accountHint: zod_1.z.string().trim().max(20).nullish(),
    isOverhead: zod_1.z.boolean().default(false),
    isActive: zod_1.z.boolean().default(true),
    sort: zod_1.z.coerce.number().int().min(0).max(1000).default(100),
});
// ─────────────────────────── Прочие поступления ───────────────────────────
exports.otherIncomeSchema = zod_1.z.object({
    category: zod_1.z.string().trim().min(1, 'Выберите категорию').max(40),
    amount: fields_1.moneySchema.refine((v) => Number(v) > 0, 'Сумма должна быть больше нуля'),
    currency: zod_1.z.enum(enums_1.CURRENCIES).default('UZS'),
    incomeDate: fields_1.dateOnly,
    projectId: zod_1.z.uuid().nullish(),
    clientId: zod_1.z.uuid().nullish(),
    description: zod_1.z.string().trim().max(2000).nullish(),
});
exports.otherIncomeListQuerySchema = zod_1.z.object({
    category: zod_1.z.string().max(40).optional(),
    dateFrom: fields_1.dateOnly.optional(),
    dateTo: fields_1.dateOnly.optional(),
    page: zod_1.z.coerce.number().int().min(1).default(1),
    pageSize: zod_1.z.coerce.number().int().min(1).max(100).default(25),
});
// ─────────────────────────── Тарифы и себестоимость ───────────────────────────
exports.EXECUTOR_SPECIALTIES_ALL = enums_1.EXECUTOR_SPECIALTIES;
exports.workItemSchema = zod_1.z.object({
    name: zod_1.z.string().trim().min(1, 'Введите название').max(120),
    unit: zod_1.z.string().trim().min(1).max(20).default('шт'),
    specialty: zod_1.z.enum(exports.EXECUTOR_SPECIALTIES_ALL).nullish(),
    defaultRate: fields_1.moneySchema,
    currency: zod_1.z.enum(enums_1.CURRENCIES).default('UZS'),
    isActive: zod_1.z.boolean().default(true),
});
/** Личные ставки сотрудника («договор сдельный» в карточке сотрудника). Пустая ставка — удалить. */
exports.employeeRatesSchema = zod_1.z.object({
    rates: zod_1.z
        .array(zod_1.z.object({
        workItemId: zod_1.z.uuid(),
        rate: fields_1.moneySchema.nullable(),
        currency: zod_1.z.enum(enums_1.CURRENCIES).default('UZS'),
    }))
        .max(200),
});
exports.TARIFF_ITEM_KINDS = ['PIECE', 'FIXED'];
exports.tariffItemSchema = zod_1.z
    .object({
    kind: zod_1.z.enum(exports.TARIFF_ITEM_KINDS),
    workItemId: zod_1.z.uuid().nullish(),
    quantity: zod_1.z.coerce.number().positive().max(100000).default(1),
    specialty: zod_1.z.enum(exports.EXECUTOR_SPECIALTIES_ALL).nullish(),
    amount: fields_1.moneySchema.nullish(),
    currency: zod_1.z.enum(enums_1.CURRENCIES).default('UZS'),
    label: zod_1.z.string().trim().max(120).nullish(),
})
    .refine((v) => (v.kind === 'PIECE' ? Boolean(v.workItemId) : Boolean(v.specialty && v.amount)), {
    message: 'Сдельно — выберите работу; фиксированно — исполнителя и сумму',
    path: ['workItemId'],
});
exports.tariffSchema = zod_1.z.object({
    serviceId: zod_1.z.uuid(),
    name: zod_1.z.string().trim().min(1, 'Введите название').max(60),
    description: zod_1.z.string().trim().max(2000).nullish(),
    price: fields_1.moneySchema.refine((v) => Number(v) > 0, 'Цена должна быть больше нуля'),
    currency: zod_1.z.enum(enums_1.CURRENCIES).default('UZS'),
    isActive: zod_1.z.boolean().default(true),
    sort: zod_1.z.coerce.number().int().min(0).max(1000).default(0),
    items: zod_1.z.array(exports.tariffItemSchema).max(50).default([]),
});
/** Финансовые настройки: делитель накладных (null — по числу проектов в месяце). */
exports.financeSettingsSchema = zod_1.z.object({
    overheadDivisor: zod_1.z.coerce.number().int().min(1).max(1000).nullable(),
});
exports.COST_LINE_STATUSES = ['PLANNED', 'ACCRUED', 'CANCELLED'];
exports.updateCostLineSchema = zod_1.z.object({
    quantity: zod_1.z.coerce.number().positive().max(100000).optional(),
    rate: fields_1.moneySchema.optional(),
    assigneeId: zod_1.z.uuid().nullable().optional(),
});
//# sourceMappingURL=catalog.js.map
"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.projectProfitQuerySchema = exports.expenseListQuerySchema = exports.updateExpenseSchema = exports.createExpenseSchema = exports.periodQuerySchema = void 0;
exports.resolvePeriodQuery = resolvePeriodQuery;
const zod_1 = require("zod");
const enums_1 = require("../enums");
const period_1 = require("../period");
const common_1 = require("./common");
const fields_1 = require("./fields");
// ─────────────────────────── Период ───────────────────────────
/** ?period=today|week|month|quarter|year|custom&from&to — как в глобальном фильтре CEO. */
exports.periodQuerySchema = zod_1.z.object({
    period: zod_1.z.enum(period_1.PERIOD_PRESETS).default('month'),
    from: fields_1.dateOnly.optional(),
    to: fields_1.dateOnly.optional(),
});
/** Период запроса → границы; свой период без дат — текущий месяц. */
function resolvePeriodQuery(q, now = new Date()) {
    if (q.period === 'custom') {
        const r = q.from && q.to ? (0, period_1.resolveCustomPeriod)(q.from, q.to) : null;
        return r ?? (0, period_1.resolvePeriod)('month', now);
    }
    return (0, period_1.resolvePeriod)(q.period, now);
}
// ─────────────────────────── Расходы (ТЗ §26) ───────────────────────────
const expenseFields = {
    /** Код категории из справочника «Категории доходов и расходов» */
    category: zod_1.z.string().trim().min(1, 'Выберите категорию').max(40),
    amount: fields_1.moneySchema.refine((v) => Number(v) > 0, 'Сумма должна быть больше нуля'),
    currency: zod_1.z.enum(enums_1.CURRENCIES).default('UZS'),
    expenseDate: fields_1.dateOnly,
    payeeUserId: zod_1.z.uuid().nullish(),
    description: zod_1.z.string().trim().max(2000).nullish(),
};
exports.createExpenseSchema = zod_1.z
    .object({
    scope: zod_1.z.enum(enums_1.EXPENSE_SCOPES),
    projectId: zod_1.z.uuid().nullish(),
    ...expenseFields,
})
    .refine((v) => (v.scope === 'PROJECT') === Boolean(v.projectId), {
    message: 'Проектный расход привязывается к проекту, расход компании — без проекта',
    path: ['projectId'],
});
exports.updateExpenseSchema = zod_1.z.object({
    category: expenseFields.category.optional(),
    amount: expenseFields.amount.optional(),
    currency: zod_1.z.enum(enums_1.CURRENCIES).optional(),
    expenseDate: fields_1.dateOnly.optional(),
    payeeUserId: zod_1.z.uuid().nullable().optional(),
    description: zod_1.z.string().trim().max(2000).nullable().optional(),
});
exports.expenseListQuerySchema = common_1.paginationQuerySchema.extend({
    scope: zod_1.z.enum(enums_1.EXPENSE_SCOPES).optional(),
    projectId: zod_1.z.uuid().optional(),
    category: zod_1.z.string().trim().max(40).optional(),
    dateFrom: fields_1.dateOnly.optional(),
    dateTo: fields_1.dateOnly.optional(),
});
exports.projectProfitQuerySchema = common_1.paginationQuerySchema.extend({
    q: zod_1.z.string().trim().max(100).optional(),
});
//# sourceMappingURL=finance.js.map
"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.upsertCommissionRuleSchema = exports.conditionSchema = exports.commissionIdsSchema = exports.commissionListQuerySchema = exports.paymentListQuerySchema = exports.refundPaymentSchema = exports.confirmPaymentSchema = exports.updatePaymentSchema = exports.createPaymentSchema = exports.contractListQuerySchema = exports.updateContractSchema = exports.createContractSchema = exports.proposalListQuerySchema = exports.createProposalSchema = exports.upsertProposalSchema = exports.proposalItemSchema = void 0;
const zod_1 = require("zod");
const enums_1 = require("../enums");
const common_1 = require("./common");
const fields_1 = require("./fields");
const numRef = (n) => n;
// ─────────────────────────── КП ───────────────────────────
exports.proposalItemSchema = zod_1.z.object({
    serviceId: zod_1.z.uuid().nullish(),
    /** Тариф услуги: цена и состав работ подставляются из тарифа */
    tariffId: zod_1.z.uuid().nullish(),
    description: zod_1.z.string().trim().min(1, 'Опишите позицию').max(500),
    quantity: zod_1.z.coerce.number().positive('Количество > 0').max(1_000_000),
    unitPrice: fields_1.moneySchema,
    discountPct: zod_1.z.coerce.number().min(0).max(100).default(0),
});
exports.upsertProposalSchema = zod_1.z.object({
    title: zod_1.z.string().trim().min(1, 'Укажите название').max(200),
    description: zod_1.z.string().trim().max(5000).nullish(),
    currency: zod_1.z.enum(enums_1.CURRENCIES).default('UZS'),
    items: zod_1.z.array(exports.proposalItemSchema).min(1, 'Добавьте хотя бы одну позицию').max(100),
    implementationTerm: zod_1.z.string().trim().max(200).nullish(),
    paymentTerms: zod_1.z.string().trim().max(1000).nullish(),
    validUntil: fields_1.dateOnly.nullish(),
    /** Комментарий к версии (что изменилось). */
    versionComment: (0, fields_1.optText)(500),
});
exports.createProposalSchema = exports.upsertProposalSchema.extend({ dealId: zod_1.z.uuid() });
exports.proposalListQuerySchema = common_1.paginationQuerySchema.extend({
    q: zod_1.z.string().trim().max(100).optional(),
    status: zod_1.z.enum(enums_1.PROPOSAL_STATUSES).optional(),
    dealId: zod_1.z.uuid().optional(),
    managerId: zod_1.z.uuid().optional(),
});
// ─────────────────────────── Договоры ───────────────────────────
exports.createContractSchema = zod_1.z.object({
    dealId: zod_1.z.uuid(),
    proposalId: zod_1.z.uuid().optional(),
    contractDate: fields_1.dateOnly,
    amount: fields_1.moneySchema,
    currency: zod_1.z.enum(enums_1.CURRENCIES).default('UZS'),
    comment: (0, fields_1.optText)(2000),
});
exports.updateContractSchema = zod_1.z
    .object({
    contractDate: fields_1.dateOnly,
    amount: fields_1.moneySchema,
    currency: zod_1.z.enum(enums_1.CURRENCIES),
    comment: zod_1.z.string().trim().max(2000).nullable(),
})
    .partial();
exports.contractListQuerySchema = common_1.paginationQuerySchema.extend({
    q: zod_1.z.string().trim().max(100).optional(),
    status: zod_1.z.enum(enums_1.CONTRACT_STATUSES).optional(),
    dealId: zod_1.z.uuid().optional(),
});
// ─────────────────────────── Оплаты ───────────────────────────
exports.createPaymentSchema = zod_1.z.object({
    dealId: zod_1.z.uuid(),
    contractId: zod_1.z.uuid().optional(),
    amount: fields_1.moneySchema.refine((v) => Number(v) > 0, 'Сумма должна быть больше нуля'),
    currency: zod_1.z.enum(enums_1.CURRENCIES).default('UZS'),
    type: zod_1.z.enum(enums_1.PAYMENT_TYPES.filter((t) => t !== 'REFUND')),
    method: zod_1.z.enum(enums_1.PAYMENT_METHODS),
    dueDate: fields_1.dateOnly.optional(),
    comment: (0, fields_1.optText)(2000),
});
/** Исправление неподтверждённой оплаты (ошиблись в сумме, типе, способе). */
exports.updatePaymentSchema = zod_1.z.object({
    contractId: zod_1.z.uuid().nullable().optional(),
    amount: fields_1.moneySchema.refine((v) => Number(v) > 0, 'Сумма должна быть больше нуля').optional(),
    currency: zod_1.z.enum(enums_1.CURRENCIES).optional(),
    type: zod_1.z
        .enum(enums_1.PAYMENT_TYPES.filter((t) => t !== 'REFUND'))
        .optional(),
    method: zod_1.z.enum(enums_1.PAYMENT_METHODS).optional(),
    dueDate: fields_1.dateOnly.nullable().optional(),
    comment: zod_1.z.string().trim().max(2000).nullable().optional(),
});
exports.confirmPaymentSchema = zod_1.z.object({
    /** Фактическая дата оплаты; по умолчанию — сейчас. */
    paidAt: zod_1.z.iso.datetime({ offset: true }).optional(),
});
exports.refundPaymentSchema = zod_1.z.object({
    amount: fields_1.moneySchema.refine((v) => Number(v) > 0, 'Сумма должна быть больше нуля'),
    method: zod_1.z.enum(enums_1.PAYMENT_METHODS),
    comment: zod_1.z.string().trim().min(1, 'Укажите причину возврата').max(2000),
});
exports.paymentListQuerySchema = common_1.paginationQuerySchema.extend({
    status: zod_1.z.enum(enums_1.PAYMENT_STATUSES).optional(),
    type: zod_1.z.enum(enums_1.PAYMENT_TYPES).optional(),
    dealId: zod_1.z.uuid().optional(),
    clientId: zod_1.z.uuid().optional(),
    dateFrom: fields_1.dateOnly.optional(),
    dateTo: fields_1.dateOnly.optional(),
});
exports.commissionListQuerySchema = common_1.paginationQuerySchema.extend({
    status: zod_1.z.enum(['ACCRUED', 'APPROVED', 'PAID', 'CANCELLED']).optional(),
    period: zod_1.z
        .string()
        .regex(/^\d{4}-\d{2}$/)
        .optional(),
    userId: zod_1.z.uuid().optional(),
});
exports.commissionIdsSchema = zod_1.z.object({
    ids: zod_1.z.array(zod_1.z.uuid()).min(1, 'Выберите комиссии').max(500),
});
const conditionLeaf = zod_1.z.object({
    metric: zod_1.z.enum(['avg_check_usd', 'avg_check_uzs', 'orders_count', 'revenue_uzs', 'revenue_usd']),
    op: zod_1.z.enum(['>', '>=', '<', '<=', '=']),
    value: zod_1.z.number(),
});
exports.conditionSchema = zod_1.z.lazy(() => zod_1.z.union([
    conditionLeaf,
    zod_1.z.object({
        all: zod_1.z.array(exports.conditionSchema).optional(),
        any: zod_1.z.array(exports.conditionSchema).optional(),
    }),
]));
exports.upsertCommissionRuleSchema = zod_1.z.object({
    name: zod_1.z.string().trim().min(1).max(120),
    appliesTo: zod_1.z.enum(['MANAGER', 'ROP']),
    userId: zod_1.z.uuid().nullish(),
    calcType: zod_1.z.enum(enums_1.COMMISSION_CALC_TYPES),
    value: zod_1.z.coerce.number().min(0).max(1_000_000_000),
    conditions: exports.conditionSchema.nullish(),
    priority: zod_1.z.coerce.number().int().min(0).max(1000).default(0),
    isActive: zod_1.z.boolean().default(true),
});
//# sourceMappingURL=sales.js.map
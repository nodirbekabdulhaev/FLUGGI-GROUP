"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.exportQuerySchema = exports.EXPORT_ENTITIES = exports.searchQuerySchema = exports.clientAnalyticsQuerySchema = exports.CLIENT_HEALTH_LEVELS = exports.analyticsQuerySchema = void 0;
const zod_1 = require("zod");
const finance_1 = require("./finance");
// ─────────────────────────── Аналитика (ТЗ §39–43) ───────────────────────────
exports.analyticsQuerySchema = finance_1.periodQuerySchema.extend({
    /** Отдел (для CEO); РОП видит свои отделы, менеджер — себя. */
    teamId: zod_1.z.uuid().optional(),
    /** Конкретный менеджер. */
    userId: zod_1.z.uuid().optional(),
});
exports.CLIENT_HEALTH_LEVELS = ['HEALTHY', 'ATTENTION', 'RISK', 'LOST'];
exports.clientAnalyticsQuerySchema = zod_1.z.object({
    sort: zod_1.z.enum(['ltv', 'health', 'lastPayment']).default('ltv'),
    health: zod_1.z.enum(exports.CLIENT_HEALTH_LEVELS).optional(),
    page: zod_1.z.coerce.number().int().min(1).default(1),
    pageSize: zod_1.z.coerce.number().int().min(1).max(100).default(25),
});
// ─────────────────────────── Поиск (ТЗ §44) ───────────────────────────
exports.searchQuerySchema = zod_1.z.object({ q: zod_1.z.string().trim().min(2).max(100) });
// ─────────────────────────── Экспорт (ТЗ §44) ───────────────────────────
exports.EXPORT_ENTITIES = [
    'leads',
    'deals',
    'clients',
    'payments',
    'expenses',
    'projects',
    'tasks',
];
exports.exportQuerySchema = zod_1.z.object({
    format: zod_1.z.enum(['csv', 'xlsx']).default('xlsx'),
    period: zod_1.z.enum(['today', 'week', 'month', 'quarter', 'year', 'custom', 'all']).default('all'),
    from: zod_1.z.string().optional(),
    to: zod_1.z.string().optional(),
});
//# sourceMappingURL=analytics.js.map
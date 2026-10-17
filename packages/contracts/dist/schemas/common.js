"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.ERROR_CODES = exports.paginationQuerySchema = exports.idSchema = void 0;
const zod_1 = require("zod");
exports.idSchema = zod_1.z.uuid('Некорректный идентификатор');
exports.paginationQuerySchema = zod_1.z.object({
    page: zod_1.z.coerce.number().int().min(1).default(1),
    pageSize: zod_1.z.coerce.number().int().min(1).max(100).default(25),
});
exports.ERROR_CODES = [
    'UNAUTHENTICATED',
    'FORBIDDEN',
    'NOT_FOUND',
    'CONFLICT',
    'VALIDATION_ERROR',
    'BUSINESS_RULE_VIOLATION',
    'RATE_LIMITED',
    'CSRF_INVALID',
    'INTERNAL',
];
//# sourceMappingURL=common.js.map
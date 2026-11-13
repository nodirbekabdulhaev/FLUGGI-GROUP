"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.auditListQuerySchema = void 0;
const zod_1 = require("zod");
const common_1 = require("./common");
exports.auditListQuerySchema = common_1.paginationQuerySchema.extend({
    actorId: zod_1.z.uuid().optional(),
    entityType: zod_1.z.string().trim().max(50).optional(),
    entityId: zod_1.z.uuid().optional(),
    dateFrom: zod_1.z.iso.datetime({ offset: true }).optional(),
    dateTo: zod_1.z.iso.datetime({ offset: true }).optional(),
});
//# sourceMappingURL=audit.js.map
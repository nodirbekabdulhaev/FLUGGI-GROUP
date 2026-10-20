"use strict";
var __decorate = (this && this.__decorate) || function (decorators, target, key, desc) {
    var c = arguments.length, r = c < 3 ? target : desc === null ? desc = Object.getOwnPropertyDescriptor(target, key) : desc, d;
    if (typeof Reflect === "object" && typeof Reflect.decorate === "function") r = Reflect.decorate(decorators, target, key, desc);
    else for (var i = decorators.length - 1; i >= 0; i--) if (d = decorators[i]) r = (c < 3 ? d(r) : c > 3 ? d(target, key, r) : d(target, key)) || r;
    return c > 3 && r && Object.defineProperty(target, key, r), r;
};
var __metadata = (this && this.__metadata) || function (k, v) {
    if (typeof Reflect === "object" && typeof Reflect.metadata === "function") return Reflect.metadata(k, v);
};
var __param = (this && this.__param) || function (paramIndex, decorator) {
    return function (target, key) { decorator(target, key, paramIndex); }
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.AuditController = void 0;
const common_1 = require("@nestjs/common");
const contracts_1 = require("@fluggi/contracts");
const decorators_1 = require("../auth/decorators");
const zod_pipe_1 = require("../http/zod.pipe");
const prisma_service_1 = require("../prisma/prisma.service");
let AuditController = class AuditController {
    prisma;
    constructor(prisma) {
        this.prisma = prisma;
    }
    async list(query) {
        const where = {
            actorId: query.actorId,
            entityType: query.entityType,
            entityId: query.entityId,
            createdAt: {
                gte: query.dateFrom ? new Date(query.dateFrom) : undefined,
                lte: query.dateTo ? new Date(query.dateTo) : undefined,
            },
        };
        const [items, total] = await Promise.all([
            this.prisma.auditLog.findMany({
                where,
                include: { actor: { select: { id: true, fullName: true } } },
                orderBy: { createdAt: 'desc' },
                skip: (query.page - 1) * query.pageSize,
                take: query.pageSize,
            }),
            this.prisma.auditLog.count({ where }),
        ]);
        return {
            items: items.map((a) => ({
                id: a.id,
                actor: a.actor,
                action: a.action,
                entityType: a.entityType,
                entityId: a.entityId,
                changes: a.changes,
                ip: a.ip,
                createdAt: a.createdAt.toISOString(),
            })),
            total,
            page: query.page,
            pageSize: query.pageSize,
        };
    }
};
exports.AuditController = AuditController;
__decorate([
    (0, common_1.Get)(),
    (0, decorators_1.RequirePermission)('audit.read', 'ALL'),
    __param(0, (0, common_1.Query)((0, zod_pipe_1.zod)(contracts_1.auditListQuerySchema))),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object]),
    __metadata("design:returntype", Promise)
], AuditController.prototype, "list", null);
exports.AuditController = AuditController = __decorate([
    (0, common_1.Controller)('audit-logs'),
    __metadata("design:paramtypes", [prisma_service_1.PrismaService])
], AuditController);
//# sourceMappingURL=audit.controller.js.map
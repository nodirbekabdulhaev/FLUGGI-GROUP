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
exports.TimelineController = void 0;
const common_1 = require("@nestjs/common");
const contracts_1 = require("@fluggi/contracts");
const decorators_1 = require("../../core/auth/decorators");
const audit_service_1 = require("../../core/audit/audit.service");
const app_exception_1 = require("../../core/http/app.exception");
const uuid_pipe_1 = require("../../core/http/uuid.pipe");
const zod_pipe_1 = require("../../core/http/zod.pipe");
const prisma_service_1 = require("../../core/prisma/prisma.service");
const activity_service_1 = require("./activity.service");
const crm_access_service_1 = require("./crm-access.service");
/**
 * Таймлайн, история этапов и комментарии карточек лида/сделки/клиента.
 * Доступ проверяется по самой записи (право *.read с её областью).
 */
let TimelineController = class TimelineController {
    prisma;
    access;
    activity;
    audit;
    constructor(prisma, access, activity, audit) {
        this.prisma = prisma;
        this.access = access;
        this.activity = activity;
        this.audit = audit;
    }
    async assertAccess(auth, t) {
        if (t.leadId)
            await this.access.lead(auth, t.leadId);
        else if (t.dealId)
            await this.access.deal(auth, t.dealId);
        else if (t.clientId)
            await this.access.client(auth, t.clientId);
    }
    async timeline(auth, q) {
        await this.assertAccess(auth, q);
        let where = q;
        // Таймлайн сделки включает историю лида, из которого она возникла.
        if (q.dealId) {
            const lead = await this.prisma.lead.findFirst({
                where: { dealId: q.dealId },
                select: { id: true },
            });
            if (lead)
                where = { OR: [{ dealId: q.dealId }, { leadId: lead.id }] };
        }
        const rows = await this.prisma.activity.findMany({
            where,
            include: { actor: { select: { id: true, fullName: true } } },
            orderBy: { createdAt: 'desc' },
            take: 200,
        });
        return rows.map((a) => ({
            id: a.id,
            type: a.type,
            actor: a.actor ? { id: a.actor.id, name: a.actor.fullName } : null,
            payload: a.payload,
            createdAt: a.createdAt.toISOString(),
        }));
    }
    async stageHistory(auth, q) {
        await this.assertAccess(auth, q);
        if (q.clientId)
            return [];
        const rows = await this.prisma.stageHistory.findMany({
            where: q.leadId ? { leadId: q.leadId } : { dealId: q.dealId },
            include: {
                fromStage: true,
                toStage: true,
                changedBy: { select: { id: true, fullName: true } },
            },
            orderBy: { createdAt: 'desc' },
        });
        const ref = (s) => ({
            id: s.id,
            code: s.code,
            name: s.nameRu,
            color: s.color,
        });
        return rows.map((h) => ({
            id: h.id,
            from: h.fromStage ? ref(h.fromStage) : null,
            to: ref(h.toStage),
            changedBy: { id: h.changedBy.id, name: h.changedBy.fullName },
            durationSec: h.durationSec,
            createdAt: h.createdAt.toISOString(),
        }));
    }
    async comments(auth, q) {
        await this.assertAccess(auth, q);
        const rows = await this.prisma.comment.findMany({
            where: { ...q, deletedAt: null },
            include: { author: { select: { id: true, fullName: true } } },
            orderBy: { createdAt: 'desc' },
        });
        return rows.map((c) => ({
            id: c.id,
            author: { id: c.author.id, name: c.author.fullName },
            body: c.body,
            createdAt: c.createdAt.toISOString(),
            canDelete: c.authorId === auth.userId,
        }));
    }
    async addComment(auth, body) {
        const { body: text, ...target } = body;
        await this.assertAccess(auth, target);
        const c = await this.prisma.$transaction(async (tx) => {
            const created = await tx.comment.create({
                data: { ...target, body: text, authorId: auth.userId },
                include: { author: { select: { id: true, fullName: true } } },
            });
            await this.activity.log(tx, {
                type: 'comment.added',
                actorId: auth.userId,
                ...target,
                payload: { commentId: created.id, preview: text.slice(0, 140) },
            });
            return created;
        });
        return {
            id: c.id,
            author: { id: c.author.id, name: c.author.fullName },
            body: c.body,
            createdAt: c.createdAt.toISOString(),
            canDelete: true,
        };
    }
    /** Автор может скрыть свой комментарий (soft delete, след остаётся в аудите). */
    async deleteComment(auth, id, meta) {
        const c = await this.prisma.comment.findFirst({ where: { id, deletedAt: null } });
        if (!c)
            throw (0, app_exception_1.notFound)('Комментарий');
        if (c.authorId !== auth.userId)
            throw (0, app_exception_1.forbidden)('Удалить можно только свой комментарий');
        await this.prisma.$transaction(async (tx) => {
            await tx.comment.update({ where: { id }, data: { deletedAt: new Date() } });
            await this.audit.log(tx, {
                actorId: auth.userId,
                action: 'comment.delete',
                entityType: 'comment',
                entityId: id,
                changes: { body: { old: c.body, new: null } },
                meta,
            });
        });
    }
};
exports.TimelineController = TimelineController;
__decorate([
    (0, common_1.Get)('timeline'),
    (0, decorators_1.AuthenticatedOnly)(),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Query)((0, zod_pipe_1.zod)(contracts_1.timelineQuerySchema))),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object]),
    __metadata("design:returntype", Promise)
], TimelineController.prototype, "timeline", null);
__decorate([
    (0, common_1.Get)('stage-history'),
    (0, decorators_1.AuthenticatedOnly)(),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Query)((0, zod_pipe_1.zod)(contracts_1.timelineQuerySchema))),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object]),
    __metadata("design:returntype", Promise)
], TimelineController.prototype, "stageHistory", null);
__decorate([
    (0, common_1.Get)('comments'),
    (0, decorators_1.AuthenticatedOnly)(),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Query)((0, zod_pipe_1.zod)(contracts_1.timelineQuerySchema))),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object]),
    __metadata("design:returntype", Promise)
], TimelineController.prototype, "comments", null);
__decorate([
    (0, common_1.Post)('comments'),
    (0, decorators_1.AuthenticatedOnly)(),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.createCommentSchema))),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object]),
    __metadata("design:returntype", Promise)
], TimelineController.prototype, "addComment", null);
__decorate([
    (0, common_1.Delete)('comments/:id'),
    (0, decorators_1.AuthenticatedOnly)(),
    (0, common_1.HttpCode)(204),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __param(2, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String, Object]),
    __metadata("design:returntype", Promise)
], TimelineController.prototype, "deleteComment", null);
exports.TimelineController = TimelineController = __decorate([
    (0, common_1.Controller)(),
    __metadata("design:paramtypes", [prisma_service_1.PrismaService,
        crm_access_service_1.CrmAccessService,
        activity_service_1.ActivityService,
        audit_service_1.AuditService])
], TimelineController);
//# sourceMappingURL=timeline.controller.js.map
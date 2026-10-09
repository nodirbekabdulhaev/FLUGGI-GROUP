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
exports.PipelineController = void 0;
const common_1 = require("@nestjs/common");
const contracts_1 = require("@fluggi/contracts");
const domain_1 = require("@fluggi/domain");
const decorators_1 = require("../../core/auth/decorators");
const app_exception_1 = require("../../core/http/app.exception");
const serialize_1 = require("../../core/http/serialize");
const zod_pipe_1 = require("../../core/http/zod.pipe");
const prisma_service_1 = require("../../core/prisma/prisma.service");
const crm_access_service_1 = require("../crm/crm-access.service");
const references_service_1 = require("../references/references.service");
const CARDS_PER_COLUMN = 100;
/** Единая доска воронки: этапы лида, затем этапы сделки (ТЗ §7). */
let PipelineController = class PipelineController {
    prisma;
    access;
    constructor(prisma, access) {
        this.prisma = prisma;
        this.access = access;
    }
    async get(auth, q) {
        const canLeads = Boolean(auth.permissions['lead.read']);
        const canDeals = Boolean(auth.permissions['deal.read']);
        if (!canLeads && !canDeals)
            throw (0, app_exception_1.forbidden)();
        const common = { ownerId: q.ownerId, teamId: q.teamId, serviceId: q.serviceId };
        const stages = await this.prisma.dealStage.findMany({ orderBy: { sort: 'asc' } });
        const leads = canLeads
            ? await this.prisma.lead.findMany({
                where: {
                    AND: [this.access.leadWhere(auth), { status: 'OPEN', ...common, sourceId: q.sourceId }],
                },
                include: { owner: { select: { id: true, fullName: true } } },
                orderBy: { updatedAt: 'desc' },
            })
            : [];
        const deals = canDeals
            ? await this.prisma.deal.findMany({
                where: {
                    AND: [
                        this.access.dealWhere(auth),
                        {
                            status: 'OPEN',
                            ...common,
                            ...(q.sourceId ? { client: { sourceId: q.sourceId } } : {}),
                        },
                    ],
                },
                include: {
                    owner: { select: { id: true, fullName: true } },
                    client: { select: { name: true } },
                },
                orderBy: { updatedAt: 'desc' },
            })
            : [];
        const columns = [];
        for (const stage of stages) {
            if (stage.entity === 'LEAD' && !canLeads)
                continue;
            if (stage.entity === 'DEAL' && !canDeals)
                continue;
            let cards;
            let amounts;
            if (stage.entity === 'LEAD') {
                const rows = leads.filter((l) => l.stageId === stage.id);
                amounts = rows.map((l) => l.budgetUzs?.toString() ?? '0');
                cards = rows.map((l) => ({
                    id: l.id,
                    kind: 'lead',
                    number: (0, contracts_1.formatNumber)('L', l.number),
                    title: l.title,
                    subtitle: l.companyName ?? l.contactName,
                    amount: (0, serialize_1.dec)(l.budget),
                    currency: l.currency,
                    amountUzs: (0, serialize_1.dec)(l.budgetUzs),
                    owner: { id: l.owner.id, name: l.owner.fullName },
                    scoreLevel: l.scoreLevel,
                    nextContactAt: l.nextContactAt?.toISOString() ?? null,
                    updatedAt: l.updatedAt.toISOString(),
                }));
            }
            else {
                const rows = deals.filter((d) => d.stageId === stage.id);
                amounts = rows.map((d) => d.amountUzs.toString());
                cards = rows.map((d) => ({
                    id: d.id,
                    kind: 'deal',
                    number: (0, contracts_1.formatNumber)('D', d.number),
                    title: d.title,
                    subtitle: d.client.name,
                    amount: (0, serialize_1.dec)(d.amount),
                    currency: d.currency,
                    amountUzs: (0, serialize_1.dec)(d.amountUzs),
                    owner: { id: d.owner.id, name: d.owner.fullName },
                    scoreLevel: null,
                    nextContactAt: null,
                    updatedAt: d.updatedAt.toISOString(),
                }));
            }
            columns.push({
                stage: (0, references_service_1.toStageDto)(stage),
                items: cards.slice(0, CARDS_PER_COLUMN),
                count: cards.length,
                totalUzs: (0, domain_1.sum)(amounts).toFixed(2),
            });
        }
        const stageProb = new Map(stages.map((s) => [s.id, s.probability]));
        const f = (0, domain_1.forecast)(deals.map((d) => ({
            amountUzs: d.amountUzs.toString(),
            probability: d.probabilityOverride ?? stageProb.get(d.stageId) ?? 0,
        })));
        return { columns, pipelineUzs: f.pipeline.toFixed(2), weightedUzs: f.weighted.toFixed(2) };
    }
};
exports.PipelineController = PipelineController;
__decorate([
    (0, common_1.Get)(),
    (0, decorators_1.AuthenticatedOnly)(),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Query)((0, zod_pipe_1.zod)(contracts_1.pipelineQuerySchema))),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object]),
    __metadata("design:returntype", Promise)
], PipelineController.prototype, "get", null);
exports.PipelineController = PipelineController = __decorate([
    (0, common_1.Controller)('pipeline'),
    __metadata("design:paramtypes", [prisma_service_1.PrismaService,
        crm_access_service_1.CrmAccessService])
], PipelineController);
//# sourceMappingURL=pipeline.controller.js.map
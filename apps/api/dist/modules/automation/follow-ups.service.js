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
Object.defineProperty(exports, "__esModule", { value: true });
exports.FollowUpsService = void 0;
const common_1 = require("@nestjs/common");
const contracts_1 = require("@fluggi/contracts");
const domain_1 = require("@fluggi/domain");
const audit_service_1 = require("../../core/audit/audit.service");
const app_exception_1 = require("../../core/http/app.exception");
const serialize_1 = require("../../core/http/serialize");
const prisma_service_1 = require("../../core/prisma/prisma.service");
const settings_service_1 = require("../../core/settings/settings.service");
const crm_access_service_1 = require("../crm/crm-access.service");
const deals_service_1 = require("../deals/deals.service");
const include = {
    client: { select: { id: true, name: true } },
    project: { select: { id: true, number: true, name: true } },
    owner: { select: { id: true, fullName: true } },
    resultDeal: { select: { id: true, number: true, title: true } },
};
const KIND_TITLE = {
    CONTACT: 'Связаться с клиентом',
    NEW_PROJECT: 'Предложить новый проект',
    REPEAT_SALE: 'Повторная продажа',
};
/** Повторные продажи (ТЗ §38, Rule 8): follow-up после завершения проекта. */
let FollowUpsService = class FollowUpsService {
    prisma;
    settings;
    access;
    deals;
    audit;
    constructor(prisma, settings, access, deals, audit) {
        this.prisma = prisma;
        this.settings = settings;
        this.access = access;
        this.deals = deals;
        this.audit = audit;
    }
    static title(kind) {
        return KIND_TITLE[kind];
    }
    toDto(f) {
        const due = (0, serialize_1.dateOnly)(f.dueDate);
        return {
            id: f.id,
            client: f.client,
            project: f.project
                ? { id: f.project.id, name: f.project.name, number: (0, contracts_1.formatNumber)('P', f.project.number) }
                : null,
            owner: { id: f.owner.id, name: f.owner.fullName },
            kind: f.kind,
            dueDate: due,
            overdueDays: (0, domain_1.projectOverdueDays)(due, f.status === 'PENDING', new Date()),
            status: f.status,
            result: f.result,
            resultDeal: f.resultDeal
                ? {
                    id: f.resultDeal.id,
                    name: f.resultDeal.title,
                    number: (0, contracts_1.formatNumber)('D', f.resultDeal.number),
                }
                : null,
            completedAt: f.completedAt?.toISOString() ?? null,
        };
    }
    /** Создаёт follow-up по интервалам из настроек; повторный вызов для проекта ничего не делает. */
    async createForProject(tx, projectId) {
        const project = await tx.project.findUnique({ where: { id: projectId } });
        if (!project || project.status !== 'COMPLETED')
            return 0;
        if (await tx.followUp.count({ where: { projectId } }))
            return 0;
        const { followUps } = await this.settings.automation();
        const start = (0, domain_1.companyDate)(project.completedAt ?? new Date());
        await tx.followUp.createMany({
            data: followUps.map((f) => ({
                clientId: project.clientId,
                projectId,
                ownerId: project.managerId,
                kind: f.kind,
                dueDate: (0, serialize_1.parseDate)((0, domain_1.addDays)(start, f.days)),
            })),
        });
        return followUps.length;
    }
    where(auth) {
        if (!auth.permissions['client.read'])
            throw (0, app_exception_1.forbidden)();
        return { OR: [{ ownerId: auth.userId }, { client: this.access.clientWhere(auth) }] };
    }
    async list(auth, q) {
        const and = [this.where(auth)];
        if (q.status)
            and.push({ status: q.status });
        if (q.clientId)
            and.push({ clientId: q.clientId });
        if (q.due)
            and.push({ status: 'PENDING', dueDate: { lte: (0, serialize_1.parseDate)((0, domain_1.companyDate)(new Date())) } });
        const where = { AND: and };
        const [rows, total] = await Promise.all([
            this.prisma.followUp.findMany({
                where,
                include,
                orderBy: [{ status: 'asc' }, { dueDate: 'asc' }],
                skip: (q.page - 1) * q.pageSize,
                take: q.pageSize,
            }),
            this.prisma.followUp.count({ where }),
        ]);
        return { items: rows.map((r) => this.toDto(r)), total, page: q.page, pageSize: q.pageSize };
    }
    /** Отметить выполненным / пропустить; можно сразу создать сделку «Повторная продажа». */
    async complete(auth, id, input, meta) {
        const f = await this.prisma.followUp.findFirst({
            where: { AND: [this.where(auth), { id }] },
            include: { project: { include: { deal: true } } },
        });
        if (!f)
            throw (0, app_exception_1.notFound)('Follow-up');
        if (f.status !== 'PENDING')
            throw (0, app_exception_1.businessRule)('Follow-up уже закрыт');
        let dealId = null;
        if (input.createDeal) {
            if (input.status !== 'DONE')
                throw (0, app_exception_1.businessRule)('Сделку можно создать только при выполнении');
            const deal = await this.deals.create(auth, {
                clientId: f.clientId,
                title: `Повторная продажа${f.project ? ` — ${f.project.name}` : ''}`,
                serviceId: f.project?.deal.serviceId ?? undefined,
                amount: f.project ? f.project.priceUzs.toFixed(2) : '0',
                currency: 'UZS',
                isRepeat: true,
            }, meta);
            dealId = deal.id;
        }
        await this.prisma.$transaction(async (tx) => {
            await tx.followUp.update({
                where: { id },
                data: {
                    status: input.status,
                    result: input.result ?? null,
                    resultDealId: dealId,
                    completedAt: new Date(),
                    completedById: auth.userId,
                },
            });
            await this.audit.log(tx, {
                actorId: auth.userId,
                action: 'followup.complete',
                entityType: 'follow_up',
                entityId: id,
                changes: {
                    status: { old: 'PENDING', new: input.status },
                    dealId: { old: null, new: dealId },
                },
                meta,
            });
        });
        return this.toDto(await this.prisma.followUp.findUniqueOrThrow({ where: { id }, include }));
    }
};
exports.FollowUpsService = FollowUpsService;
exports.FollowUpsService = FollowUpsService = __decorate([
    (0, common_1.Injectable)(),
    __metadata("design:paramtypes", [prisma_service_1.PrismaService,
        settings_service_1.SettingsService,
        crm_access_service_1.CrmAccessService,
        deals_service_1.DealsService,
        audit_service_1.AuditService])
], FollowUpsService);
//# sourceMappingURL=follow-ups.service.js.map
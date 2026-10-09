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
exports.ProposalsService = void 0;
const common_1 = require("@nestjs/common");
const domain_1 = require("@fluggi/domain");
const audit_service_1 = require("../../core/audit/audit.service");
const app_exception_1 = require("../../core/http/app.exception");
const serialize_1 = require("../../core/http/serialize");
const outbox_service_1 = require("../../core/outbox/outbox.service");
const prisma_service_1 = require("../../core/prisma/prisma.service");
const activity_service_1 = require("../crm/activity.service");
const crm_access_service_1 = require("../crm/crm-access.service");
const deals_service_1 = require("../deals/deals.service");
const exchange_rate_service_1 = require("../references/exchange-rate.service");
const proposals_mapper_1 = require("./proposals.mapper");
const EDITABLE = ['DRAFT', 'IN_APPROVAL', 'SENT', 'VIEWED', 'REJECTED'];
let ProposalsService = class ProposalsService {
    prisma;
    access;
    activity;
    audit;
    outbox;
    rates;
    deals;
    constructor(prisma, access, activity, audit, outbox, rates, deals) {
        this.prisma = prisma;
        this.access = access;
        this.activity = activity;
        this.audit = audit;
        this.outbox = outbox;
        this.rates = rates;
        this.deals = deals;
    }
    /** КП видно тем, кто видит сделку (и имеет proposal.read). */
    where(auth) {
        return { deal: this.access.dealWhere(auth, 'proposal.read') };
    }
    async list(auth, q) {
        const and = [this.where(auth)];
        if (q.status)
            and.push({ status: q.status });
        if (q.dealId)
            and.push({ dealId: q.dealId });
        if (q.managerId)
            and.push({ managerId: q.managerId });
        if (q.q)
            and.push({
                OR: [{ title: { contains: q.q } }, { client: { name: { contains: q.q } } }],
            });
        const where = { AND: and };
        const [items, total] = await Promise.all([
            this.prisma.proposal.findMany({
                where,
                include: proposals_mapper_1.proposalInclude,
                orderBy: { updatedAt: 'desc' },
                skip: (q.page - 1) * q.pageSize,
                take: q.pageSize,
            }),
            this.prisma.proposal.count({ where }),
        ]);
        return { items: items.map(proposals_mapper_1.toProposalDto), total, page: q.page, pageSize: q.pageSize };
    }
    async find(auth, id, code = 'proposal.read') {
        const p = await this.prisma.proposal.findFirst({
            where: { id, deal: this.access.dealWhere(auth, code) },
        });
        if (!p)
            throw (0, app_exception_1.notFound)('КП');
        return p;
    }
    async get(auth, id) {
        await this.find(auth, id);
        return (0, proposals_mapper_1.toProposalDto)(await this.prisma.proposal.findUniqueOrThrow({ where: { id }, include: proposals_mapper_1.proposalInclude }));
    }
    async versions(auth, id) {
        await this.find(auth, id);
        const rows = await this.prisma.proposalVersion.findMany({
            where: { proposalId: id },
            include: { author: { select: { id: true, fullName: true } } },
            orderBy: { version: 'desc' },
        });
        return rows.map((v) => ({
            version: v.version,
            total: v.total.toFixed(2),
            currency: v.currency,
            author: { id: v.author.id, name: v.author.fullName },
            comment: v.comment,
            createdAt: v.createdAt.toISOString(),
        }));
    }
    async compute(auth, input) {
        // Тариф определяет услугу позиции; архивный тариф в новое КП не добавить
        const tariffIds = [
            ...new Set(input.items.map((i) => i.tariffId).filter((x) => Boolean(x))),
        ];
        if (tariffIds.length) {
            const tariffs = await this.prisma.tariff.findMany({ where: { id: { in: tariffIds } } });
            for (const [idx, item] of input.items.entries()) {
                if (!item.tariffId)
                    continue;
                const t = tariffs.find((x) => x.id === item.tariffId);
                if (!t || !t.isActive)
                    throw (0, app_exception_1.businessRule)('Тариф не найден или отключён', [
                        { path: `items.${idx}.tariffId`, message: 'Выберите действующий тариф' },
                    ]);
                item.serviceId = t.serviceId;
                // Цену и скидку по тарифу меняет только РОП/CEO (право утверждать КП); себестоимость
                // исполнителей от цены не зависит, а KPI и комиссии считаются от фактической цены.
                if (!auth.permissions['proposal.approve']) {
                    const rate = Number(await this.rates.rateFor('USD'));
                    const expected = t.currency === input.currency
                        ? Number(t.price)
                        : t.currency === 'USD'
                            ? Number(t.price) * rate
                            : Number(t.price) / rate;
                    const price = Number(item.unitPrice);
                    const tolerance = t.currency === input.currency ? 0.01 : expected * 0.01;
                    if (Math.abs(price - expected) > tolerance || Number(item.discountPct ?? 0) > 0)
                        throw (0, app_exception_1.businessRule)('Цену тарифа меняет РОП', [
                            {
                                path: `items.${idx}.unitPrice`,
                                message: 'Цена и скидка по тарифу — как в тарифе; изменить может РОП',
                            },
                        ]);
                }
            }
        }
        const totals = (0, domain_1.proposalTotals)(input.items);
        const rate = await this.rates.rateFor(input.currency);
        return {
            totals,
            rate,
            totalUzs: (0, domain_1.toUzs)(totals.total.toString(), input.currency, rate).toFixed(2),
        };
    }
    /** Новая версия = снимок шапки и позиций (ТЗ §16). */
    async snapshot(tx, proposalId, version, authorId, comment) {
        const p = await tx.proposal.findUniqueOrThrow({
            where: { id: proposalId },
            include: proposals_mapper_1.proposalInclude,
        });
        const dto = (0, proposals_mapper_1.toProposalDto)(p);
        await tx.proposalVersion.create({
            data: {
                proposalId,
                version,
                snapshot: dto,
                total: p.total,
                currency: p.currency,
                authorId,
                comment: comment ?? null,
            },
        });
        return dto;
    }
    async create(auth, input, meta) {
        const deal = await this.access.deal(auth, input.dealId, 'proposal.create');
        if (deal.status !== 'OPEN')
            throw (0, app_exception_1.businessRule)('Сделка закрыта');
        const { totals, rate, totalUzs } = await this.compute(auth, input);
        return this.prisma.$transaction(async (tx) => {
            const p = await tx.proposal.create({
                data: {
                    dealId: deal.id,
                    clientId: deal.clientId,
                    managerId: deal.ownerId,
                    title: input.title,
                    description: input.description ?? null,
                    currency: input.currency,
                    subtotal: totals.subtotal.toFixed(2),
                    discountAmount: totals.discountAmount.toFixed(2),
                    total: totals.total.toFixed(2),
                    exchangeRate: rate,
                    totalUzs,
                    implementationTerm: input.implementationTerm ?? null,
                    paymentTerms: input.paymentTerms ?? null,
                    validUntil: (0, serialize_1.parseDate)(input.validUntil),
                    items: {
                        create: input.items.map((i, idx) => ({
                            serviceId: i.serviceId ?? null,
                            tariffId: i.tariffId ?? null,
                            description: i.description,
                            quantity: i.quantity,
                            unitPrice: i.unitPrice,
                            discountPct: i.discountPct,
                            total: totals.lines[idx].total.toFixed(2),
                            sort: idx,
                        })),
                    },
                },
            });
            const dto = await this.snapshot(tx, p.id, 1, auth.userId, input.versionComment ?? 'Создано');
            await this.activity.log(tx, {
                type: 'proposal.created',
                actorId: auth.userId,
                dealId: deal.id,
                payload: { number: dto.number, total: dto.total, currency: dto.currency },
            });
            await this.audit.log(tx, {
                actorId: auth.userId,
                action: 'proposal.create',
                entityType: 'proposal',
                entityId: p.id,
                changes: { total: { old: null, new: dto.total } },
                meta,
            });
            return dto;
        });
    }
    /** Любое изменение → новая версия; отправленное КП возвращается в черновик и требует повторной отправки. */
    async update(auth, id, input, meta) {
        const before = await this.find(auth, id, 'proposal.update');
        if (!EDITABLE.includes(before.status))
            throw (0, app_exception_1.businessRule)('Принятое КП изменить нельзя — создайте новое');
        const { totals, rate, totalUzs } = await this.compute(auth, input);
        return this.prisma.$transaction(async (tx) => {
            await tx.proposalItem.deleteMany({ where: { proposalId: id } });
            const version = before.currentVersion + 1;
            await tx.proposal.update({
                where: { id },
                data: {
                    title: input.title,
                    description: input.description ?? null,
                    currency: input.currency,
                    subtotal: totals.subtotal.toFixed(2),
                    discountAmount: totals.discountAmount.toFixed(2),
                    total: totals.total.toFixed(2),
                    exchangeRate: rate,
                    totalUzs,
                    implementationTerm: input.implementationTerm ?? null,
                    paymentTerms: input.paymentTerms ?? null,
                    validUntil: (0, serialize_1.parseDate)(input.validUntil),
                    currentVersion: version,
                    status: 'DRAFT',
                    approvedById: null,
                    approvedAt: null,
                    items: {
                        create: input.items.map((i, idx) => ({
                            serviceId: i.serviceId ?? null,
                            tariffId: i.tariffId ?? null,
                            description: i.description,
                            quantity: i.quantity,
                            unitPrice: i.unitPrice,
                            discountPct: i.discountPct,
                            total: totals.lines[idx].total.toFixed(2),
                            sort: idx,
                        })),
                    },
                },
            });
            const dto = await this.snapshot(tx, id, version, auth.userId, input.versionComment);
            await this.activity.log(tx, {
                type: 'proposal.updated',
                actorId: auth.userId,
                dealId: before.dealId,
                payload: {
                    number: dto.number,
                    version,
                    old: before.total.toFixed(2),
                    new: dto.total,
                    currency: dto.currency,
                },
            });
            await this.audit.log(tx, {
                actorId: auth.userId,
                action: 'proposal.update',
                entityType: 'proposal',
                entityId: id,
                changes: {
                    total: { old: before.total.toFixed(2), new: dto.total },
                    version: { old: before.currentVersion, new: version },
                },
                meta,
            });
            return dto;
        });
    }
    async transition(auth, p, data, action, meta, after) {
        return this.prisma.$transaction(async (tx) => {
            await tx.proposal.update({ where: { id: p.id }, data });
            await this.activity.log(tx, {
                type: `proposal.${action}`,
                actorId: auth.userId,
                dealId: p.dealId,
                payload: { proposalId: p.id, title: p.title },
            });
            await this.audit.log(tx, {
                actorId: auth.userId,
                action: `proposal.${action}`,
                entityType: 'proposal',
                entityId: p.id,
                changes: data.status ? { status: { old: p.status, new: data.status } } : null,
                meta,
            });
            if (after)
                await after(tx);
            return (0, proposals_mapper_1.toProposalDto)(await tx.proposal.findUniqueOrThrow({ where: { id: p.id }, include: proposals_mapper_1.proposalInclude }));
        });
    }
    async submitApproval(auth, id, meta) {
        const p = await this.find(auth, id, 'proposal.update');
        if (p.status !== 'DRAFT')
            throw (0, app_exception_1.businessRule)('На согласование отправляется черновик');
        const deal = await this.prisma.deal.findUniqueOrThrow({ where: { id: p.dealId } });
        return this.transition(auth, p, { status: 'IN_APPROVAL' }, 'approval_requested', meta, (tx) => this.outbox.publish(tx, 'proposal.approval_requested', { proposalId: id, dealId: p.dealId, teamId: deal.teamId }, auth.userId));
    }
    /** РОП утверждает КП (ТЗ §3.2). */
    async approve(auth, id, meta) {
        const p = await this.find(auth, id, 'proposal.approve');
        if (!['DRAFT', 'IN_APPROVAL'].includes(p.status))
            throw (0, app_exception_1.businessRule)('Утвердить можно черновик или КП на согласовании');
        return this.transition(auth, p, { status: 'DRAFT', approvedById: auth.userId, approvedAt: new Date() }, 'approved', meta);
    }
    async send(auth, id, meta) {
        const p = await this.find(auth, id, 'proposal.send');
        if (p.status === 'IN_APPROVAL')
            throw (0, app_exception_1.businessRule)('КП на согласовании у РОП — дождитесь утверждения');
        if (!['DRAFT', 'REJECTED'].includes(p.status))
            throw (0, app_exception_1.businessRule)('КП уже отправлено');
        return this.transition(auth, p, { status: 'SENT', sentAt: new Date() }, 'sent', meta, async (tx) => {
            await this.deals.advanceTo(tx, p.dealId, 'PROPOSAL_SENT', auth.userId);
            await this.outbox.publish(tx, 'proposal.sent', { proposalId: id, dealId: p.dealId }, auth.userId);
        });
    }
    async markViewed(auth, id, meta) {
        const p = await this.find(auth, id, 'proposal.update');
        if (p.status !== 'SENT')
            throw (0, app_exception_1.businessRule)('Отметить просмотр можно у отправленного КП');
        return this.transition(auth, p, { status: 'VIEWED', viewedAt: new Date() }, 'viewed', meta);
    }
    /** Клиент принял КП: сделка → «Переговоры», сумма сделки = итог КП. */
    async accept(auth, id, meta) {
        const p = await this.find(auth, id, 'proposal.update');
        if (!['SENT', 'VIEWED'].includes(p.status))
            throw (0, app_exception_1.businessRule)('Принять можно отправленное КП');
        return this.transition(auth, p, { status: 'ACCEPTED', acceptedAt: new Date() }, 'accepted', meta, async (tx) => {
            const deal = await tx.deal.findUniqueOrThrow({ where: { id: p.dealId } });
            if (!deal.amount.eq(p.total) || deal.currency !== p.currency) {
                await tx.deal.update({
                    where: { id: deal.id },
                    data: {
                        amount: p.total,
                        currency: p.currency,
                        exchangeRate: p.exchangeRate,
                        amountUzs: p.totalUzs,
                    },
                });
                await this.activity.log(tx, {
                    type: 'deal.amount_changed',
                    actorId: auth.userId,
                    dealId: deal.id,
                    payload: {
                        changes: { amount: { old: deal.amount.toFixed(2), new: p.total.toFixed(2) } },
                        reason: 'КП принято',
                    },
                });
            }
            await this.deals.advanceTo(tx, p.dealId, 'NEGOTIATION', auth.userId);
            await this.outbox.publish(tx, 'proposal.accepted', { proposalId: id, dealId: p.dealId }, auth.userId);
        });
    }
    async reject(auth, id, meta) {
        const p = await this.find(auth, id, 'proposal.update');
        if (!['SENT', 'VIEWED'].includes(p.status))
            throw (0, app_exception_1.businessRule)('Отклонить можно отправленное КП');
        return this.transition(auth, p, { status: 'REJECTED', rejectedAt: new Date() }, 'rejected', meta);
    }
};
exports.ProposalsService = ProposalsService;
exports.ProposalsService = ProposalsService = __decorate([
    (0, common_1.Injectable)(),
    __metadata("design:paramtypes", [prisma_service_1.PrismaService,
        crm_access_service_1.CrmAccessService,
        activity_service_1.ActivityService,
        audit_service_1.AuditService,
        outbox_service_1.OutboxService,
        exchange_rate_service_1.ExchangeRateService,
        deals_service_1.DealsService])
], ProposalsService);
//# sourceMappingURL=proposals.service.js.map
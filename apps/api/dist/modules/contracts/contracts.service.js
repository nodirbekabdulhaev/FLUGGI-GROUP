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
exports.ContractsService = void 0;
const common_1 = require("@nestjs/common");
const contracts_1 = require("@fluggi/contracts");
const domain_1 = require("@fluggi/domain");
const audit_service_1 = require("../../core/audit/audit.service");
const app_exception_1 = require("../../core/http/app.exception");
const serialize_1 = require("../../core/http/serialize");
const outbox_service_1 = require("../../core/outbox/outbox.service");
const prisma_service_1 = require("../../core/prisma/prisma.service");
const activity_service_1 = require("../crm/activity.service");
const crm_access_service_1 = require("../crm/crm-access.service");
const deals_service_1 = require("../deals/deals.service");
const files_service_1 = require("../files/files.service");
const exchange_rate_service_1 = require("../references/exchange-rate.service");
const include = {
    deal: { select: { id: true, number: true, title: true } },
    client: { select: { id: true, name: true } },
    proposal: { select: { id: true, number: true, title: true } },
    createdBy: { select: { id: true, fullName: true } },
    files: {
        where: { deletedAt: null },
        include: { uploadedBy: { select: { id: true, fullName: true } } },
    },
    payments: { where: { status: 'PAID' }, select: { amountUzs: true, type: true } },
};
const toDto = (c) => ({
    id: c.id,
    number: (0, contracts_1.formatNumber)('C', c.number).replace(/^C-/, 'ДГ-'),
    deal: { id: c.deal.id, name: c.deal.title, number: (0, contracts_1.formatNumber)('D', c.deal.number) },
    client: c.client,
    proposal: c.proposal
        ? { id: c.proposal.id, name: c.proposal.title, number: (0, contracts_1.formatNumber)('KP', c.proposal.number) }
        : null,
    contractDate: (0, serialize_1.dateOnly)(c.contractDate),
    amount: (0, serialize_1.decReq)(c.amount),
    currency: c.currency,
    amountUzs: (0, serialize_1.decReq)(c.amountUzs),
    status: c.status,
    signedAt: (0, serialize_1.iso)(c.signedAt),
    comment: c.comment,
    paidUzs: (0, domain_1.sum)(c.payments.map((p) => (p.type === 'REFUND' ? p.amountUzs.neg() : p.amountUzs).toString())).toFixed(2),
    files: c.files.map(files_service_1.toFileDto),
    createdBy: { id: c.createdBy.id, name: c.createdBy.fullName },
    createdAt: c.createdAt.toISOString(),
});
const TRANSITIONS = {
    send: { from: ['DRAFT'], to: 'SENT' },
    'submit-approval': { from: ['DRAFT', 'SENT'], to: 'IN_APPROVAL' },
    sign: { from: ['DRAFT', 'SENT', 'IN_APPROVAL'], to: 'SIGNED' },
    cancel: { from: ['DRAFT', 'SENT', 'IN_APPROVAL', 'SIGNED'], to: 'CANCELLED' },
};
let ContractsService = class ContractsService {
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
    async list(auth, q) {
        const and = [
            { deal: this.access.dealWhere(auth, 'contract.read') },
        ];
        if (q.status)
            and.push({ status: q.status });
        if (q.dealId)
            and.push({ dealId: q.dealId });
        if (q.q)
            and.push({
                OR: [{ client: { name: { contains: q.q } } }, { deal: { title: { contains: q.q } } }],
            });
        const where = { AND: and };
        const [items, total] = await Promise.all([
            this.prisma.contract.findMany({
                where,
                include,
                orderBy: { createdAt: 'desc' },
                skip: (q.page - 1) * q.pageSize,
                take: q.pageSize,
            }),
            this.prisma.contract.count({ where }),
        ]);
        return { items: items.map(toDto), total, page: q.page, pageSize: q.pageSize };
    }
    async find(auth, id, code = 'contract.read') {
        const c = await this.prisma.contract.findFirst({
            where: { id, deal: this.access.dealWhere(auth, code) },
        });
        if (!c)
            throw (0, app_exception_1.notFound)('Договор');
        return c;
    }
    async get(auth, id) {
        await this.find(auth, id);
        return toDto(await this.prisma.contract.findUniqueOrThrow({ where: { id }, include }));
    }
    /** Договор по сделке; из принятого КП сумма берётся из КП. Сделка → «Договор». */
    async create(auth, input, meta) {
        const deal = await this.access.deal(auth, input.dealId, 'contract.create');
        if (deal.status !== 'OPEN')
            throw (0, app_exception_1.businessRule)('Сделка закрыта');
        if (input.proposalId) {
            const p = await this.prisma.proposal.findFirst({
                where: { id: input.proposalId, dealId: deal.id },
            });
            if (!p)
                throw (0, app_exception_1.businessRule)('КП не относится к этой сделке');
            if (p.status !== 'ACCEPTED')
                throw (0, app_exception_1.businessRule)('Договор создаётся по принятому КП');
        }
        const { rate, amountUzs } = await this.rates.convert(input.amount, input.currency);
        return this.prisma.$transaction(async (tx) => {
            const c = await tx.contract.create({
                data: {
                    dealId: deal.id,
                    clientId: deal.clientId,
                    proposalId: input.proposalId,
                    contractDate: (0, serialize_1.parseDate)(input.contractDate),
                    amount: input.amount,
                    currency: input.currency,
                    exchangeRate: rate,
                    amountUzs,
                    comment: input.comment,
                    createdById: auth.userId,
                },
                include,
            });
            await this.activity.log(tx, {
                type: 'contract.created',
                actorId: auth.userId,
                dealId: deal.id,
                payload: { number: toDto(c).number, amount: input.amount, currency: input.currency },
            });
            await this.deals.advanceTo(tx, deal.id, 'CONTRACT', auth.userId);
            await this.audit.log(tx, {
                actorId: auth.userId,
                action: 'contract.create',
                entityType: 'contract',
                entityId: c.id,
                changes: { amount: { old: null, new: input.amount } },
                meta,
            });
            return toDto(c);
        });
    }
    async update(auth, id, input, meta) {
        const before = await this.find(auth, id, 'contract.update');
        if (before.status === 'SIGNED' || before.status === 'CANCELLED')
            throw (0, app_exception_1.businessRule)('Подписанный или отменённый договор изменить нельзя');
        const data = {
            ...input,
            contractDate: (0, serialize_1.parseDate)(input.contractDate) ?? undefined,
        };
        if (input.amount || input.currency) {
            const conv = await this.rates.convert(input.amount ?? before.amount.toString(), input.currency ?? before.currency);
            data.exchangeRate = conv.rate;
            data.amountUzs = conv.amountUzs;
        }
        return this.prisma.$transaction(async (tx) => {
            const after = await tx.contract.update({ where: { id }, data, include });
            const changes = (0, audit_service_1.diffFields)(before, after, ['contractDate', 'amount', 'currency', 'comment']);
            if (changes)
                await this.audit.log(tx, {
                    actorId: auth.userId,
                    action: 'contract.update',
                    entityType: 'contract',
                    entityId: id,
                    changes,
                    meta,
                });
            return toDto(after);
        });
    }
    async transition(auth, id, action, meta) {
        const c = await this.find(auth, id, 'contract.update');
        const t = TRANSITIONS[action];
        if (!t.from.includes(c.status))
            throw (0, app_exception_1.businessRule)('Это действие недоступно для договора в текущем статусе');
        if (action === 'cancel') {
            const paid = await this.prisma.payment.count({ where: { contractId: id, status: 'PAID' } });
            if (paid)
                throw (0, app_exception_1.businessRule)('По договору есть подтверждённые оплаты — отменить нельзя');
        }
        return this.prisma.$transaction(async (tx) => {
            const after = await tx.contract.update({
                where: { id },
                data: { status: t.to, ...(t.to === 'SIGNED' ? { signedAt: new Date() } : {}) },
                include,
            });
            await this.activity.log(tx, {
                type: `contract.${t.to === 'SIGNED' ? 'signed' : t.to.toLowerCase()}`,
                actorId: auth.userId,
                dealId: c.dealId,
                payload: { number: toDto(after).number },
            });
            await this.audit.log(tx, {
                actorId: auth.userId,
                action: `contract.${action}`,
                entityType: 'contract',
                entityId: id,
                changes: { status: { old: c.status, new: t.to } },
                meta,
            });
            if (t.to === 'SIGNED') {
                const deal = await tx.deal.findUniqueOrThrow({ where: { id: c.dealId } });
                await this.deals.advanceTo(tx, c.dealId, 'AWAITING_PAYMENT', auth.userId);
                await this.outbox.publish(tx, 'contract.signed', { contractId: id, dealId: c.dealId, managerId: deal.ownerId, teamId: deal.teamId }, auth.userId);
            }
            return toDto(after);
        });
    }
};
exports.ContractsService = ContractsService;
exports.ContractsService = ContractsService = __decorate([
    (0, common_1.Injectable)(),
    __metadata("design:paramtypes", [prisma_service_1.PrismaService,
        crm_access_service_1.CrmAccessService,
        activity_service_1.ActivityService,
        audit_service_1.AuditService,
        outbox_service_1.OutboxService,
        exchange_rate_service_1.ExchangeRateService,
        deals_service_1.DealsService])
], ContractsService);
//# sourceMappingURL=contracts.service.js.map
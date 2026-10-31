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
exports.DealsService = void 0;
const common_1 = require("@nestjs/common");
const contracts_1 = require("@fluggi/contracts");
const audit_service_1 = require("../../core/audit/audit.service");
const app_exception_1 = require("../../core/http/app.exception");
const serialize_1 = require("../../core/http/serialize");
const outbox_service_1 = require("../../core/outbox/outbox.service");
const prisma_service_1 = require("../../core/prisma/prisma.service");
const activity_service_1 = require("../crm/activity.service");
const crm_access_service_1 = require("../crm/crm-access.service");
const exchange_rate_service_1 = require("../references/exchange-rate.service");
const deals_mapper_1 = require("./deals.mapper");
const TRACKED = [
    'title',
    'amount',
    'currency',
    'serviceId',
    'contactId',
    'expectedCloseDate',
    'probabilityOverride',
];
let DealsService = class DealsService {
    prisma;
    access;
    activity;
    audit;
    outbox;
    rates;
    constructor(prisma, access, activity, audit, outbox, rates) {
        this.prisma = prisma;
        this.access = access;
        this.activity = activity;
        this.audit = audit;
        this.outbox = outbox;
        this.rates = rates;
    }
    async list(auth, q) {
        const and = [this.access.dealWhere(auth)];
        if (q.q) {
            and.push({
                OR: [
                    { title: { contains: q.q } },
                    { client: { name: { contains: q.q } } },
                    ...(/^\d+$/.test(q.q.replace(/^D-/i, ''))
                        ? [{ number: Number(q.q.replace(/^D-/i, '')) }]
                        : []),
                ],
            });
        }
        if (q.stageCode)
            and.push({ stage: { code: q.stageCode } });
        if (q.status)
            and.push({ status: q.status });
        if (q.ownerId)
            and.push({ ownerId: q.ownerId });
        if (q.teamId)
            and.push({ teamId: q.teamId });
        if (q.clientId)
            and.push({ clientId: q.clientId });
        if (q.serviceId)
            and.push({ serviceId: q.serviceId });
        if (q.amountMin !== undefined)
            and.push({ amountUzs: { gte: q.amountMin } });
        if (q.amountMax !== undefined)
            and.push({ amountUzs: { lte: q.amountMax } });
        if (q.dateFrom)
            and.push({ createdAt: { gte: new Date(`${q.dateFrom}T00:00:00+05:00`) } });
        if (q.dateTo)
            and.push({
                createdAt: { lt: new Date(new Date(`${q.dateTo}T00:00:00+05:00`).getTime() + 86_400_000) },
            });
        const where = { AND: and };
        const [items, total] = await Promise.all([
            this.prisma.deal.findMany({
                where,
                include: deals_mapper_1.dealInclude,
                orderBy: { createdAt: 'desc' },
                skip: (q.page - 1) * q.pageSize,
                take: q.pageSize,
            }),
            this.prisma.deal.count({ where }),
        ]);
        return { items: items.map(deals_mapper_1.toDealDto), total, page: q.page, pageSize: q.pageSize };
    }
    async get(auth, id) {
        await this.access.deal(auth, id);
        return (0, deals_mapper_1.toDealDto)(await this.prisma.deal.findUniqueOrThrow({ where: { id }, include: deals_mapper_1.dealInclude }));
    }
    /** Новая сделка у существующего клиента (повторная продажа, ТЗ §63). */
    async create(auth, input, meta) {
        const client = await this.access.client(auth, input.clientId);
        const owner = await this.access.assignableOwner(auth, input.ownerId ?? client.ownerId, 'deal.create');
        if (input.contactId)
            await this.assertContact(client.id, input.contactId);
        const { rate, amountUzs } = await this.rates.convert(input.amount, input.currency);
        const stage = await this.prisma.dealStage.findUniqueOrThrow({
            where: { code: 'NEED_DEFINED' },
        });
        const previous = await this.prisma.deal.count({
            where: { clientId: client.id, deletedAt: null },
        });
        return this.prisma.$transaction(async (tx) => {
            const deal = await tx.deal.create({
                data: {
                    title: input.title,
                    clientId: client.id,
                    contactId: input.contactId,
                    ownerId: owner.id,
                    teamId: owner.teamId,
                    serviceId: input.serviceId,
                    amount: input.amount,
                    currency: input.currency,
                    exchangeRate: rate,
                    amountUzs,
                    stageId: stage.id,
                    expectedCloseDate: (0, serialize_1.parseDate)(input.expectedCloseDate),
                    isRepeat: input.isRepeat ?? previous > 0,
                    createdById: auth.userId,
                },
            });
            await this.activity.stageChange(tx, { dealId: deal.id }, null, stage.id, auth.userId);
            await this.activity.log(tx, {
                type: 'deal.created',
                actorId: auth.userId,
                dealId: deal.id,
                clientId: client.id,
                payload: { amount: input.amount, currency: input.currency, client: client.name },
            });
            await this.audit.log(tx, {
                actorId: auth.userId,
                action: 'deal.create',
                entityType: 'deal',
                entityId: deal.id,
                changes: {
                    amount: { old: null, new: input.amount },
                    clientId: { old: null, new: client.id },
                },
                meta,
            });
            await this.outbox.publish(tx, 'deal.created', { dealId: deal.id, ownerId: owner.id, teamId: owner.teamId, amountUzs }, auth.userId);
            return (0, deals_mapper_1.toDealDto)(await tx.deal.findUniqueOrThrow({ where: { id: deal.id }, include: deals_mapper_1.dealInclude }));
        });
    }
    async update(auth, id, input, meta) {
        const before = await this.access.deal(auth, id, 'deal.update');
        if (input.contactId)
            await this.assertContact(before.clientId, input.contactId);
        const data = {
            ...input,
            expectedCloseDate: (0, serialize_1.parseDate)(input.expectedCloseDate),
        };
        if (input.amount !== undefined || input.currency !== undefined) {
            const { rate, amountUzs } = await this.rates.convert(input.amount ?? before.amount.toString(), input.currency ?? before.currency);
            data.exchangeRate = rate;
            data.amountUzs = amountUzs;
        }
        return this.prisma.$transaction(async (tx) => {
            const after = await tx.deal.update({ where: { id }, data });
            const changes = (0, audit_service_1.diffFields)(before, after, TRACKED);
            if (changes) {
                // «Изменена сумма» — отдельное событие таймлайна (ТЗ §12)
                await this.activity.log(tx, {
                    type: changes.amount || changes.currency ? 'deal.amount_changed' : 'deal.updated',
                    actorId: auth.userId,
                    dealId: id,
                    payload: { changes },
                });
                await this.audit.log(tx, {
                    actorId: auth.userId,
                    action: 'deal.update',
                    entityType: 'deal',
                    entityId: id,
                    changes,
                    meta,
                });
            }
            return (0, deals_mapper_1.toDealDto)(await tx.deal.findUniqueOrThrow({ where: { id }, include: deals_mapper_1.dealInclude }));
        });
    }
    async assign(auth, id, ownerId, meta) {
        const before = await this.access.deal(auth, id, 'deal.update');
        // Передать сделку другому менеджеру может тот, кто распределяет лиды (РОП/CEO).
        const owner = await this.access.assignableOwner(auth, ownerId, 'lead.assign');
        return this.prisma.$transaction(async (tx) => {
            await tx.deal.update({ where: { id }, data: { ownerId: owner.id, teamId: owner.teamId } });
            await this.activity.log(tx, {
                type: 'deal.assigned',
                actorId: auth.userId,
                dealId: id,
                payload: { to: owner.fullName },
            });
            await this.audit.log(tx, {
                actorId: auth.userId,
                action: 'deal.assign',
                entityType: 'deal',
                entityId: id,
                changes: { ownerId: { old: before.ownerId, new: owner.id } },
                meta,
            });
            return (0, deals_mapper_1.toDealDto)(await tx.deal.findUniqueOrThrow({ where: { id }, include: deals_mapper_1.dealInclude }));
        });
    }
    /**
     * Переход по этапам сделки с проверкой документов (BUSINESS_RULES §3).
     * «Оплачено» ставится только подтверждением оплаты (ТЗ Rule 3). Назад — без проверок.
     */
    async changeStage(auth, id, code, meta) {
        const deal = await this.access.deal(auth, id, 'deal.change_stage');
        return this.prisma.$transaction(async (tx) => {
            await this.moveStage(tx, deal, code, auth.userId, meta);
            return (0, deals_mapper_1.toDealDto)(await tx.deal.findUniqueOrThrow({ where: { id }, include: deals_mapper_1.dealInclude }));
        });
    }
    async moveStage(tx, deal, code, actorId, meta, auto = false) {
        if (deal.status !== 'OPEN' && !(auto && code === 'PAID')) {
            throw (0, app_exception_1.businessRule)('Сделка закрыта. Сначала верните её в работу');
        }
        if (code === 'PAID' && !auto) {
            throw (0, app_exception_1.businessRule)('Этап «Оплачено» устанавливается автоматически при подтверждении оплаты');
        }
        const target = await tx.dealStage.findUnique({ where: { code } });
        if (!target)
            throw (0, app_exception_1.notFound)('Этап');
        if (target.id === deal.stageId)
            return;
        const from = await tx.dealStage.findUniqueOrThrow({ where: { id: deal.stageId } });
        const forward = contracts_1.DEAL_STAGE_CODES.indexOf(code) > contracts_1.DEAL_STAGE_CODES.indexOf(from.code);
        if (forward && !auto)
            await this.checkGate(tx, deal, code);
        await tx.deal.update({ where: { id: deal.id }, data: { stageId: target.id } });
        await this.activity.stageChange(tx, { dealId: deal.id }, from.id, target.id, actorId);
        await this.activity.log(tx, {
            type: 'deal.stage_changed',
            actorId,
            dealId: deal.id,
            payload: { from: from.nameRu, to: target.nameRu, auto },
        });
        if (meta) {
            await this.audit.log(tx, {
                actorId,
                action: 'deal.stage_change',
                entityType: 'deal',
                entityId: deal.id,
                changes: { stage: { old: from.code, new: target.code } },
                meta,
            });
        }
        await this.outbox.publish(tx, 'deal.stage_changed', { dealId: deal.id, from: from.code, to: target.code }, actorId);
    }
    /** Двигает сделку вперёд (не назад) — для автоматических переходов от КП, договоров и оплат. */
    async advanceTo(tx, dealId, code, actorId) {
        const deal = await tx.deal.findUniqueOrThrow({
            where: { id: dealId },
            include: { stage: true },
        });
        if (deal.status !== 'OPEN' && code !== 'PAID')
            return;
        if (contracts_1.DEAL_STAGE_CODES.indexOf(deal.stage.code) >= contracts_1.DEAL_STAGE_CODES.indexOf(code))
            return;
        await this.moveStage(tx, deal, code, actorId, undefined, true);
    }
    async checkGate(tx, deal, code) {
        const idx = contracts_1.DEAL_STAGE_CODES.indexOf(code);
        if (idx >= contracts_1.DEAL_STAGE_CODES.indexOf('PROPOSAL_SENT')) {
            if (deal.amount.lte(0)) {
                throw (0, app_exception_1.businessRule)('Укажите сумму сделки', [
                    { path: 'amount', message: 'Сумма должна быть больше нуля' },
                ]);
            }
            const sent = await tx.proposal.count({
                where: { dealId: deal.id, status: { in: ['SENT', 'VIEWED', 'ACCEPTED'] } },
            });
            if (!sent)
                throw (0, app_exception_1.businessRule)('Сначала отправьте клиенту КП');
        }
        if (idx >= contracts_1.DEAL_STAGE_CODES.indexOf('CONTRACT')) {
            const contracts = await tx.contract.count({
                where: { dealId: deal.id, status: { not: 'CANCELLED' } },
            });
            if (!contracts)
                throw (0, app_exception_1.businessRule)('Сначала создайте договор');
        }
        if (idx >= contracts_1.DEAL_STAGE_CODES.indexOf('AWAITING_PAYMENT')) {
            const signed = await tx.contract.count({ where: { dealId: deal.id, status: 'SIGNED' } });
            if (!signed)
                throw (0, app_exception_1.businessRule)('Договор ещё не подписан');
        }
    }
    async close(auth, id, input, meta) {
        const deal = await this.access.deal(auth, id, 'deal.update');
        if (deal.status !== 'OPEN')
            throw (0, app_exception_1.businessRule)('Сделка уже закрыта');
        const reason = input.lossReasonId
            ? await this.prisma.lossReason.findFirst({
                where: { id: input.lossReasonId, isActive: true },
            })
            : null;
        if (input.lossReasonId && !reason)
            throw (0, app_exception_1.businessRule)('Причина не найдена');
        if (reason?.requiresComment && !input.comment) {
            throw (0, app_exception_1.businessRule)('Для этой причины нужен комментарий', [
                { path: 'comment', message: 'Опишите причину' },
            ]);
        }
        return this.prisma.$transaction(async (tx) => {
            await tx.deal.update({
                where: { id },
                data: {
                    status: input.status,
                    lossReasonId: reason?.id ?? null,
                    lossComment: input.comment ?? null,
                    closedAt: new Date(),
                },
            });
            await this.activity.log(tx, {
                type: 'deal.closed',
                actorId: auth.userId,
                dealId: id,
                payload: {
                    status: input.status,
                    reason: reason?.nameRu ?? null,
                    comment: input.comment ?? null,
                },
            });
            await this.audit.log(tx, {
                actorId: auth.userId,
                action: 'deal.close',
                entityType: 'deal',
                entityId: id,
                changes: {
                    status: { old: deal.status, new: input.status },
                    lossReason: { old: null, new: reason?.code ?? null },
                },
                meta,
            });
            if (input.status === 'LOST' || input.status === 'REJECTED') {
                await this.outbox.publish(tx, 'deal.lost', {
                    dealId: id,
                    ownerId: deal.ownerId,
                    teamId: deal.teamId,
                    amountUzs: deal.amountUzs.toFixed(2),
                    reason: reason?.nameRu ?? null,
                }, auth.userId);
            }
            return (0, deals_mapper_1.toDealDto)(await tx.deal.findUniqueOrThrow({ where: { id }, include: deals_mapper_1.dealInclude }));
        });
    }
    async reopen(auth, id, meta) {
        const deal = await this.access.deal(auth, id, 'deal.update');
        if (deal.status === 'OPEN')
            return this.get(auth, id);
        if (deal.status === 'WON')
            throw (0, app_exception_1.businessRule)('Оплаченную сделку нельзя вернуть в работу');
        return this.prisma.$transaction(async (tx) => {
            await tx.deal.update({
                where: { id },
                data: { status: 'OPEN', lossReasonId: null, lossComment: null, closedAt: null },
            });
            await this.activity.log(tx, {
                type: 'deal.reopened',
                actorId: auth.userId,
                dealId: id,
                payload: { from: deal.status },
            });
            await this.audit.log(tx, {
                actorId: auth.userId,
                action: 'deal.reopen',
                entityType: 'deal',
                entityId: id,
                changes: { status: { old: deal.status, new: 'OPEN' } },
                meta,
            });
            return (0, deals_mapper_1.toDealDto)(await tx.deal.findUniqueOrThrow({ where: { id }, include: deals_mapper_1.dealInclude }));
        });
    }
    /** Сделки — критичные данные (ТЗ §66): только soft delete и только без оплат. */
    async remove(auth, id, meta) {
        const deal = await this.access.deal(auth, id, 'deal.delete');
        if (deal.status === 'WON')
            throw (0, app_exception_1.businessRule)('Оплаченную сделку удалить нельзя');
        await this.prisma.$transaction(async (tx) => {
            await tx.deal.update({ where: { id }, data: { deletedAt: new Date() } });
            await this.audit.log(tx, {
                actorId: auth.userId,
                action: 'deal.delete',
                entityType: 'deal',
                entityId: id,
                changes: { title: { old: deal.title, new: null } },
                meta,
            });
        });
    }
    async assertContact(clientId, contactId) {
        const c = await this.prisma.contact.findFirst({ where: { id: contactId, clientId } });
        if (!c)
            throw (0, app_exception_1.businessRule)('Контакт не принадлежит клиенту', [
                { path: 'contactId', message: 'Выберите контакт клиента' },
            ]);
    }
};
exports.DealsService = DealsService;
exports.DealsService = DealsService = __decorate([
    (0, common_1.Injectable)(),
    __metadata("design:paramtypes", [prisma_service_1.PrismaService,
        crm_access_service_1.CrmAccessService,
        activity_service_1.ActivityService,
        audit_service_1.AuditService,
        outbox_service_1.OutboxService,
        exchange_rate_service_1.ExchangeRateService])
], DealsService);
//# sourceMappingURL=deals.service.js.map
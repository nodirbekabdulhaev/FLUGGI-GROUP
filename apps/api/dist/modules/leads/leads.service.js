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
exports.LeadsService = void 0;
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
const exchange_rate_service_1 = require("../references/exchange-rate.service");
const leads_mapper_1 = require("./leads.mapper");
const OPEN_MEETING = ['SCHEDULED', 'CONFIRMED', 'RESCHEDULED'];
/** Поля, изменения которых видны в таймлайне и аудите. */
const TRACKED = [
    'title',
    'contactName',
    'companyName',
    'phone',
    'telegram',
    'email',
    'sourceId',
    'serviceId',
    'budget',
    'currency',
    'priority',
    'desiredDate',
    'nextContactAt',
    'companySize',
    'interest',
];
let LeadsService = class LeadsService {
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
    // ─── чтение ───
    async list(auth, q) {
        const and = [this.access.leadWhere(auth)];
        if (q.q) {
            and.push({
                OR: ['title', 'contactName', 'companyName', 'phone', 'telegram', 'email'].map((f) => ({
                    [f]: { contains: q.q },
                })),
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
        if (q.sourceId)
            and.push({ sourceId: q.sourceId });
        if (q.serviceId)
            and.push({ serviceId: q.serviceId });
        if (q.scoreLevel)
            and.push({ scoreLevel: q.scoreLevel });
        if (q.dateFrom)
            and.push({ createdAt: { gte: new Date(`${q.dateFrom}T00:00:00+05:00`) } });
        if (q.dateTo)
            and.push({
                createdAt: { lt: new Date(new Date(`${q.dateTo}T00:00:00+05:00`).getTime() + 86_400_000) },
            });
        const where = { AND: and };
        const [items, total] = await Promise.all([
            this.prisma.lead.findMany({
                where,
                include: leads_mapper_1.leadInclude,
                orderBy: [{ createdAt: 'desc' }],
                skip: (q.page - 1) * q.pageSize,
                take: q.pageSize,
            }),
            this.prisma.lead.count({ where }),
        ]);
        return { items: items.map(leads_mapper_1.toLeadDto), total, page: q.page, pageSize: q.pageSize };
    }
    async get(auth, id) {
        await this.access.lead(auth, id);
        return (0, leads_mapper_1.toLeadDto)(await this.prisma.lead.findUniqueOrThrow({ where: { id }, include: leads_mapper_1.leadInclude }));
    }
    // ─── создание и изменение ───
    async create(auth, input, meta) {
        const owner = await this.access.assignableOwner(auth, input.ownerId, 'lead.create');
        const [service, source, stage] = await Promise.all([
            this.prisma.service.findFirst({ where: { id: input.serviceId, isActive: true } }),
            this.prisma.leadSource.findFirst({ where: { id: input.sourceId, isActive: true } }),
            this.stage('NEW'),
        ]);
        if (!service)
            throw (0, app_exception_1.businessRule)('Услуга не найдена', [{ path: 'serviceId', message: 'Выберите услугу' }]);
        if (!source)
            throw (0, app_exception_1.businessRule)('Источник не найден', [
                { path: 'sourceId', message: 'Выберите источник' },
            ]);
        const budgetUzs = input.budget
            ? (await this.rates.convert(input.budget, input.currency)).amountUzs
            : null;
        const title = input.title ?? `${input.companyName ?? input.contactName} — ${service.nameRu}`;
        return this.prisma.$transaction(async (tx) => {
            const lead = await tx.lead.create({
                data: {
                    title,
                    contactName: input.contactName,
                    companyName: input.companyName,
                    phone: input.phone,
                    telegram: input.telegram,
                    whatsapp: input.whatsapp,
                    instagram: input.instagram,
                    email: input.email,
                    website: input.website,
                    city: input.city,
                    country: input.country,
                    sourceId: source.id,
                    serviceId: service.id,
                    ownerId: owner.id,
                    teamId: owner.teamId,
                    budget: input.budget,
                    currency: input.currency,
                    budgetUzs,
                    desiredDate: (0, serialize_1.parseDate)(input.desiredDate),
                    priority: input.priority,
                    companySize: input.companySize,
                    interest: input.interest,
                    nextContactAt: input.nextContactAt ? new Date(input.nextContactAt) : undefined,
                    comment: input.comment,
                    stageId: stage.id,
                    createdById: auth.userId,
                },
            });
            await this.rescore(tx, lead.id);
            await this.activity.stageChange(tx, { leadId: lead.id }, null, stage.id, auth.userId);
            await this.activity.log(tx, {
                type: 'lead.created',
                actorId: auth.userId,
                leadId: lead.id,
                payload: { owner: owner.fullName, source: source.nameRu, service: service.nameRu },
            });
            await this.audit.log(tx, {
                actorId: auth.userId,
                action: 'lead.create',
                entityType: 'lead',
                entityId: lead.id,
                changes: { title: { old: null, new: title }, ownerId: { old: null, new: owner.id } },
                meta,
            });
            await this.outbox.publish(tx, 'lead.created', {
                leadId: lead.id,
                ownerId: owner.id,
                teamId: owner.teamId,
                createdById: auth.userId,
                budgetUzs,
            }, auth.userId);
            return (0, leads_mapper_1.toLeadDto)(await tx.lead.findUniqueOrThrow({ where: { id: lead.id }, include: leads_mapper_1.leadInclude }));
        });
    }
    /**
     * Лид из внешнего канала (форма сайта, Instagram, таргет): без пользователя-автора,
     * ответственный выбран заранее. Источник — по коду справочника.
     */
    async createInbound(input) {
        const [source, stage, owner] = await Promise.all([
            input.sourceId
                ? this.prisma.leadSource.findUnique({ where: { id: input.sourceId } })
                : this.prisma.leadSource.findUnique({ where: { code: input.sourceCode } }),
            this.stage('NEW'),
            this.prisma.user.findUniqueOrThrow({ where: { id: input.ownerId } }),
        ]);
        const src = source ?? (await this.prisma.leadSource.findFirstOrThrow({ where: { code: 'OTHER' } }));
        return this.prisma.$transaction(async (tx) => {
            const lead = await tx.lead.create({
                data: {
                    title: input.title.slice(0, 200),
                    contactName: input.contactName ?? null,
                    companyName: input.companyName ?? null,
                    phone: input.phone ?? null,
                    email: input.email ?? null,
                    instagram: input.instagram ?? null,
                    comment: input.comment ?? null,
                    sourceId: src.id,
                    serviceId: input.serviceId ?? null,
                    ownerId: owner.id,
                    teamId: owner.teamId,
                    stageId: stage.id,
                    createdById: owner.id,
                },
            });
            await this.rescore(tx, lead.id);
            await this.activity.stageChange(tx, { leadId: lead.id }, null, stage.id, owner.id);
            await this.activity.log(tx, {
                type: 'lead.created',
                actorId: null,
                leadId: lead.id,
                payload: { owner: owner.fullName, source: src.nameRu, channel: input.channel },
            });
            await this.outbox.publish(tx, 'lead.created', {
                leadId: lead.id,
                ownerId: owner.id,
                teamId: owner.teamId,
                createdById: owner.id,
                budgetUzs: null,
            }, null);
            return { id: lead.id, number: lead.number, title: lead.title };
        });
    }
    async update(auth, id, input, meta) {
        const before = await this.access.lead(auth, id, 'lead.update');
        const data = {
            ...input,
            desiredDate: (0, serialize_1.parseDate)(input.desiredDate),
            nextContactAt: input.nextContactAt === undefined
                ? undefined
                : input.nextContactAt
                    ? new Date(input.nextContactAt)
                    : null,
        };
        if (input.budget !== undefined || input.currency !== undefined) {
            const budget = input.budget === undefined ? (before.budget?.toString() ?? null) : input.budget;
            const currency = input.currency ?? before.currency;
            data.budgetUzs = budget ? (await this.rates.convert(budget, currency)).amountUzs : null;
        }
        if (input.sourceId) {
            const src = await this.prisma.leadSource.findUnique({ where: { id: input.sourceId } });
            if (!src)
                throw (0, app_exception_1.businessRule)('Источник не найден');
        }
        return this.prisma.$transaction(async (tx) => {
            const after = await tx.lead.update({ where: { id }, data });
            await this.rescore(tx, id);
            const changes = (0, audit_service_1.diffFields)(before, after, TRACKED);
            if (changes) {
                await this.activity.log(tx, {
                    type: 'lead.updated',
                    actorId: auth.userId,
                    leadId: id,
                    payload: { changes },
                });
                await this.audit.log(tx, {
                    actorId: auth.userId,
                    action: 'lead.update',
                    entityType: 'lead',
                    entityId: id,
                    changes,
                    meta,
                });
            }
            return (0, leads_mapper_1.toLeadDto)(await tx.lead.findUniqueOrThrow({ where: { id }, include: leads_mapper_1.leadInclude }));
        });
    }
    async assign(auth, id, ownerId, meta) {
        const before = await this.access.lead(auth, id, 'lead.assign');
        const owner = await this.access.assignableOwner(auth, ownerId, 'lead.assign');
        if (before.ownerId === owner.id)
            return this.get(auth, id);
        return this.prisma.$transaction(async (tx) => {
            await tx.lead.update({ where: { id }, data: { ownerId: owner.id, teamId: owner.teamId } });
            await this.activity.log(tx, {
                type: 'lead.assigned',
                actorId: auth.userId,
                leadId: id,
                payload: { to: owner.fullName },
            });
            await this.audit.log(tx, {
                actorId: auth.userId,
                action: 'lead.assign',
                entityType: 'lead',
                entityId: id,
                changes: { ownerId: { old: before.ownerId, new: owner.id } },
                meta,
            });
            await this.outbox.publish(tx, 'lead.assigned', { leadId: id, ownerId: owner.id, previousOwnerId: before.ownerId }, auth.userId);
            return (0, leads_mapper_1.toLeadDto)(await tx.lead.findUniqueOrThrow({ where: { id }, include: leads_mapper_1.leadInclude }));
        });
    }
    // ─── воронка ───
    async changeStage(auth, id, code, meta) {
        const lead = await this.access.lead(auth, id, 'lead.update');
        return this.prisma.$transaction(async (tx) => {
            await this.moveStage(tx, lead, code, auth.userId, meta);
            return (0, leads_mapper_1.toLeadDto)(await tx.lead.findUniqueOrThrow({ where: { id }, include: leads_mapper_1.leadInclude }));
        });
    }
    /**
     * Переход лида по этапам с проверкой обязательных данных (BUSINESS_RULES §3).
     * Используется и встречами: назначение/проведение встречи двигает лид автоматически.
     */
    async moveStage(tx, lead, code, actorId, meta, auto = false) {
        if (lead.status !== 'OPEN')
            throw (0, app_exception_1.businessRule)('Лид закрыт. Сначала верните его в работу');
        const target = await this.stage(code, tx);
        if (target.id === lead.stageId)
            return;
        if (code === 'MEETING_SCHEDULED') {
            const has = await tx.meeting.count({
                where: { leadId: lead.id, status: { in: [...OPEN_MEETING] } },
            });
            if (!has)
                throw (0, app_exception_1.businessRule)('Сначала назначьте встречу с клиентом');
        }
        if (code === 'MEETING_DONE') {
            const has = await tx.meeting.count({ where: { leadId: lead.id, status: 'DONE' } });
            if (!has)
                throw (0, app_exception_1.businessRule)('Отметьте встречу проведённой и укажите результат');
        }
        const from = await tx.dealStage.findUniqueOrThrow({ where: { id: lead.stageId } });
        await tx.lead.update({
            where: { id: lead.id },
            data: { stageId: target.id, ...(code === 'CONTACTED' ? { lastContactAt: new Date() } : {}) },
        });
        await this.rescore(tx, lead.id);
        await this.activity.stageChange(tx, { leadId: lead.id }, from.id, target.id, actorId);
        await this.activity.log(tx, {
            type: 'lead.stage_changed',
            actorId,
            leadId: lead.id,
            payload: { from: from.nameRu, to: target.nameRu, auto },
        });
        if (meta) {
            await this.audit.log(tx, {
                actorId,
                action: 'lead.stage_change',
                entityType: 'lead',
                entityId: lead.id,
                changes: { stage: { old: from.code, new: target.code } },
                meta,
            });
        }
    }
    /** Двигает лид вперёд (не назад) — для автоматических переходов от встреч. */
    async advanceTo(tx, leadId, code, actorId) {
        const lead = await tx.lead.findUniqueOrThrow({
            where: { id: leadId },
            include: { stage: true },
        });
        if (lead.status !== 'OPEN')
            return;
        const current = contracts_1.LEAD_STAGE_CODES.indexOf(lead.stage.code);
        if (current >= contracts_1.LEAD_STAGE_CODES.indexOf(code))
            return;
        await this.moveStage(tx, lead, code, actorId, undefined, true);
    }
    async close(auth, id, input, meta) {
        const lead = await this.access.lead(auth, id, 'lead.update');
        if (lead.status !== 'OPEN')
            throw (0, app_exception_1.businessRule)('Лид уже закрыт');
        const reason = await this.checkReason(input);
        return this.prisma.$transaction(async (tx) => {
            await tx.lead.update({
                where: { id },
                data: {
                    status: input.status,
                    lossReasonId: reason?.id ?? null,
                    lossComment: input.comment ?? null,
                    closedAt: new Date(),
                },
            });
            await this.activity.log(tx, {
                type: 'lead.closed',
                actorId: auth.userId,
                leadId: id,
                payload: {
                    status: input.status,
                    reason: reason?.nameRu ?? null,
                    comment: input.comment ?? null,
                },
            });
            await this.audit.log(tx, {
                actorId: auth.userId,
                action: 'lead.close',
                entityType: 'lead',
                entityId: id,
                changes: {
                    status: { old: lead.status, new: input.status },
                    lossReason: { old: null, new: reason?.code ?? null },
                },
                meta,
            });
            await this.outbox.publish(tx, 'lead.closed', { leadId: id, status: input.status }, auth.userId);
            return (0, leads_mapper_1.toLeadDto)(await tx.lead.findUniqueOrThrow({ where: { id }, include: leads_mapper_1.leadInclude }));
        });
    }
    async reopen(auth, id, meta) {
        const lead = await this.access.lead(auth, id, 'lead.update');
        if (lead.status === 'OPEN')
            return this.get(auth, id);
        if (lead.status === 'CONVERTED')
            throw (0, app_exception_1.businessRule)('Лид уже переведён в сделку');
        return this.prisma.$transaction(async (tx) => {
            await tx.lead.update({
                where: { id },
                data: { status: 'OPEN', lossReasonId: null, lossComment: null, closedAt: null },
            });
            await this.activity.log(tx, {
                type: 'lead.reopened',
                actorId: auth.userId,
                leadId: id,
                payload: { from: lead.status },
            });
            await this.audit.log(tx, {
                actorId: auth.userId,
                action: 'lead.reopen',
                entityType: 'lead',
                entityId: id,
                changes: { status: { old: lead.status, new: 'OPEN' } },
                meta,
            });
            return (0, leads_mapper_1.toLeadDto)(await tx.lead.findUniqueOrThrow({ where: { id }, include: leads_mapper_1.leadInclude }));
        });
    }
    /** Квалификация: лид → клиент (новый или существующий) + сделка (BUSINESS_RULES §2). */
    async convert(auth, id, input, meta) {
        const lead = await this.access.lead(auth, id, 'lead.update');
        if (!auth.permissions['deal.create'])
            throw (0, app_exception_1.businessRule)('Нет права создавать сделки');
        if (lead.status !== 'OPEN')
            throw (0, app_exception_1.businessRule)('Конвертировать можно только лид в работе');
        const existingClient = input.clientId ? await this.access.client(auth, input.clientId) : null;
        const { rate, amountUzs } = await this.rates.convert(input.amount, input.currency);
        const dealStage = await this.stage('NEED_DEFINED');
        const service = lead.serviceId
            ? await this.prisma.service.findUnique({ where: { id: lead.serviceId } })
            : null;
        return this.prisma.$transaction(async (tx) => {
            const client = existingClient ??
                (await tx.client.create({
                    data: {
                        name: input.clientName,
                        type: input.clientType,
                        phone: lead.phone,
                        email: lead.email,
                        telegram: lead.telegram,
                        website: lead.website,
                        city: lead.city,
                        country: lead.country,
                        ownerId: lead.ownerId,
                        teamId: lead.teamId,
                        sourceId: lead.sourceId,
                        contacts: lead.contactName
                            ? {
                                create: {
                                    fullName: lead.contactName,
                                    phone: lead.phone,
                                    telegram: lead.telegram,
                                    whatsapp: lead.whatsapp,
                                    instagram: lead.instagram,
                                    email: lead.email,
                                    isPrimary: true,
                                },
                            }
                            : undefined,
                    },
                    include: { contacts: true },
                }));
            const contactId = 'contacts' in client ? client.contacts[0]?.id : undefined;
            const isRepeat = existingClient
                ? (await tx.deal.count({ where: { clientId: client.id } })) > 0
                : false;
            const deal = await tx.deal.create({
                data: {
                    title: input.title ?? `${service?.nameRu ?? lead.title} — ${client.name}`,
                    clientId: client.id,
                    contactId,
                    ownerId: lead.ownerId,
                    teamId: lead.teamId,
                    serviceId: lead.serviceId,
                    amount: input.amount,
                    currency: input.currency,
                    exchangeRate: rate,
                    amountUzs,
                    stageId: dealStage.id,
                    expectedCloseDate: (0, serialize_1.parseDate)(input.expectedCloseDate),
                    isRepeat,
                    createdById: auth.userId,
                },
            });
            await tx.lead.update({
                where: { id },
                data: {
                    status: 'CONVERTED',
                    clientId: client.id,
                    dealId: deal.id,
                    convertedAt: new Date(),
                    closedAt: new Date(),
                },
            });
            await this.activity.stageChange(tx, { dealId: deal.id }, null, dealStage.id, auth.userId);
            await this.activity.log(tx, {
                type: 'lead.converted',
                actorId: auth.userId,
                leadId: id,
                dealId: deal.id,
                clientId: client.id,
                payload: {
                    client: client.name,
                    amount: input.amount,
                    currency: input.currency,
                    newClient: !existingClient,
                },
            });
            await this.audit.log(tx, {
                actorId: auth.userId,
                action: 'lead.convert',
                entityType: 'lead',
                entityId: id,
                changes: {
                    status: { old: 'OPEN', new: 'CONVERTED' },
                    dealId: { old: null, new: deal.id },
                    clientId: { old: null, new: client.id },
                },
                meta,
            });
            await this.outbox.publish(tx, 'lead.converted', { leadId: id, dealId: deal.id, clientId: client.id }, auth.userId);
            await this.outbox.publish(tx, 'deal.created', { dealId: deal.id, ownerId: deal.ownerId, teamId: deal.teamId, amountUzs }, auth.userId);
            return { leadId: id, clientId: client.id, dealId: deal.id };
        });
    }
    async remove(auth, id, meta) {
        const lead = await this.access.lead(auth, id, 'lead.delete');
        if (lead.status === 'CONVERTED')
            throw (0, app_exception_1.businessRule)('Лид переведён в сделку — удалить нельзя');
        await this.prisma.$transaction(async (tx) => {
            await tx.lead.update({ where: { id }, data: { deletedAt: new Date() } });
            await this.audit.log(tx, {
                actorId: auth.userId,
                action: 'lead.delete',
                entityType: 'lead',
                entityId: id,
                changes: { title: { old: lead.title, new: null } },
                meta,
            });
        });
    }
    // ─── вспомогательное ───
    async stage(code, tx = this.prisma) {
        const stage = await tx.dealStage.findUnique({ where: { code } });
        if (!stage)
            throw (0, app_exception_1.notFound)('Этап воронки');
        return stage;
    }
    async checkReason(input) {
        if (!input.lossReasonId)
            return null;
        const reason = await this.prisma.lossReason.findFirst({
            where: { id: input.lossReasonId, isActive: true },
        });
        if (!reason)
            throw (0, app_exception_1.businessRule)('Причина не найдена', [
                { path: 'lossReasonId', message: 'Выберите причину' },
            ]);
        if (reason.requiresComment && !input.comment) {
            throw (0, app_exception_1.businessRule)('Для этой причины нужен комментарий', [
                { path: 'comment', message: 'Опишите причину' },
            ]);
        }
        return reason;
    }
    /** Пересчёт lead score (ТЗ §10) после любых изменений, влияющих на факторы. */
    async rescore(tx, id) {
        const lead = await tx.lead.findUniqueOrThrow({
            where: { id },
            include: { stage: true, service: true },
        });
        let minPriceUzs = null;
        if (lead.service?.minPrice) {
            const rate = lead.service.currency === 'USD'
                ? (await tx.exchangeRate.findFirst({
                    where: { currency: 'USD' },
                    orderBy: { date: 'desc' },
                }))?.rateToUzs
                : 1;
            if (rate)
                minPriceUzs = (0, domain_1.toUzs)(lead.service.minPrice.toString(), lead.service.currency, rate.toString()).toNumber();
        }
        const days = lead.desiredDate
            ? Math.round((lead.desiredDate.getTime() - Date.now()) / 86_400_000)
            : null;
        const { score, level } = (0, domain_1.computeLeadScore)({
            budgetUzs: lead.budgetUzs ? Number(lead.budgetUzs) : null,
            serviceMinPriceUzs: minPriceUzs,
            hasService: Boolean(lead.serviceId),
            priority: lead.priority,
            daysToDesiredDate: days,
            companySize: lead.companySize,
            interest: lead.interest,
            stageIndex: Math.max(0, contracts_1.LEAD_STAGE_CODES.indexOf(lead.stage.code)),
            stageCount: contracts_1.LEAD_STAGE_CODES.length,
        });
        if (score !== lead.score || level !== lead.scoreLevel) {
            await tx.lead.update({ where: { id }, data: { score, scoreLevel: level } });
        }
    }
};
exports.LeadsService = LeadsService;
exports.LeadsService = LeadsService = __decorate([
    (0, common_1.Injectable)(),
    __metadata("design:paramtypes", [prisma_service_1.PrismaService,
        crm_access_service_1.CrmAccessService,
        activity_service_1.ActivityService,
        audit_service_1.AuditService,
        outbox_service_1.OutboxService,
        exchange_rate_service_1.ExchangeRateService])
], LeadsService);
//# sourceMappingURL=leads.service.js.map
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
exports.MeetingsService = void 0;
const common_1 = require("@nestjs/common");
const contracts_1 = require("@fluggi/contracts");
const audit_service_1 = require("../../core/audit/audit.service");
const app_exception_1 = require("../../core/http/app.exception");
const outbox_service_1 = require("../../core/outbox/outbox.service");
const prisma_service_1 = require("../../core/prisma/prisma.service");
const activity_service_1 = require("../crm/activity.service");
const crm_access_service_1 = require("../crm/crm-access.service");
const leads_service_1 = require("../leads/leads.service");
const meetingInclude = {
    lead: { select: { id: true, number: true, title: true } },
    deal: { select: { id: true, number: true, title: true } },
    client: { select: { id: true, name: true } },
    manager: { select: { id: true, fullName: true } },
    rop: { select: { id: true, fullName: true } },
};
const toDto = (m) => ({
    id: m.id,
    lead: m.lead
        ? { id: m.lead.id, name: m.lead.title, number: (0, contracts_1.formatNumber)('L', m.lead.number) }
        : null,
    deal: m.deal
        ? { id: m.deal.id, name: m.deal.title, number: (0, contracts_1.formatNumber)('D', m.deal.number) }
        : null,
    client: m.client,
    manager: { id: m.manager.id, name: m.manager.fullName },
    rop: m.rop ? { id: m.rop.id, name: m.rop.fullName } : null,
    startsAt: m.startsAt.toISOString(),
    durationMin: m.durationMin,
    type: m.type,
    link: m.link,
    status: m.status,
    comment: m.comment,
    result: m.result,
    createdAt: m.createdAt.toISOString(),
});
let MeetingsService = class MeetingsService {
    prisma;
    access;
    activity;
    audit;
    outbox;
    leads;
    constructor(prisma, access, activity, audit, outbox, leads) {
        this.prisma = prisma;
        this.access = access;
        this.activity = activity;
        this.audit = audit;
        this.outbox = outbox;
        this.leads = leads;
    }
    async list(auth, q) {
        const and = [this.access.meetingWhere(auth)];
        if (q.status)
            and.push({ status: q.status });
        if (q.managerId)
            and.push({ managerId: q.managerId });
        if (q.leadId)
            and.push({ leadId: q.leadId });
        if (q.dealId)
            and.push({ dealId: q.dealId });
        if (q.dateFrom)
            and.push({ startsAt: { gte: new Date(`${q.dateFrom}T00:00:00+05:00`) } });
        if (q.dateTo)
            and.push({
                startsAt: { lt: new Date(new Date(`${q.dateTo}T00:00:00+05:00`).getTime() + 86_400_000) },
            });
        const where = { AND: and };
        const [items, total] = await Promise.all([
            this.prisma.meeting.findMany({
                where,
                include: meetingInclude,
                orderBy: { startsAt: q.status === 'DONE' ? 'desc' : 'asc' },
                skip: (q.page - 1) * q.pageSize,
                take: q.pageSize,
            }),
            this.prisma.meeting.count({ where }),
        ]);
        return { items: items.map(toDto), total, page: q.page, pageSize: q.pageSize };
    }
    async find(auth, id, code = 'meeting.read') {
        const m = await this.prisma.meeting.findFirst({
            where: { AND: [this.access.meetingWhere(auth, code), { id }] },
        });
        if (!m)
            throw (0, app_exception_1.notFound)('Встреча');
        return m;
    }
    /**
     * Встреча по лиду или сделке. Менеджер — ответственный за запись, РОП — руководитель его отдела.
     * Назначение встречи по лиду автоматически переводит лид на этап «Назначена встреча».
     */
    async create(auth, input, meta) {
        const lead = input.leadId ? await this.access.lead(auth, input.leadId) : null;
        const deal = input.dealId ? await this.access.deal(auth, input.dealId) : null;
        const record = (lead ?? deal);
        if (record.status !== 'OPEN')
            throw (0, app_exception_1.businessRule)('Нельзя назначить встречу по закрытой записи');
        const team = record.teamId
            ? await this.prisma.team.findUnique({ where: { id: record.teamId } })
            : null;
        return this.prisma.$transaction(async (tx) => {
            const m = await tx.meeting.create({
                data: {
                    leadId: lead?.id,
                    dealId: deal?.id,
                    clientId: deal?.clientId ?? lead?.clientId ?? null,
                    managerId: record.ownerId,
                    ropId: team?.headId ?? null,
                    teamId: record.teamId,
                    startsAt: new Date(input.startsAt),
                    durationMin: input.durationMin,
                    type: input.type,
                    link: input.link,
                    comment: input.comment,
                    createdById: auth.userId,
                },
                include: meetingInclude,
            });
            await this.activity.log(tx, {
                type: 'meeting.created',
                actorId: auth.userId,
                leadId: lead?.id,
                dealId: deal?.id,
                meetingId: m.id,
                payload: { startsAt: m.startsAt.toISOString(), type: m.type },
            });
            if (lead)
                await this.leads.advanceTo(tx, lead.id, 'MEETING_SCHEDULED', auth.userId);
            await this.audit.log(tx, {
                actorId: auth.userId,
                action: 'meeting.create',
                entityType: 'meeting',
                entityId: m.id,
                changes: { startsAt: { old: null, new: m.startsAt.toISOString() } },
                meta,
            });
            await this.outbox.publish(tx, 'meeting.created', {
                meetingId: m.id,
                managerId: m.managerId,
                ropId: m.ropId,
                startsAt: m.startsAt.toISOString(),
            }, auth.userId);
            return toDto(m);
        });
    }
    async update(auth, id, input, meta) {
        const before = await this.find(auth, id, 'meeting.update');
        if (before.status === 'DONE')
            throw (0, app_exception_1.businessRule)('Проведённую встречу изменить нельзя');
        const startsAt = input.startsAt ? new Date(input.startsAt) : undefined;
        const status = input.status ??
            (startsAt && startsAt.getTime() !== before.startsAt.getTime() ? 'RESCHEDULED' : undefined);
        return this.prisma.$transaction(async (tx) => {
            const after = await tx.meeting.update({
                where: { id },
                data: { ...input, startsAt, status },
                include: meetingInclude,
            });
            const changes = (0, audit_service_1.diffFields)(before, after, [
                'startsAt',
                'durationMin',
                'type',
                'link',
                'status',
                'comment',
            ]);
            if (changes) {
                await this.activity.log(tx, {
                    type: 'meeting.updated',
                    actorId: auth.userId,
                    leadId: after.leadId,
                    dealId: after.dealId,
                    meetingId: id,
                    payload: { changes },
                });
                await this.audit.log(tx, {
                    actorId: auth.userId,
                    action: 'meeting.update',
                    entityType: 'meeting',
                    entityId: id,
                    changes,
                    meta,
                });
            }
            return toDto(after);
        });
    }
    /** «Встреча проведена» + результат; лид переходит на этап «Встреча проведена». */
    async complete(auth, id, input, meta) {
        const before = await this.find(auth, id, 'meeting.update');
        if (before.status === 'DONE')
            throw (0, app_exception_1.businessRule)('Встреча уже отмечена проведённой');
        if (before.status === 'CANCELLED')
            throw (0, app_exception_1.businessRule)('Встреча отменена');
        return this.prisma.$transaction(async (tx) => {
            const m = await tx.meeting.update({
                where: { id },
                data: { status: 'DONE', result: input.result },
                include: meetingInclude,
            });
            await this.activity.log(tx, {
                type: 'meeting.completed',
                actorId: auth.userId,
                leadId: m.leadId,
                dealId: m.dealId,
                meetingId: id,
                payload: { result: input.result.slice(0, 500) },
            });
            if (m.leadId) {
                await tx.lead.update({ where: { id: m.leadId }, data: { lastContactAt: new Date() } });
                await this.leads.advanceTo(tx, m.leadId, 'MEETING_DONE', auth.userId);
            }
            await this.audit.log(tx, {
                actorId: auth.userId,
                action: 'meeting.complete',
                entityType: 'meeting',
                entityId: id,
                changes: { status: { old: before.status, new: 'DONE' } },
                meta,
            });
            await this.outbox.publish(tx, 'meeting.completed', { meetingId: id, managerId: m.managerId, ropId: m.ropId }, auth.userId);
            return toDto(m);
        });
    }
};
exports.MeetingsService = MeetingsService;
exports.MeetingsService = MeetingsService = __decorate([
    (0, common_1.Injectable)(),
    __metadata("design:paramtypes", [prisma_service_1.PrismaService,
        crm_access_service_1.CrmAccessService,
        activity_service_1.ActivityService,
        audit_service_1.AuditService,
        outbox_service_1.OutboxService,
        leads_service_1.LeadsService])
], MeetingsService);
//# sourceMappingURL=meetings.service.js.map
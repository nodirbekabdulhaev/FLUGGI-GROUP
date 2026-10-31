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
var RemindersService_1;
Object.defineProperty(exports, "__esModule", { value: true });
exports.RemindersService = void 0;
const common_1 = require("@nestjs/common");
const contracts_1 = require("@fluggi/contracts");
const domain_1 = require("@fluggi/domain");
const db_1 = require("@fluggi/db");
const serialize_1 = require("../../core/http/serialize");
const prisma_service_1 = require("../../core/prisma/prisma.service");
const notifications_service_1 = require("../notifications/notifications.service");
const DAY = 86_400_000;
const MIN = 60_000;
/**
 * Умные напоминания (ТЗ §41). Каждое напоминание записывается в reminder_log с уникальным
 * ключом — повторный запуск (или второй worker) не отправит то же самое ещё раз.
 */
let RemindersService = RemindersService_1 = class RemindersService {
    prisma;
    notifications;
    logger = new common_1.Logger(RemindersService_1.name);
    constructor(prisma, notifications) {
        this.prisma = prisma;
        this.notifications = notifications;
    }
    /** true — ключ новый, напоминание нужно отправить. */
    async once(kind, key) {
        try {
            await this.prisma.reminderLog.create({ data: { kind, dedupeKey: `${kind}:${key}` } });
            return true;
        }
        catch (err) {
            if (err instanceof db_1.Prisma.PrismaClientKnownRequestError && err.code === 'P2002')
                return false;
            throw err;
        }
    }
    /** Отправить один раз по ключу (для событийных напоминаний). */
    sendOnce(kind, key, to, n) {
        return this.send(kind, key, to, n);
    }
    async send(kind, key, to, n) {
        if (!(await this.once(kind, key)))
            return 0;
        await this.notifications.notify(to, n, null);
        return 1;
    }
    async teamHead(teamId) {
        if (!teamId)
            return null;
        return (await this.prisma.team.findUnique({ where: { id: teamId } }))?.headId ?? null;
    }
    async ceoIds() {
        const rows = await this.prisma.user.findMany({
            where: { role: { code: 'CEO' }, status: 'ACTIVE', deletedAt: null },
            select: { id: true },
        });
        return rows.map((r) => r.id);
    }
    async run(now = new Date()) {
        const today = (0, domain_1.companyDate)(now);
        const out = {};
        const add = (k, n) => {
            if (n)
                out[k] = (out[k] ?? 0) + n;
        };
        // Встреча завтра — один раз в день по каждой встрече
        const tomorrow = (0, domain_1.companyDayStart)((0, domain_1.addDays)(today, 1));
        const meetingsTomorrow = await this.prisma.meeting.findMany({
            where: {
                status: { in: ['SCHEDULED', 'CONFIRMED'] },
                startsAt: { gte: tomorrow, lt: new Date(tomorrow.getTime() + DAY) },
            },
            include: { lead: true, deal: true },
        });
        for (const m of meetingsTomorrow)
            add('meeting.tomorrow', await this.send('meeting.tomorrow', m.id, [m.managerId], {
                type: 'meeting.reminder',
                title: `Встреча завтра в ${(0, domain_1.tashkentTime)(m.startsAt)}`,
                body: m.lead?.title ?? m.deal?.title ?? '',
                link: m.leadId ? `/sales/leads/${m.leadId}` : `/sales/deals/${m.dealId}`,
            }));
        // Встреча через 30 минут
        const soon = await this.prisma.meeting.findMany({
            where: {
                status: { in: ['SCHEDULED', 'CONFIRMED'] },
                startsAt: { gt: now, lte: new Date(now.getTime() + 30 * MIN) },
            },
            include: { lead: true, deal: true },
        });
        for (const m of soon)
            add('meeting.soon', await this.send('meeting.soon', m.id, [m.managerId, m.ropId], {
                type: 'meeting.reminder',
                title: `Встреча через ${Math.max(1, Math.round((m.startsAt.getTime() - now.getTime()) / MIN))} мин`,
                body: `${(0, domain_1.tashkentTime)(m.startsAt)} · ${m.lead?.title ?? m.deal?.title ?? ''}`,
                link: m.leadId ? `/sales/leads/${m.leadId}` : `/sales/deals/${m.dealId}`,
            }));
        // Клиенту не звонили 3 дня (лиды в работе) — не чаще раза в 3 дня
        const stale = await this.prisma.lead.findMany({
            where: {
                status: 'OPEN',
                deletedAt: null,
                OR: [
                    { lastContactAt: { lt: new Date(now.getTime() - 3 * DAY) } },
                    { lastContactAt: null, createdAt: { lt: new Date(now.getTime() - 3 * DAY) } },
                ],
            },
            select: { id: true, title: true, ownerId: true },
            take: 500,
        });
        const bucket = Math.floor(now.getTime() / (3 * DAY));
        for (const l of stale)
            add('lead.no_contact', await this.send('lead.no_contact', `${l.id}:${bucket}`, [l.ownerId], {
                type: 'client.no_contact',
                title: 'Клиенту не звонили 3 дня',
                body: l.title,
                link: `/sales/leads/${l.id}`,
            }));
        // Клиент не отвечает: по открытой сделке нет активности 7 дней — раз в неделю
        const openDeals = await this.prisma.deal.findMany({
            where: {
                status: 'OPEN',
                deletedAt: null,
                createdAt: { lt: new Date(now.getTime() - 7 * DAY) },
            },
            select: { id: true, title: true, ownerId: true },
            take: 500,
        });
        if (openDeals.length) {
            const last = await this.prisma.activity.groupBy({
                by: ['dealId'],
                where: { dealId: { in: openDeals.map((d) => d.id) } },
                _max: { createdAt: true },
            });
            const week = Math.floor(now.getTime() / (7 * DAY));
            for (const d of openDeals) {
                const at = last.find((x) => x.dealId === d.id)?._max.createdAt;
                if (at && at > new Date(now.getTime() - 7 * DAY))
                    continue;
                add('deal.silent', await this.send('deal.silent', `${d.id}:${week}`, [d.ownerId], {
                    type: 'client.no_contact',
                    title: 'Клиент не отвечает 7 дней',
                    body: d.title,
                    link: `/sales/deals/${d.id}`,
                }));
            }
        }
        // КП отправлено 2 дня назад и без ответа
        const proposals = await this.prisma.proposal.findMany({
            where: {
                status: { in: ['SENT', 'VIEWED'] },
                sentAt: { lt: new Date(now.getTime() - 2 * DAY) },
            },
            select: { id: true, number: true, title: true, dealId: true, managerId: true },
        });
        for (const p of proposals)
            add('proposal.waiting', await this.send('proposal.waiting', p.id, [p.managerId], {
                type: 'proposal.reminder',
                title: 'КП отправлено 2 дня назад — нет ответа',
                body: `${(0, contracts_1.formatNumber)('KP', p.number)} ${p.title}`,
                link: `/sales/deals/${p.dealId}`,
            }));
        // Договор не подписан 3 дня
        const contracts = await this.prisma.contract.findMany({
            where: {
                status: { in: ['DRAFT', 'SENT', 'IN_APPROVAL'] },
                createdAt: { lt: new Date(now.getTime() - 3 * DAY) },
            },
            include: { deal: true },
        });
        for (const c of contracts)
            add('contract.unsigned', await this.send('contract.unsigned', c.id, [c.deal.ownerId, await this.teamHead(c.deal.teamId)], {
                type: 'contract.reminder',
                title: 'Договор не подписан',
                body: `${c.deal.title}`,
                link: `/sales/deals/${c.dealId}`,
            }));
        // Оплата просрочена (ожидаемая дата прошла)
        const payments = await this.prisma.payment.findMany({
            where: { status: 'PENDING', dueDate: { lt: (0, serialize_1.parseDate)(today) } },
            include: { deal: true },
        });
        for (const p of payments)
            add('payment.overdue', await this.send('payment.overdue', p.id, [p.deal.ownerId, await this.teamHead(p.deal.teamId)], {
                type: 'payment.overdue',
                title: 'Оплата просрочена',
                body: `${p.deal.title}: ${Math.round(Number(p.amount)).toLocaleString('ru-RU')} ${p.currency}`,
                link: `/sales/deals/${p.dealId}`,
            }));
        // Проект заканчивается через 3 дня
        const ending = await this.prisma.project.findMany({
            where: {
                deletedAt: null,
                status: { in: [...contracts_1.ACTIVE_PROJECT_STATUSES] },
                deadline: (0, serialize_1.parseDate)((0, domain_1.addDays)(today, 3)),
            },
        });
        for (const p of ending)
            add('project.ending', await this.send('project.ending', p.id, [p.managerId, p.ropId], {
                type: 'project.ending',
                title: 'Проект заканчивается через 3 дня',
                body: `${(0, contracts_1.formatNumber)('P', p.number)} ${p.name}`,
                link: `/projects/${p.id}`,
            }));
        // Просроченный проект — РОП и CEO, один раз
        const overdue = await this.prisma.project.findMany({
            where: {
                deletedAt: null,
                status: { in: [...contracts_1.ACTIVE_PROJECT_STATUSES] },
                deadline: { lt: (0, serialize_1.parseDate)(today) },
            },
        });
        const ceos = overdue.length ? await this.ceoIds() : [];
        for (const p of overdue)
            add('project.overdue', await this.send('project.overdue', p.id, [p.ropId, ...ceos], {
                type: 'project.overdue',
                title: 'Просроченный проект',
                body: `${(0, contracts_1.formatNumber)('P', p.number)} ${p.name}`,
                link: `/projects/${p.id}`,
            }));
        // Личные дела: «сегодня срок» — утром (с 09:00) один раз; просрочено — один раз
        if ((0, domain_1.tashkentTime)(now) >= '09:00') {
            const dayEnd = (0, domain_1.companyDayStart)((0, domain_1.addDays)(today, 1));
            const todos = await this.prisma.todo.findMany({
                where: { status: 'OPEN', deletedAt: null, dueAt: { lt: dayEnd } },
                select: { id: true, title: true, ownerId: true, dueAt: true },
                take: 1000,
            });
            for (const t of todos) {
                const overdue = t.dueAt < now;
                add(overdue ? 'todo.overdue' : 'todo.today', await this.send(overdue ? 'todo.overdue' : 'todo.today', t.id, [t.ownerId], {
                    type: overdue ? 'todo.overdue' : 'todo.due',
                    title: overdue ? `⏰ Просрочено: ${t.title}` : `Сегодня срок: ${t.title}`,
                    body: `до ${(0, domain_1.tashkentTime)(t.dueAt)}`,
                    link: '/todos',
                }));
            }
        }
        if (Object.keys(out).length)
            this.logger.log(`Reminders: ${JSON.stringify(out)}`);
        return out;
    }
};
exports.RemindersService = RemindersService;
exports.RemindersService = RemindersService = RemindersService_1 = __decorate([
    (0, common_1.Injectable)(),
    __metadata("design:paramtypes", [prisma_service_1.PrismaService,
        notifications_service_1.NotificationsService])
], RemindersService);
//# sourceMappingURL=reminders.service.js.map
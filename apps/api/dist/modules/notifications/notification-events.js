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
exports.NotificationEvents = void 0;
const common_1 = require("@nestjs/common");
const contracts_1 = require("@fluggi/contracts");
const outbox_dispatcher_1 = require("../../core/outbox/outbox.dispatcher");
const prisma_service_1 = require("../../core/prisma/prisma.service");
const settings_service_1 = require("../../core/settings/settings.service");
const notifications_service_1 = require("./notifications.service");
const fmt = (uzs) => `${Math.round(Number(uzs)).toLocaleString('ru-RU')} UZS`;
/**
 * Подписчики событий → уведомления (ТЗ §14, §54): in-app и Telegram через
 * NotificationsService с учётом личных настроек. Порог «крупного» — из настроек.
 */
let NotificationEvents = class NotificationEvents {
    dispatcher;
    notifications;
    prisma;
    settings;
    constructor(dispatcher, notifications, prisma, settings) {
        this.dispatcher = dispatcher;
        this.notifications = notifications;
        this.prisma = prisma;
        this.settings = settings;
    }
    async large() {
        return (await this.settings.automation()).largeAmountUzs;
    }
    async teamHead(teamId) {
        if (!teamId)
            return null;
        return (await this.prisma.team.findUnique({ where: { id: teamId } }))?.headId ?? null;
    }
    async ceoIds() {
        const ceos = await this.prisma.user.findMany({
            where: { role: { code: 'CEO' }, status: 'ACTIVE', deletedAt: null },
        });
        return ceos.map((u) => u.id);
    }
    onModuleInit() {
        this.dispatcher.on('lead.created', async (e, meta) => {
            const lead = await this.prisma.lead.findUnique({ where: { id: e.leadId } });
            if (!lead)
                return;
            const link = `/sales/leads/${lead.id}`;
            await this.notifications.notify([e.ownerId], { type: 'lead.created', title: 'Новый лид', body: lead.title, link }, meta.actorId);
            // CEO не ответственный за лиды, но узнаёт о каждом новом лиде и о том, кому он назначен
            const owner = await this.prisma.user.findUnique({
                where: { id: e.ownerId },
                select: { fullName: true },
            });
            await this.notifications.notify((await this.ceoIds()).filter((id) => id !== e.ownerId), {
                type: 'lead.created',
                title: 'Новый лид',
                body: `${lead.title} → ${owner?.fullName ?? ''}`,
                link,
            }, meta.actorId);
            if (e.budgetUzs && Number(e.budgetUzs) >= (await this.large())) {
                await this.notifications.notify([await this.teamHead(e.teamId), ...(await this.ceoIds())], {
                    type: 'lead.large',
                    title: 'Крупный лид',
                    body: `${lead.title} — ${fmt(e.budgetUzs)}`,
                    link,
                }, meta.actorId);
            }
        });
        this.dispatcher.on('lead.assigned', async (e, meta) => {
            const lead = await this.prisma.lead.findUnique({ where: { id: e.leadId } });
            if (!lead)
                return;
            await this.notifications.notify([e.ownerId], {
                type: 'lead.assigned',
                title: 'Вам назначен лид',
                body: lead.title,
                link: `/sales/leads/${lead.id}`,
            }, meta.actorId);
        });
        this.dispatcher.on('deal.created', async (e, meta) => {
            const deal = await this.prisma.deal.findUnique({ where: { id: e.dealId } });
            if (!deal)
                return;
            const large = Number(e.amountUzs) >= (await this.large());
            await this.notifications.notify([await this.teamHead(e.teamId), ...(large ? await this.ceoIds() : [])], {
                type: large ? 'deal.large' : 'deal.created',
                title: large ? 'Крупная сделка' : 'Новая сделка',
                body: `${(0, contracts_1.formatNumber)('D', deal.number)} ${deal.title} — ${fmt(e.amountUzs)}`,
                link: `/sales/deals/${deal.id}`,
            }, meta.actorId);
        });
        this.dispatcher.on('deal.lost', async (e, meta) => {
            if (Number(e.amountUzs) < (await this.large()))
                return;
            const deal = await this.prisma.deal.findUnique({
                where: { id: e.dealId },
                include: { client: true },
            });
            if (!deal)
                return;
            await this.notifications.notify([await this.teamHead(e.teamId), ...(await this.ceoIds())], {
                type: 'deal.large_lost',
                title: 'Потеря крупного клиента',
                body: `${deal.client.name}: ${fmt(e.amountUzs)}${e.reason ? ` — ${e.reason}` : ''}`,
                link: `/sales/deals/${deal.id}`,
            }, meta.actorId);
        });
        this.dispatcher.on('proposal.approval_requested', async (e, meta) => {
            const p = await this.prisma.proposal.findUnique({ where: { id: e.proposalId } });
            if (!p)
                return;
            await this.notifications.notify([await this.teamHead(e.teamId)], {
                type: 'proposal.approval',
                title: 'КП на согласование',
                body: `${p.title} — ${fmt(p.totalUzs.toString())}`,
                link: `/sales/deals/${p.dealId}`,
            }, meta.actorId);
        });
        this.dispatcher.on('contract.signed', async (e, meta) => {
            const deal = await this.prisma.deal.findUnique({
                where: { id: e.dealId },
                include: { client: true },
            });
            if (!deal)
                return;
            await this.notifications.notify([e.managerId, await this.teamHead(e.teamId)], {
                type: 'contract.signed',
                title: 'Договор подписан',
                body: `${deal.client.name} · ${deal.title}`,
                link: `/sales/deals/${deal.id}`,
            }, meta.actorId);
        });
        // Оплата: менеджеру, РОП и CEO (ТЗ §14).
        this.dispatcher.on('payment.paid', async (e, meta) => {
            const deal = await this.prisma.deal.findUnique({
                where: { id: e.dealId },
                include: { client: true },
            });
            if (!deal)
                return;
            await this.notifications.notify([e.managerId, await this.teamHead(e.teamId), ...(await this.ceoIds())], {
                type: 'payment.paid',
                title: 'Оплата получена',
                body: `${deal.client.name}: ${fmt(e.amountUzs)}`,
                link: `/sales/deals/${deal.id}`,
            }, meta.actorId);
        });
        this.dispatcher.on('project.created', async (e, meta) => {
            const project = await this.prisma.project.findUnique({ where: { id: e.projectId } });
            if (!project)
                return;
            await this.notifications.notify([e.ropId, e.managerId], {
                type: 'project.created',
                title: 'Новый проект',
                body: `${(0, contracts_1.formatNumber)('P', project.number)} ${project.name} — назначьте исполнителей`,
                link: `/projects/${project.id}`,
            }, meta.actorId);
        });
        // Проекты и задачи (ТЗ §14, §21–24). Telegram подключится к этим же событиям в Phase 7.
        this.dispatcher.on('project.member_added', async (e, meta) => {
            const project = await this.prisma.project.findUnique({ where: { id: e.projectId } });
            if (!project)
                return;
            await this.notifications.notify([e.userId], {
                type: 'project.member_added',
                title: 'Вас добавили в проект',
                body: `${(0, contracts_1.formatNumber)('P', project.number)} ${project.name}`,
                link: `/projects/${project.id}`,
            }, meta.actorId);
        });
        this.dispatcher.on('project.status_changed', async (e, meta) => {
            if (e.to !== 'COMPLETED' && e.to !== 'CANCELLED')
                return;
            const project = await this.prisma.project.findUnique({ where: { id: e.projectId } });
            if (!project)
                return;
            await this.notifications.notify([project.managerId, project.ropId, ...(await this.ceoIds())], {
                type: `project.${e.to === 'COMPLETED' ? 'completed' : 'cancelled'}`,
                title: e.to === 'COMPLETED' ? 'Проект завершён' : 'Проект отменён',
                body: `${(0, contracts_1.formatNumber)('P', project.number)} ${project.name}`,
                link: `/projects/${project.id}`,
            }, meta.actorId);
        });
        this.dispatcher.on('task.assigned', async (e, meta) => {
            const task = await this.prisma.task.findUnique({
                where: { id: e.taskId },
                include: { project: true },
            });
            if (!task || task.assigneeId !== e.assigneeId)
                return;
            await this.notifications.notify([e.assigneeId], {
                type: 'task.assigned',
                title: 'Новая задача',
                body: `${task.title} · ${task.project.name}`,
                link: `/projects/${task.projectId}?task=${task.id}`,
            }, meta.actorId);
        });
        // На проверку — автору задачи и РОП проекта; принято/возвращено — исполнителю.
        this.dispatcher.on('task.status_changed', async (e, meta) => {
            const task = await this.prisma.task.findUnique({
                where: { id: e.taskId },
                include: { project: true },
            });
            if (!task)
                return;
            const link = `/projects/${task.projectId}?task=${task.id}`;
            if (e.to === 'REVIEW') {
                await this.notifications.notify([task.creatorId, task.project.ropId], { type: 'task.review', title: 'Задача на проверке', body: task.title, link }, meta.actorId);
            }
            else if (e.from === 'REVIEW') {
                const accepted = e.to === 'DONE';
                await this.notifications.notify([task.assigneeId], {
                    type: accepted ? 'task.accepted' : 'task.returned',
                    title: accepted ? 'Задача принята' : 'Задача возвращена на доработку',
                    body: task.title,
                    link,
                }, meta.actorId);
            }
        });
        // Исполнителю: изменение дедлайна (ТЗ §14)
        this.dispatcher.on('task.deadline_changed', async (e, meta) => {
            const task = await this.prisma.task.findUnique({
                where: { id: e.taskId },
                include: { project: true },
            });
            if (!task)
                return;
            const when = task.deadline
                ? task.deadline.toLocaleString('ru-RU', {
                    timeZone: 'Asia/Tashkent',
                    dateStyle: 'short',
                    timeStyle: 'short',
                })
                : 'без срока';
            await this.notifications.notify([task.assigneeId], {
                type: 'task.deadline_changed',
                title: 'Изменён дедлайн задачи',
                body: `${task.title}: ${when}`,
                link: `/projects/${task.projectId}?task=${task.id}`,
            }, meta.actorId);
        });
        this.dispatcher.on('task.overdue', async (e) => {
            const task = await this.prisma.task.findUnique({
                where: { id: e.taskId },
                include: { project: true },
            });
            if (!task)
                return;
            const d = e.overdueDays;
            const word = d % 10 === 1 && d % 100 !== 11
                ? 'день'
                : [2, 3, 4].includes(d % 10) && ![12, 13, 14].includes(d % 100)
                    ? 'дня'
                    : 'дней';
            await this.notifications.notify([task.assigneeId, task.project.ropId], {
                type: 'task.overdue',
                title: `Просрочено ${d} ${word}`,
                body: `${task.title} · ${task.project.name}`,
                link: `/projects/${task.projectId}?task=${task.id}`,
            }, null);
        });
        // Шаг 6 сценария приёмки: РОП получает уведомление о новой встрече.
        this.dispatcher.on('meeting.created', async (e, meta) => {
            const m = await this.prisma.meeting.findUnique({
                where: { id: e.meetingId },
                include: { lead: true, deal: true, manager: true },
            });
            if (!m)
                return;
            const subject = m.lead?.title ?? m.deal?.title ?? '';
            const when = m.startsAt.toLocaleString('ru-RU', {
                timeZone: 'Asia/Tashkent',
                dateStyle: 'short',
                timeStyle: 'short',
            });
            await this.notifications.notify([e.ropId, e.managerId], {
                type: 'meeting.created',
                title: 'Новая встреча',
                body: `${when} · ${subject} · ${m.manager.fullName}`,
                link: m.leadId ? `/sales/leads/${m.leadId}` : `/sales/deals/${m.dealId}`,
            }, meta.actorId);
        });
    }
};
exports.NotificationEvents = NotificationEvents;
exports.NotificationEvents = NotificationEvents = __decorate([
    (0, common_1.Injectable)(),
    __metadata("design:paramtypes", [outbox_dispatcher_1.OutboxDispatcher,
        notifications_service_1.NotificationsService,
        prisma_service_1.PrismaService,
        settings_service_1.SettingsService])
], NotificationEvents);
//# sourceMappingURL=notification-events.js.map
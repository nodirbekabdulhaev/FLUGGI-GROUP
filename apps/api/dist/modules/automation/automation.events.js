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
exports.AutomationEvents = void 0;
const common_1 = require("@nestjs/common");
const outbox_dispatcher_1 = require("../../core/outbox/outbox.dispatcher");
const prisma_service_1 = require("../../core/prisma/prisma.service");
const notifications_service_1 = require("../notifications/notifications.service");
const follow_ups_service_1 = require("./follow-ups.service");
const reminders_service_1 = require("./reminders.service");
const reports_service_1 = require("./reports.service");
/** Автоматизации по событиям (ТЗ §54): follow-up после проекта, достижение плана. */
let AutomationEvents = class AutomationEvents {
    dispatcher;
    prisma;
    followUps;
    reports;
    notifications;
    reminders;
    constructor(dispatcher, prisma, followUps, reports, notifications, reminders) {
        this.dispatcher = dispatcher;
        this.prisma = prisma;
        this.followUps = followUps;
        this.reports = reports;
        this.notifications = notifications;
        this.reminders = reminders;
    }
    onModuleInit() {
        // Rule 8: после завершения проекта — follow-up на 30/60/90 дней (интервалы — в настройках)
        this.dispatcher.on('project.status_changed', async (e) => {
            if (e.to !== 'COMPLETED')
                return;
            await this.prisma.$transaction((tx) => this.followUps.createForProject(tx, e.projectId));
        });
        // ТЗ §14: CEO (и РОП по отделу) — «Достижение месячного плана», один раз за месяц
        this.dispatcher.on('payment.paid', async (e) => {
            const company = await this.reports.planProgress({});
            if (company.plan.gt(0) && company.collected.gte(company.plan)) {
                const ceos = await this.prisma.user.findMany({
                    where: { role: { code: 'CEO' }, status: 'ACTIVE', deletedAt: null },
                    select: { id: true },
                });
                await this.reminders.sendOnce('plan.achieved', `company:${company.period}`, ceos.map((c) => c.id), {
                    type: 'plan.achieved',
                    title: '🎉 Месячный план выполнен',
                    body: `${company.period}: ${Math.round(Number(company.collected)).toLocaleString('ru-RU')} из ${Math.round(Number(company.plan)).toLocaleString('ru-RU')} UZS`,
                    link: '/finance/revenue',
                });
            }
            if (!e.teamId)
                return;
            const team = await this.reports.planProgress({ teamIds: [e.teamId] });
            if (team.plan.gt(0) && team.collected.gte(team.plan)) {
                const head = (await this.prisma.team.findUnique({ where: { id: e.teamId } }))?.headId ?? null;
                await this.reminders.sendOnce('plan.achieved', `team:${e.teamId}:${team.period}`, [head], {
                    type: 'plan.achieved',
                    title: '🎉 План отдела выполнен',
                    body: `${team.period}: ${Math.round(Number(team.collected)).toLocaleString('ru-RU')} из ${Math.round(Number(team.plan)).toLocaleString('ru-RU')} UZS`,
                    link: '/dashboard',
                });
            }
        });
    }
};
exports.AutomationEvents = AutomationEvents;
exports.AutomationEvents = AutomationEvents = __decorate([
    (0, common_1.Injectable)(),
    __metadata("design:paramtypes", [outbox_dispatcher_1.OutboxDispatcher,
        prisma_service_1.PrismaService,
        follow_ups_service_1.FollowUpsService,
        reports_service_1.ReportsService,
        notifications_service_1.NotificationsService,
        reminders_service_1.RemindersService])
], AutomationEvents);
//# sourceMappingURL=automation.events.js.map
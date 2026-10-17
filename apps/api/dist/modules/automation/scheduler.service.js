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
var SchedulerService_1;
Object.defineProperty(exports, "__esModule", { value: true });
exports.SchedulerService = void 0;
const common_1 = require("@nestjs/common");
const contracts_1 = require("@fluggi/contracts");
const domain_1 = require("@fluggi/domain");
const db_1 = require("@fluggi/db");
const env_1 = require("../../config/env");
const serialize_1 = require("../../core/http/serialize");
const prisma_service_1 = require("../../core/prisma/prisma.service");
const settings_service_1 = require("../../core/settings/settings.service");
const client_insights_service_1 = require("../analytics/client-insights.service");
const notifications_service_1 = require("../notifications/notifications.service");
const recurring_service_1 = require("../todos/recurring.service");
const people_service_1 = require("../people/people.service");
const overdue_runner_1 = require("../projects/overdue.runner");
const telegram_runners_1 = require("../telegram/telegram.runners");
const follow_ups_service_1 = require("./follow-ups.service");
const reminders_service_1 = require("./reminders.service");
const reports_service_1 = require("./reports.service");
/** Каждые N минут: слот — дата + номер интервала. */
const every = (minutes) => (now) => {
    const t = (0, domain_1.tashkentTime)(now);
    const m = Number(t.slice(0, 2)) * 60 + Number(t.slice(3));
    return `${(0, domain_1.companyDate)(now)}#${Math.floor(m / minutes)}`;
};
/** Ежедневно после HH:MM (Ташкент); пропущенный запуск догоняется в тот же день. */
const dailyAt = (hhmm) => (now) => (0, domain_1.tashkentTime)(now) >= hhmm ? (0, domain_1.companyDate)(now) : null;
const weeklyAt = (weekday, hhmm) => (now) => (0, domain_1.isoWeekday)((0, domain_1.companyDate)(now)) === weekday && (0, domain_1.tashkentTime)(now) >= hhmm ? (0, domain_1.companyDate)(now) : null;
const monthlyAt = (hhmm) => (now) => (0, domain_1.companyDate)(now).endsWith('-01') && (0, domain_1.tashkentTime)(now) >= hhmm
    ? (0, domain_1.companyDate)(now).slice(0, 7)
    : null;
const TICK_MS = 30_000;
/**
 * Планировщик (ТЗ §55). Запуск каждой задачи фиксируется в job_runs с уникальным слотом,
 * поэтому даже при нескольких worker'ах задача выполняется один раз.
 */
let SchedulerService = SchedulerService_1 = class SchedulerService {
    prisma;
    settings;
    notifications;
    reminders;
    reports;
    followUps;
    overdue;
    people;
    clients;
    recurring;
    logger = new common_1.Logger(SchedulerService_1.name);
    timer;
    running = false;
    jobs;
    constructor(prisma, settings, notifications, reminders, reports, followUps, overdue, people, clients, recurring) {
        this.prisma = prisma;
        this.settings = settings;
        this.notifications = notifications;
        this.reminders = reminders;
        this.reports = reports;
        this.followUps = followUps;
        this.overdue = overdue;
        this.people = people;
        this.clients = clients;
        this.recurring = recurring;
        this.jobs = [
            {
                name: 'overdue-tasks',
                label: 'Проверка просроченных задач',
                schedule: 'каждый час',
                due: every(60),
                run: (now) => this.overdue.scan(now),
            },
            {
                name: 'smart-reminders',
                label: 'Умные напоминания',
                schedule: 'каждые 15 минут',
                due: every(15),
                run: (now) => this.reminders.run(now),
            },
            {
                name: 'follow-ups',
                label: 'Повторные контакты на сегодня',
                schedule: 'ежедневно 09:00',
                due: dailyAt('09:00'),
                run: (now) => this.followUpsDue(now),
            },
            {
                name: 'daily-report',
                label: 'Ежедневный отчёт руководителю',
                schedule: 'ежедневно 18:00',
                due: dailyAt('18:00'),
                run: (now) => this.dailyReport(now),
            },
            {
                name: 'absences',
                label: 'Отметка отсутствующих',
                schedule: 'ежедневно 20:00',
                due: dailyAt('20:00'),
                run: (now) => this.absences(now),
            },
            {
                name: 'weekly-report',
                label: 'Еженедельный отчёт CEO',
                schedule: 'понедельник 09:00',
                due: weeklyAt(1, '09:00'),
                run: (now) => this.weeklyReport(now),
            },
            {
                name: 'recurring-todos',
                label: 'Регулярные дела (налоговый календарь)',
                schedule: 'ежедневно 07:00',
                due: dailyAt('07:00'),
                run: (now) => this.recurring.generate(now),
            },
            {
                name: 'client-health',
                label: 'Здоровье клиентов',
                schedule: 'ежедневно 06:00',
                due: dailyAt('06:00'),
                run: (now) => this.clientHealth(now),
            },
            {
                name: 'payroll',
                label: 'Предварительный расчёт зарплаты',
                schedule: '1-го числа 09:00',
                due: monthlyAt('09:00'),
                run: (now) => this.payroll(now),
            },
        ];
    }
    onApplicationBootstrap() {
        if (!(0, telegram_runners_1.backgroundEnabled)() || !(0, env_1.loadEnv)().SCHEDULER_ENABLED)
            return;
        this.timer = setInterval(() => void this.tick(), TICK_MS);
        this.timer.unref();
        void this.tick();
    }
    onApplicationShutdown() {
        if (this.timer)
            clearInterval(this.timer);
    }
    async tick(now = new Date()) {
        if (this.running)
            return;
        this.running = true;
        try {
            for (const job of this.jobs) {
                const slot = job.due(now);
                if (slot)
                    await this.execute(job, slot, now);
            }
        }
        finally {
            this.running = false;
        }
    }
    /** Выполнить задачу в слоте один раз. false — слот уже был выполнен. */
    async execute(job, slot, now = new Date()) {
        let runId;
        try {
            runId = (await this.prisma.jobRun.create({ data: { job: job.name, slot } })).id;
        }
        catch (err) {
            if (err instanceof db_1.Prisma.PrismaClientKnownRequestError && err.code === 'P2002')
                return false;
            throw err;
        }
        try {
            const result = await job.run(now);
            await this.prisma.jobRun.update({
                where: { id: runId },
                data: { finishedAt: new Date(), result: (result ?? null) },
            });
        }
        catch (err) {
            this.logger.error(err, `Job ${job.name} failed`);
            await this.prisma.jobRun.update({
                where: { id: runId },
                data: { finishedAt: new Date(), error: String(err.message).slice(0, 1000) },
            });
        }
        return true;
    }
    /** Ручной запуск из настроек (отдельный слот, чтобы не мешать расписанию). */
    async runNow(name, now = new Date()) {
        const job = this.jobs.find((j) => j.name === name);
        if (!job)
            return null;
        await this.execute(job, `manual:${now.toISOString()}`, now);
        return this.prisma.jobRun.findFirst({ where: { job: name }, orderBy: { startedAt: 'desc' } });
    }
    async users(role) {
        return this.prisma.user.findMany({
            where: { role: { code: role }, status: 'ACTIVE', deletedAt: null },
            include: { headedTeams: { where: { deletedAt: null }, select: { id: true } } },
        });
    }
    // ─────────────────────────── Задачи ───────────────────────────
    async followUpsDue(now) {
        const today = (0, serialize_1.parseDate)((0, domain_1.companyDate)(now));
        const due = await this.prisma.followUp.findMany({
            where: { status: 'PENDING', notifiedAt: null, dueDate: { lte: today } },
            include: { client: true },
        });
        for (const f of due) {
            await this.notifications.notify([f.ownerId], {
                type: 'followup.due',
                title: follow_ups_service_1.FollowUpsService.title(f.kind),
                body: f.client.name,
                link: '/sales/follow-ups',
            });
            await this.prisma.followUp.update({ where: { id: f.id }, data: { notifiedAt: now } });
        }
        return { notified: due.length };
    }
    async dailyReport(now) {
        if (!(await this.settings.automation()).dailyReport)
            return { skipped: true };
        const company = await this.reports.daily({}, now);
        const ceos = await this.users('CEO');
        await this.notifications.notify(ceos.map((u) => u.id), {
            type: 'report.daily',
            title: 'Ежедневный отчёт',
            body: company.replace(/<[^>]+>/g, ''),
            link: '/dashboard',
        });
        const rops = await this.users('ROP');
        for (const r of rops.filter((x) => x.headedTeams.length)) {
            const text = await this.reports.daily({ teamIds: r.headedTeams.map((t) => t.id) }, now);
            await this.notifications.notify([r.id], {
                type: 'report.daily',
                title: 'Ежедневный отчёт отдела',
                body: text.replace(/<[^>]+>/g, ''),
                link: '/dashboard',
            });
        }
        return { ceo: ceos.length, rop: rops.length };
    }
    async weeklyReport(now) {
        if (!(await this.settings.automation()).weeklyReport)
            return { skipped: true };
        const text = await this.reports.weekly({}, now);
        const ceos = await this.users('CEO');
        await this.notifications.notify(ceos.map((u) => u.id), {
            type: 'report.weekly',
            title: 'Еженедельный отчёт',
            body: text.replace(/<[^>]+>/g, ''),
            link: '/finance/revenue',
        });
        return { ceo: ceos.length };
    }
    /** Сотрудники с рабочим днём по графику и без отметки — «Отсутствовал» (ТЗ §35). */
    async absences(now) {
        const date = (0, domain_1.companyDate)(now);
        const weekday = (0, domain_1.isoWeekday)(date);
        const users = await this.prisma.user.findMany({
            // Посещаемость отмечают только менеджеры и РОП
            where: { status: 'ACTIVE', deletedAt: null, role: { code: { in: [...contracts_1.ATTENDANCE_ROLES] } } },
            include: { role: true, employee: { include: { schedule: true } } },
        });
        const schedules = await this.prisma.workSchedule.findMany({
            where: { isActive: true, roleCode: { not: null } },
        });
        const marked = new Set((await this.prisma.attendance.findMany({
            where: { date: (0, serialize_1.parseDate)(date) },
            select: { userId: true },
        })).map((a) => a.userId));
        let created = 0;
        for (const u of users) {
            if (marked.has(u.id))
                continue;
            const own = u.employee?.schedule?.isActive ? u.employee.schedule : null;
            const schedule = own ?? schedules.find((s) => s.roleCode === u.role.code);
            if (!schedule || !schedule.workDays.includes(weekday))
                continue;
            await this.prisma.attendance.create({
                data: {
                    userId: u.id,
                    date: (0, serialize_1.parseDate)(date),
                    status: 'ABSENT',
                    comment: 'Нет отметки (автоматически)',
                },
            });
            created += 1;
        }
        return { absent: created };
    }
    /** Пересчёт «здоровья» клиентов (ТЗ §43); перешедшие в «Риск» — уведомление менеджеру. */
    async clientHealth(now) {
        const r = await this.clients.refreshAll(now);
        for (const c of r.toRisk)
            await this.notifications.notify([c.ownerId], {
                type: 'client.risk',
                title: 'Клиент в зоне риска',
                body: `${c.name}: ${c.reasons.join(', ')}`,
                link: `/clients/${c.id}`,
            });
        return { changed: r.changed, risk: r.toRisk.length };
    }
    /** 1-го числа: черновик зарплаты за прошлый месяц и уведомление CEO (ТЗ §55). */
    async payroll(now) {
        const prev = (0, domain_1.addDays)(`${(0, domain_1.companyDate)(now).slice(0, 7)}-01`, -1).slice(0, 7);
        const ceo = (await this.users('CEO'))[0];
        if (!ceo)
            return { skipped: 'no CEO' };
        const all = {
            'payroll.manage': 'ALL',
            'payroll.read': 'ALL',
            'kpi.read': 'ALL',
        };
        const system = {
            sessionId: 'system',
            userId: ceo.id,
            email: ceo.email,
            fullName: 'Система',
            locale: 'ru',
            roleCode: 'CEO',
            roleName: 'CEO',
            teamId: null,
            teamName: null,
            headedTeamIds: [],
            directionIds: [],
            permissions: all,
            telegramLinked: false,
        };
        const rows = await this.people.calculate(system, prev, {
            ip: null,
            userAgent: 'scheduler',
            sessionId: null,
        });
        await this.notifications.notify([ceo.id], {
            type: 'payroll.calculated',
            title: `Предварительный расчёт зарплаты за ${prev}`,
            body: `Начислений: ${rows.length}. Проверьте бонусы и штрафы и утвердите.`,
            link: '/finance/payroll',
        });
        return { period: prev, entries: rows.length };
    }
};
exports.SchedulerService = SchedulerService;
exports.SchedulerService = SchedulerService = SchedulerService_1 = __decorate([
    (0, common_1.Injectable)(),
    __metadata("design:paramtypes", [prisma_service_1.PrismaService,
        settings_service_1.SettingsService,
        notifications_service_1.NotificationsService,
        reminders_service_1.RemindersService,
        reports_service_1.ReportsService,
        follow_ups_service_1.FollowUpsService,
        overdue_runner_1.OverdueScanner,
        people_service_1.PeopleService,
        client_insights_service_1.ClientInsightsService,
        recurring_service_1.RecurringTodosService])
], SchedulerService);
//# sourceMappingURL=scheduler.service.js.map
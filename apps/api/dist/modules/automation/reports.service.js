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
exports.ReportsService = void 0;
const common_1 = require("@nestjs/common");
const contracts_1 = require("@fluggi/contracts");
const domain_1 = require("@fluggi/domain");
const db_1 = require("@fluggi/db");
const serialize_1 = require("../../core/http/serialize");
const prisma_service_1 = require("../../core/prisma/prisma.service");
const ZERO = new db_1.Prisma.Decimal(0);
const DAY = 86_400_000;
const mln = (v) => {
    const n = Number(v);
    if (Math.abs(n) >= 1e6)
        return `${(n / 1e6).toLocaleString('ru-RU', { maximumFractionDigits: 1 })} млн`;
    return `${Math.round(n).toLocaleString('ru-RU')}`;
};
const uzs = (v) => `${Math.round(Number(v)).toLocaleString('ru-RU')} UZS`;
const dayTitle = (d) => new Date(d.getTime() + 5 * 3_600_000)
    .toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', timeZone: 'UTC' })
    .toUpperCase();
/**
 * Отчёты руководителю (ТЗ §56–57). Тексты — для Telegram (HTML) и in-app.
 */
let ReportsService = class ReportsService {
    prisma;
    constructor(prisma) {
        this.prisma = prisma;
    }
    deal(scope) {
        return scope.teamIds ? { teamId: { in: scope.teamIds } } : {};
    }
    async collected(scope, from, to) {
        const rows = await this.prisma.payment.findMany({
            where: { status: 'PAID', paidAt: { gte: from, lt: to }, deal: this.deal(scope) },
            select: {
                type: true,
                amountUzs: true,
                deal: { select: { owner: { select: { fullName: true } } } },
            },
        });
        const net = rows.reduce((s, p) => (p.type === 'REFUND' ? s.sub(p.amountUzs) : s.add(p.amountUzs)), ZERO);
        const byManager = new Map();
        for (const p of rows) {
            const name = p.deal.owner.fullName;
            byManager.set(name, (byManager.get(name) ?? ZERO)[p.type === 'REFUND' ? 'sub' : 'add'](p.amountUzs));
        }
        const ranking = [...byManager].sort((a, b) => Number(b[1]) - Number(a[1]));
        return {
            net,
            ranking,
            refunds: rows.filter((p) => p.type === 'REFUND').reduce((s, p) => s.add(p.amountUzs), ZERO),
        };
    }
    /** План месяца: сумма целей «Выручка» менеджеров (UZS; USD по текущему курсу). */
    async monthPlan(scope, period) {
        const [targets, usd] = await Promise.all([
            this.prisma.kpiTarget.findMany({
                where: {
                    period,
                    metric: 'REVENUE',
                    user: {
                        role: { code: 'MANAGER' },
                        ...(scope.teamIds ? { teamId: { in: scope.teamIds } } : {}),
                    },
                },
            }),
            this.prisma.exchangeRate.findFirst({ where: { currency: 'USD' }, orderBy: { date: 'desc' } }),
        ]);
        const rate = usd ? usd.rateToUzs : ZERO;
        return targets.reduce((s, t) => s.add(t.currency === 'USD' ? t.targetValue.mul(rate) : t.targetValue), ZERO);
    }
    /** Прогноз месяца: получено + взвешенная воронка открытых сделок (ТЗ §40). */
    async forecast(scope, collectedMonth) {
        const open = await this.prisma.deal.findMany({
            where: { status: 'OPEN', deletedAt: null, ...this.deal(scope) },
            select: {
                amountUzs: true,
                probabilityOverride: true,
                stage: { select: { probability: true } },
            },
        });
        const weighted = open.reduce((s, d) => s.add(d.amountUzs.mul(d.probabilityOverride ?? d.stage.probability).div(100)), ZERO);
        return collectedMonth.add(weighted);
    }
    async planProgress(scope, now = new Date()) {
        const period = (0, domain_1.companyDate)(now).slice(0, 7);
        const m = (0, domain_1.monthRange)(period);
        const [plan, got] = await Promise.all([
            this.monthPlan(scope, period),
            this.collected(scope, m.from, m.to),
        ]);
        return { period, plan, collected: got.net };
    }
    /** Ежедневный отчёт за день (ТЗ §56). */
    async daily(scope, now = new Date()) {
        const date = (0, domain_1.companyDate)(now);
        const from = (0, domain_1.companyDayStart)(date);
        const to = new Date(from.getTime() + DAY);
        const inDay = { gte: from, lt: to };
        const team = scope.teamIds ? { teamId: { in: scope.teamIds } } : {};
        const [leads, qualified, meetings, proposals, contracts, day, projects, overdueTasks, overdueProjects, month,] = await Promise.all([
            this.prisma.lead.count({ where: { ...team, createdAt: inDay, deletedAt: null } }),
            this.prisma.lead.count({ where: { ...team, convertedAt: inDay } }),
            this.prisma.meeting.count({ where: { ...team, status: 'DONE', startsAt: inDay } }),
            this.prisma.proposal.count({ where: { sentAt: inDay, deal: this.deal(scope) } }),
            this.prisma.contract.count({
                where: { status: 'SIGNED', signedAt: inDay, deal: this.deal(scope) },
            }),
            this.collected(scope, from, to),
            this.prisma.project.count({ where: { ...team, createdAt: inDay, deletedAt: null } }),
            this.prisma.task.count({
                where: {
                    deletedAt: null,
                    status: { in: [...contracts_1.OPEN_TASK_STATUSES] },
                    deadline: { lt: now },
                    project: { deletedAt: null, ...team },
                },
            }),
            this.prisma.project.count({
                where: {
                    ...team,
                    deletedAt: null,
                    status: { in: [...contracts_1.ACTIVE_PROJECT_STATUSES] },
                    deadline: { lt: (0, serialize_1.parseDate)(date) },
                },
            }),
            this.planProgress(scope, now),
        ]);
        const forecast = await this.forecast(scope, month.collected);
        const best = day.ranking[0];
        const planPct = month.plan.gt(0)
            ? `${month.collected.div(month.plan).mul(100).toFixed(0)}%`
            : 'цель не задана';
        return [
            `📊 <b>ОТЧЁТ ЗА ${dayTitle(now)}</b>`,
            '',
            `Новые лиды: ${leads}`,
            `Квалифицировано: ${qualified}`,
            `Встречи: ${meetings}`,
            `КП: ${proposals}`,
            `Договоры: ${contracts}`,
            `Оплаты: ${uzs(day.net)}`,
            `Новые проекты: ${projects}`,
            `Просроченные задачи: ${overdueTasks}`,
            `Просроченные проекты: ${overdueProjects}`,
            '',
            `Лучший менеджер: ${best ? `${best[0]} — ${mln(best[1])}` : '—'}`,
            `План месяца: ${planPct}`,
            `Прогноз: ${mln(forecast)}`,
        ].join('\n');
    }
    /** Еженедельный отчёт за 7 дней (ТЗ §57). */
    async weekly(scope, now = new Date()) {
        const to = (0, domain_1.companyDayStart)((0, domain_1.companyDate)(now));
        const from = new Date(to.getTime() - 7 * DAY);
        const inWeek = { gte: from, lt: to };
        const team = scope.teamIds ? { teamId: { in: scope.teamIds } } : {};
        const dateRange = {
            gte: new Date(from.getTime() + 5 * 3_600_000),
            lt: new Date(to.getTime() + 5 * 3_600_000),
        };
        const [leads, converted, deals, won, money, projectExp, companyExp, projects, overdueTasks] = await Promise.all([
            this.prisma.lead.count({ where: { ...team, createdAt: inWeek, deletedAt: null } }),
            this.prisma.lead.count({
                where: { ...team, createdAt: inWeek, deletedAt: null, dealId: { not: null } },
            }),
            this.prisma.deal.count({ where: { ...team, createdAt: inWeek, deletedAt: null } }),
            this.prisma.deal.count({ where: { ...team, wonAt: inWeek } }),
            this.collected(scope, from, to),
            this.prisma.expense.aggregate({
                where: { deletedAt: null, scope: 'PROJECT', expenseDate: dateRange, project: team },
                _sum: { amountUzs: true },
            }),
            scope.teamIds
                ? null
                : this.prisma.expense.aggregate({
                    where: { deletedAt: null, scope: 'COMPANY', expenseDate: dateRange },
                    _sum: { amountUzs: true },
                }),
            this.prisma.project.count({ where: { ...team, createdAt: inWeek, deletedAt: null } }),
            this.prisma.task.count({
                where: {
                    deletedAt: null,
                    status: { in: [...contracts_1.OPEN_TASK_STATUSES] },
                    deadline: { lt: now },
                    project: { deletedAt: null, ...team },
                },
            }),
        ]);
        const expenses = (projectExp._sum.amountUzs ?? ZERO).add(companyExp?._sum.amountUzs ?? ZERO);
        const profit = money.net.sub(projectExp._sum.amountUzs ?? ZERO);
        const avg = won > 0 ? money.net.div(won) : ZERO;
        const conv = leads > 0 ? `${((converted / leads) * 100).toFixed(0)}%` : '—';
        const top = money.ranking.slice(0, 3).map(([name, v], i) => `${i + 1}. ${name} — ${mln(v)}`);
        return [
            `📈 <b>НЕДЕЛЬНЫЙ ОТЧЁТ</b> (${dayTitle(from)} — ${dayTitle(new Date(to.getTime() - DAY))})`,
            '',
            `Лиды: ${leads}`,
            `Конверсия: ${conv}`,
            `Сделки: ${deals}`,
            `Выручка: ${uzs(money.net)}`,
            `Средний чек: ${uzs(avg)}`,
            `Прибыль: ${uzs(profit)}${(0, domain_1.marginPct)(profit.toString(), money.net.toString()) ? ` (${(0, domain_1.marginPct)(profit.toString(), money.net.toString())}%)` : ''}`,
            `Расходы: ${uzs(expenses)}`,
            `Проекты: ${projects}`,
            `Просроченные задачи: ${overdueTasks}`,
            '',
            'Эффективность сотрудников:',
            ...(top.length ? top : ['—']),
        ].join('\n');
    }
};
exports.ReportsService = ReportsService;
exports.ReportsService = ReportsService = __decorate([
    (0, common_1.Injectable)(),
    __metadata("design:paramtypes", [prisma_service_1.PrismaService])
], ReportsService);
//# sourceMappingURL=reports.service.js.map
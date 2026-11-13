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
exports.DashboardService = void 0;
const common_1 = require("@nestjs/common");
const contracts_1 = require("@fluggi/contracts");
const domain_1 = require("@fluggi/domain");
const prisma_service_1 = require("../../core/prisma/prisma.service");
const finance_service_1 = require("../finance/finance.service");
const project_access_service_1 = require("../projects/project-access.service");
const kpi_service_1 = require("./kpi.service");
const TZ_MS = 5 * 3_600_000;
/** Месяц (YYYY-MM) начала периода — для целей KPI. */
const monthOf = (d) => new Date(d.getTime() + TZ_MS).toISOString().slice(0, 7);
/**
 * Дашборды по ролям (ТЗ §5, §58–60). Один запрос возвращает разделы,
 * доступные пользователю: CEO — компания, РОП — отдел, менеджер — свои показатели,
 * исполнитель — свои задачи.
 */
let DashboardService = class DashboardService {
    prisma;
    kpi;
    finance;
    projectsAccess;
    constructor(prisma, kpi, finance, projectsAccess) {
        this.prisma = prisma;
        this.kpi = kpi;
        this.finance = finance;
        this.projectsAccess = projectsAccess;
    }
    async get(auth, q) {
        const range = (0, contracts_1.resolvePeriodQuery)(q);
        const inRange = { gte: range.from, lt: range.to };
        const period = monthOf(range.from);
        const out = { from: range.from.toISOString(), to: range.to.toISOString() };
        if (auth.permissions['dashboard.ceo'] === 'ALL' && auth.permissions['finance.read']) {
            const [fin, newLeads, newDeals, contracts, projectsInProgress] = await Promise.all([
                this.finance.summary(auth, q),
                this.prisma.lead.count({ where: { createdAt: inRange, deletedAt: null } }),
                this.prisma.deal.count({ where: { createdAt: inRange, deletedAt: null } }),
                this.prisma.contract.count({ where: { status: 'SIGNED', signedAt: inRange } }),
                this.prisma.project.count({
                    where: { deletedAt: null, status: { in: [...contracts_1.ACTIVE_PROJECT_STATUSES] } },
                }),
            ]);
            out.ceo = {
                revenueUzs: fin.revenueUzs,
                profitUzs: fin.grossProfitUzs,
                paidUzs: fin.collectedUzs,
                expectedUzs: fin.receivablesUzs,
                newLeads,
                newDeals,
                contracts,
                projectsInProgress,
            };
        }
        if (auth.roleCode === 'ROP' &&
            auth.headedTeamIds.length &&
            auth.permissions['dashboard.team']) {
            const teamIds = auth.headedTeamIds;
            const team = { teamId: { in: teamIds } };
            const [me] = await this.kpi.rows(auth, period, range, { userIds: [auth.userId] });
            const [leads, meetings, proposals, contracts, payments, managers, teamRow] = await Promise.all([
                this.prisma.lead.count({ where: { ...team, createdAt: inRange, deletedAt: null } }),
                this.prisma.meeting.count({ where: { ...team, status: 'DONE', startsAt: inRange } }),
                this.prisma.proposal.count({ where: { sentAt: inRange, deal: team } }),
                this.prisma.contract.count({
                    where: { status: 'SIGNED', signedAt: inRange, deal: team },
                }),
                this.prisma.payment.count({
                    where: { status: 'PAID', type: { not: 'REFUND' }, paidAt: inRange, deal: team },
                }),
                this.kpi.rows(auth, period, range, { group: 'MANAGER' }),
                this.prisma.team.findFirst({
                    where: { id: { in: teamIds } },
                    select: { id: true, name: true },
                }),
            ]);
            if (me?.rop)
                out.team = {
                    team: teamRow,
                    kpi: me.rop,
                    leads,
                    meetings,
                    proposals,
                    contracts,
                    payments,
                    managers,
                };
        }
        if (auth.roleCode === 'MANAGER') {
            const [me] = await this.kpi.rows(auth, period, range, { userIds: [auth.userId] });
            const now = new Date();
            const [deals, commission, tasksOpen, tasksOverdue] = await Promise.all([
                this.prisma.deal.count({
                    where: { ownerId: auth.userId, status: 'OPEN', deletedAt: null },
                }),
                this.prisma.commission.aggregate({
                    where: { userId: auth.userId, status: { not: 'CANCELLED' }, createdAt: inRange },
                    _sum: { amountUzs: true },
                }),
                this.prisma.task.count({
                    where: {
                        assigneeId: auth.userId,
                        deletedAt: null,
                        status: { in: [...contracts_1.OPEN_TASK_STATUSES] },
                    },
                }),
                this.prisma.task.count({
                    where: {
                        assigneeId: auth.userId,
                        deletedAt: null,
                        status: { in: [...contracts_1.OPEN_TASK_STATUSES] },
                        deadline: { lt: now },
                    },
                }),
            ]);
            if (me)
                out.own = {
                    kpi: me,
                    deals,
                    commissionUzs: (commission._sum.amountUzs ?? 0).toString(),
                    tasksOpen,
                    tasksOverdue,
                };
        }
        if (auth.roleCode === 'EXECUTOR') {
            const now = new Date();
            const tomorrow = new Date((0, domain_1.companyDayStart)((0, domain_1.companyDate)(now)).getTime() + 86_400_000);
            const mine = { assigneeId: auth.userId, deletedAt: null, project: { deletedAt: null } };
            const open = { in: [...contracts_1.OPEN_TASK_STATUSES] };
            const [projects, today, overdue, inProgress, done] = await Promise.all([
                this.prisma.project.count({
                    where: {
                        deletedAt: null,
                        status: { in: [...contracts_1.ACTIVE_PROJECT_STATUSES] },
                        members: { some: { userId: auth.userId, status: 'ACTIVE' } },
                    },
                }),
                this.prisma.task.count({
                    where: { ...mine, status: open, deadline: { gte: now, lt: tomorrow } },
                }),
                this.prisma.task.count({ where: { ...mine, status: open, deadline: { lt: now } } }),
                this.prisma.task.count({ where: { ...mine, status: 'IN_PROGRESS' } }),
                this.prisma.task.count({ where: { ...mine, status: 'DONE', completedAt: inRange } }),
            ]);
            out.executor = { projects, today, overdue, inProgress, done };
        }
        if (auth.roleCode === 'PROJECT_MANAGER') {
            const now = new Date();
            const todayStart = (0, domain_1.companyDayStart)((0, domain_1.companyDate)(now));
            const tomorrow = new Date(todayStart.getTime() + 86_400_000);
            const visible = this.projectsAccess.projectWhere(auth);
            const active = { AND: [visible, { status: { in: [...contracts_1.ACTIVE_PROJECT_STATUSES] } }] };
            const tasks = this.projectsAccess.taskWhere(auth);
            const open = { status: { in: [...contracts_1.OPEN_TASK_STATUSES] } };
            const [directions, activeN, overdueN, unassigned, tasksOpen, tasksOverdue, tasksToday, completed,] = await Promise.all([
                this.prisma.direction.findMany({
                    where: { id: { in: auth.directionIds } },
                    orderBy: { sort: 'asc' },
                }),
                this.prisma.project.count({ where: active }),
                this.prisma.project.count({ where: { AND: [active, { deadline: { lt: todayStart } }] } }),
                this.prisma.project.count({
                    where: {
                        AND: [active, { members: { none: { status: { not: 'REMOVED' } } } }],
                    },
                }),
                this.prisma.task.count({ where: { AND: [tasks, open] } }),
                this.prisma.task.count({ where: { AND: [tasks, open, { deadline: { lt: now } }] } }),
                this.prisma.task.count({
                    where: { AND: [tasks, open, { deadline: { gte: todayStart, lt: tomorrow } }] },
                }),
                this.prisma.project.count({
                    where: { AND: [visible, { status: 'COMPLETED', completedAt: inRange }] },
                }),
            ]);
            out.projects = {
                directions: directions.map((d) => d.name),
                active: activeN,
                overdueProjects: overdueN,
                unassigned,
                tasksOpen,
                tasksOverdue,
                tasksToday,
                completed,
            };
        }
        if (auth.roleCode !== 'CEO')
            out.myKpi = (await this.myKpi(auth, period, range)) ?? undefined;
        return out;
    }
    /** «Мой KPI»: выполнение целей месяца и сумма KPI-бонуса по текущему выполнению. */
    async myKpi(auth, period, range) {
        const [me] = await this.kpi.rows(auth, period, range, { userIds: [auth.userId] });
        if (!me)
            return null;
        const [employee, commission, piece] = await Promise.all([
            this.prisma.employee.findUnique({ where: { userId: auth.userId } }),
            this.prisma.commission.aggregate({
                where: { userId: auth.userId, period, status: { not: 'CANCELLED' } },
                _sum: { amountUzs: true },
            }),
            // Сдельно: начисления по проектам за месяц
            this.prisma.expense.aggregate({
                where: {
                    payeeUserId: auth.userId,
                    category: 'EXECUTOR',
                    deletedAt: null,
                    expenseDate: { gte: range.from, lt: range.to },
                },
                _sum: { amountUzs: true },
            }),
        ]);
        const bonusTarget = employee?.kpiBonusTarget?.toFixed(2) ?? null;
        const bonusUzs = (0, domain_1.kpiBonusFor)(bonusTarget, me.kpiPct);
        const commissionUzs = (commission._sum.amountUzs ?? 0).toString();
        // Оклад — только у менеджеров и РОП
        const baseSalary = (0, contracts_1.hasFixedSalary)(auth.roleCode)
            ? (employee?.baseSalary?.toFixed(2) ?? null)
            : null;
        const pieceRateUzs = (piece._sum.amountUzs ?? 0).toString();
        return {
            period,
            pct: me.kpiPct,
            targets: me.targets,
            bonusTarget,
            bonusUzs,
            commissionUzs: Number(commissionUzs).toFixed(2),
            baseSalary,
            pieceRateUzs: Number(pieceRateUzs).toFixed(2),
            expectedUzs: (0, domain_1.finalSalary)({
                baseSalary: baseSalary ?? 0,
                pieceRate: pieceRateUzs,
                kpiBonus: bonusUzs,
                commission: commissionUzs,
                otherBonus: 0,
                penalty: 0,
            }),
        };
    }
};
exports.DashboardService = DashboardService;
exports.DashboardService = DashboardService = __decorate([
    (0, common_1.Injectable)(),
    __metadata("design:paramtypes", [prisma_service_1.PrismaService,
        kpi_service_1.KpiService,
        finance_service_1.FinanceService,
        project_access_service_1.ProjectAccessService])
], DashboardService);
//# sourceMappingURL=dashboard.service.js.map
import { Injectable } from '@nestjs/common';
import {
  hasFixedSalary,
  type MyKpiDto,
  ACTIVE_PROJECT_STATUSES,
  OPEN_TASK_STATUSES,
  resolvePeriodQuery,
  type DashboardDto,
  type periodQuerySchema,
} from '@fluggi/contracts';
import { companyDate, companyDayStart, finalSalary, kpiBonusFor } from '@fluggi/domain';
import type { z } from 'zod';
import type { AuthContext } from '../../core/auth/auth-context';
import { PrismaService } from '../../core/prisma/prisma.service';
import { FinanceService } from '../finance/finance.service';
import { ProjectAccessService } from '../projects/project-access.service';
import { KpiService } from './kpi.service';

const TZ_MS = 5 * 3_600_000;
/** Месяц (YYYY-MM) начала периода — для целей KPI. */
const monthOf = (d: Date) => new Date(d.getTime() + TZ_MS).toISOString().slice(0, 7);

/**
 * Дашборды по ролям (ТЗ §5, §58–60). Один запрос возвращает разделы,
 * доступные пользователю: CEO — компания, РОП — отдел, менеджер — свои показатели,
 * исполнитель — свои задачи.
 */
@Injectable()
export class DashboardService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly kpi: KpiService,
    private readonly finance: FinanceService,
    private readonly projectsAccess: ProjectAccessService,
  ) {}

  async get(auth: AuthContext, q: z.output<typeof periodQuerySchema>): Promise<DashboardDto> {
    const range = resolvePeriodQuery(q);
    const inRange = { gte: range.from, lt: range.to };
    const period = monthOf(range.from);
    const out: DashboardDto = { from: range.from.toISOString(), to: range.to.toISOString() };

    if (auth.permissions['dashboard.ceo'] === 'ALL' && auth.permissions['finance.read']) {
      const [fin, newLeads, newDeals, contracts, projectsInProgress] = await Promise.all([
        this.finance.summary(auth, q),
        this.prisma.lead.count({ where: { createdAt: inRange, deletedAt: null } }),
        this.prisma.deal.count({ where: { createdAt: inRange, deletedAt: null } }),
        this.prisma.contract.count({ where: { status: 'SIGNED', signedAt: inRange } }),
        this.prisma.project.count({
          where: { deletedAt: null, status: { in: [...ACTIVE_PROJECT_STATUSES] } },
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

    if (
      auth.roleCode === 'ROP' &&
      auth.headedTeamIds.length &&
      auth.permissions['dashboard.team']
    ) {
      const teamIds = auth.headedTeamIds;
      const team = { teamId: { in: teamIds } };
      const [me] = await this.kpi.rows(auth, period, range, { userIds: [auth.userId] });
      const [leads, meetings, proposals, contracts, payments, managers, teamRow] =
        await Promise.all([
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
            status: { in: [...OPEN_TASK_STATUSES] },
          },
        }),
        this.prisma.task.count({
          where: {
            assigneeId: auth.userId,
            deletedAt: null,
            status: { in: [...OPEN_TASK_STATUSES] },
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
      const tomorrow = new Date(companyDayStart(companyDate(now)).getTime() + 86_400_000);
      const mine = { assigneeId: auth.userId, deletedAt: null, project: { deletedAt: null } };
      const open = { in: [...OPEN_TASK_STATUSES] };
      const [projects, today, overdue, inProgress, done] = await Promise.all([
        this.prisma.project.count({
          where: {
            deletedAt: null,
            status: { in: [...ACTIVE_PROJECT_STATUSES] },
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
      const todayStart = companyDayStart(companyDate(now));
      const tomorrow = new Date(todayStart.getTime() + 86_400_000);
      const visible = this.projectsAccess.projectWhere(auth);
      const active = { AND: [visible, { status: { in: [...ACTIVE_PROJECT_STATUSES] } }] };
      const tasks = this.projectsAccess.taskWhere(auth);
      const open = { status: { in: [...OPEN_TASK_STATUSES] } };
      const [
        directions,
        activeN,
        overdueN,
        unassigned,
        tasksOpen,
        tasksOverdue,
        tasksToday,
        completed,
      ] = await Promise.all([
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

    if (auth.roleCode !== 'CEO') out.myKpi = (await this.myKpi(auth, period, range)) ?? undefined;
    return out;
  }

  /** «Мой KPI»: выполнение целей месяца и сумма KPI-бонуса по текущему выполнению. */
  private async myKpi(
    auth: AuthContext,
    period: string,
    range: { from: Date; to: Date },
  ): Promise<MyKpiDto | null> {
    const [me] = await this.kpi.rows(auth, period, range, { userIds: [auth.userId] });
    if (!me) return null;
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
    const bonusUzs = kpiBonusFor(bonusTarget, me.kpiPct);
    const commissionUzs = (commission._sum.amountUzs ?? 0).toString();
    // Оклад — только у менеджеров и РОП
    const baseSalary = hasFixedSalary(auth.roleCode)
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
      expectedUzs: finalSalary({
        baseSalary: baseSalary ?? 0,
        pieceRate: pieceRateUzs,
        kpiBonus: bonusUzs,
        commission: commissionUzs,
        otherBonus: 0,
        penalty: 0,
      }),
    };
  }
}

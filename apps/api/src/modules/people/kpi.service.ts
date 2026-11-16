import { Injectable } from '@nestjs/common';
import {
  ACTIVE_PROJECT_STATUSES,
  OPEN_TASK_STATUSES,
  type ExecutorKpi,
  type KpiGroup,
  type KpiMetric,
  type KpiRowDto,
  type ManagerKpi,
  type PermissionCode,
  type RoleCode,
  type RopKpi,
  type TargetProgressDto,
} from '@fluggi/contracts';
import { averagePct, companyDate, completionPct, marginPct } from '@fluggi/domain';
import { Prisma } from '@fluggi/db';
import type { AuthContext } from '../../core/auth/auth-context';
import { notFound } from '../../core/http/app.exception';
import { parseDate } from '../../core/http/serialize';
import { PrismaService } from '../../core/prisma/prisma.service';
import { scopeOf } from '../../core/rbac/scope';

export interface Range {
  from: Date;
  to: Date;
}

const ZERO = new Prisma.Decimal(0);
const pct = (part: number, total: number) => (total > 0 ? ((part / total) * 100).toFixed(2) : null);
const avg = (sum: Prisma.Decimal, n: number) => (n > 0 ? sum.div(n) : ZERO).toFixed(2);

type Person = {
  id: string;
  fullName: string;
  teamId: string | null;
  role: { code: RoleCode };
  team: { id: string; name: string } | null;
  headedTeams: { id: string }[];
};

const GROUP_OF: Partial<Record<RoleCode, KpiGroup>> = {
  MANAGER: 'MANAGER',
  ROP: 'ROP',
  EXECUTOR: 'EXECUTOR',
};

/**
 * KPI сотрудников (ТЗ §28–31). Считается «на лету» из исходных таблиц за период —
 * один источник правды с CRM, проектами и оплатами.
 */
@Injectable()
export class KpiService {
  constructor(private readonly prisma: PrismaService) {}

  /** Сотрудники, чьи KPI видит пользователь (право code). */
  usersWhere(auth: AuthContext, code: PermissionCode): Prisma.UserWhereInput {
    const scope = scopeOf(auth, code);
    const base: Prisma.UserWhereInput = { deletedAt: null };
    if (scope === 'ALL') return base;
    if (scope === 'TEAM') {
      const teams = [...new Set([...auth.headedTeamIds, ...(auth.teamId ? [auth.teamId] : [])])];
      return { ...base, OR: [{ teamId: { in: teams } }, { id: auth.userId }] };
    }
    return { ...base, id: auth.userId };
  }

  private people(where: Prisma.UserWhereInput) {
    return this.prisma.user.findMany({
      where,
      select: {
        id: true,
        fullName: true,
        teamId: true,
        role: { select: { code: true } },
        team: { select: { id: true, name: true } },
        headedTeams: { where: { deletedAt: null }, select: { id: true } },
      },
      orderBy: { fullName: 'asc' },
    });
  }

  private async usdRate() {
    const r = await this.prisma.exchangeRate.findFirst({
      where: { currency: 'USD' },
      orderBy: { date: 'desc' },
    });
    return r ? Number(r.rateToUzs) : 0;
  }

  // ─────────────────────────── Менеджер (§28) ───────────────────────────

  async managers(ids: string[], r: Range): Promise<Map<string, ManagerKpi>> {
    const inRange = { gte: r.from, lt: r.to };
    const [leads, meetings, proposals, contracts, payments, won] = await Promise.all([
      this.prisma.lead.findMany({
        where: { ownerId: { in: ids }, createdAt: inRange, deletedAt: null },
        select: { ownerId: true, status: true, dealId: true, stage: { select: { code: true } } },
      }),
      this.prisma.meeting.groupBy({
        by: ['managerId'],
        where: { managerId: { in: ids }, status: 'DONE', startsAt: inRange },
        _count: { _all: true },
      }),
      this.prisma.proposal.groupBy({
        by: ['managerId'],
        where: { managerId: { in: ids }, sentAt: inRange },
        _count: { _all: true },
      }),
      this.prisma.contract.findMany({
        where: { status: 'SIGNED', signedAt: inRange, deal: { ownerId: { in: ids } } },
        select: { deal: { select: { ownerId: true } } },
      }),
      this.prisma.payment.findMany({
        where: { status: 'PAID', paidAt: inRange, deal: { ownerId: { in: ids } } },
        select: { type: true, amountUzs: true, deal: { select: { ownerId: true } } },
      }),
      this.prisma.deal.groupBy({
        by: ['ownerId'],
        where: { ownerId: { in: ids }, wonAt: inRange },
        _count: { _all: true },
      }),
    ]);
    const out = new Map<string, ManagerKpi>();
    for (const id of ids) {
      const mine = leads.filter((l) => l.ownerId === id);
      const pays = payments.filter((p) => p.deal.ownerId === id);
      const revenue = pays.reduce(
        (s, p) => (p.type === 'REFUND' ? s.sub(p.amountUzs) : s.add(p.amountUzs)),
        ZERO,
      );
      const orders = won.find((w) => w.ownerId === id)?._count._all ?? 0;
      out.set(id, {
        leads: mine.length,
        processedLeads: mine.filter((l) => l.status !== 'OPEN' || l.stage.code !== 'NEW').length,
        meetings: meetings.find((m) => m.managerId === id)?._count._all ?? 0,
        proposals: proposals.find((p) => p.managerId === id)?._count._all ?? 0,
        contracts: contracts.filter((c) => c.deal.ownerId === id).length,
        payments: pays.filter((p) => p.type !== 'REFUND').length,
        orders,
        revenueUzs: revenue.toFixed(2),
        avgCheckUzs: avg(revenue, orders),
        conversionPct: pct(mine.filter((l) => l.dealId).length, mine.length),
      });
    }
    return out;
  }

  // ─────────────────────────── РОП (§29) ───────────────────────────

  async rop(teamIds: string[], r: Range, plan: Prisma.Decimal): Promise<RopKpi> {
    const inRange = { gte: r.from, lt: r.to };
    const team = { teamId: { in: teamIds } };
    const now = new Date();
    const [payments, orders, leads, managers, expenses, projects, overdueTasks, overdueProjects] =
      await Promise.all([
        this.prisma.payment.findMany({
          where: { status: 'PAID', paidAt: inRange, deal: team },
          select: { type: true, amountUzs: true },
        }),
        this.prisma.deal.count({ where: { ...team, wonAt: inRange } }),
        this.prisma.lead.findMany({
          where: { ...team, createdAt: inRange, deletedAt: null },
          select: { dealId: true },
        }),
        this.prisma.user.count({
          where: { ...team, deletedAt: null, status: 'ACTIVE', role: { code: 'MANAGER' } },
        }),
        this.prisma.expense.aggregate({
          where: {
            deletedAt: null,
            scope: 'PROJECT',
            project: team,
            expenseDate: {
              gte: new Date(r.from.getTime() + 5 * 3_600_000),
              lt: new Date(r.to.getTime() + 5 * 3_600_000),
            },
          },
          _sum: { amountUzs: true },
        }),
        this.prisma.project.count({ where: { ...team, deletedAt: null, createdAt: inRange } }),
        this.prisma.task.count({
          where: {
            deletedAt: null,
            project: { ...team, deletedAt: null },
            status: { in: [...OPEN_TASK_STATUSES] },
            deadline: { lt: now },
          },
        }),
        this.prisma.project.count({
          where: {
            ...team,
            deletedAt: null,
            status: { in: [...ACTIVE_PROJECT_STATUSES] },
            deadline: { lt: parseDate(companyDate(now))! },
          },
        }),
      ]);
    const net = payments.reduce(
      (s, p) => (p.type === 'REFUND' ? s.sub(p.amountUzs) : s.add(p.amountUzs)),
      ZERO,
    );
    const gross = net.sub(expenses._sum.amountUzs ?? ZERO);
    return {
      teamRevenueUzs: net.toFixed(2),
      orders,
      avgCheckUzs: avg(net, orders),
      conversionPct: pct(leads.filter((l) => l.dealId).length, leads.length),
      managers,
      planUzs: plan.toFixed(2),
      planPct: completionPct(net.toString(), plan.gt(0) ? plan.toString() : null),
      marginPct: marginPct(gross.toString(), net.toString()),
      projects,
      overdueTasks,
      overdueProjects,
    };
  }

  // ─────────────────────────── Исполнитель (§30) ───────────────────────────

  async executors(ids: string[], r: Range): Promise<Map<string, ExecutorKpi>> {
    const now = new Date();
    const until = r.to < now ? r.to : now;
    const [tasks, reworks] = await Promise.all([
      this.prisma.task.findMany({
        where: {
          assigneeId: { in: ids },
          deletedAt: null,
          status: { not: 'CANCELLED' },
          createdAt: { lt: r.to },
          OR: [{ completedAt: null }, { completedAt: { gte: r.from } }],
        },
        select: {
          assigneeId: true,
          status: true,
          deadline: true,
          startedAt: true,
          completedAt: true,
        },
      }),
      this.prisma.taskStatusHistory.findMany({
        where: {
          fromStatus: 'REVIEW',
          toStatus: { in: ['IN_PROGRESS', 'TODO'] },
          createdAt: { gte: r.from, lt: r.to },
          task: { assigneeId: { in: ids } },
        },
        select: { task: { select: { assigneeId: true } } },
      }),
    ]);
    const out = new Map<string, ExecutorKpi>();
    for (const id of ids) {
      const mine = tasks.filter((t) => t.assigneeId === id);
      const done = mine.filter(
        (t) =>
          t.status === 'DONE' && t.completedAt && t.completedAt >= r.from && t.completedAt < r.to,
      );
      const overdue = mine.filter((t) =>
        t.status === 'DONE'
          ? Boolean(
              t.deadline && t.completedAt && t.completedAt > t.deadline && t.completedAt >= r.from,
            )
          : Boolean(t.deadline && t.deadline < until),
      ).length;
      const timed = done.filter((t) => t.startedAt);
      const hours = timed.reduce(
        (s, t) => s + (t.completedAt!.getTime() - t.startedAt!.getTime()) / 3_600_000,
        0,
      );
      out.set(id, {
        tasks: mine.length,
        done: done.length,
        overdue,
        avgHours: timed.length ? (hours / timed.length).toFixed(1) : null,
        completionPct: pct(done.length, mine.length),
        reworks: reworks.filter((x) => x.task.assigneeId === id).length,
      });
    }
    return out;
  }

  // ─────────────────────────── Цели (§31) ───────────────────────────

  private targetsOf(userIds: string[], period: string) {
    return this.prisma.kpiTarget.findMany({ where: { userId: { in: userIds }, period } });
  }

  private progress(
    targets: { metric: KpiMetric; targetValue: Prisma.Decimal; currency: 'UZS' | 'USD' }[],
    facts: Partial<Record<KpiMetric, number>>,
    usd: number,
  ): TargetProgressDto[] {
    return targets.map((t) => {
      const raw = facts[t.metric] ?? 0;
      const fact = t.metric === 'REVENUE' && t.currency === 'USD' && usd ? raw / usd : raw;
      return {
        metric: t.metric,
        target: t.targetValue.toFixed(2),
        currency: t.currency,
        fact: fact.toFixed(2),
        pct: completionPct(fact, t.targetValue.toString()),
      };
    });
  }

  /**
   * Строки KPI для видимых сотрудников. Цели — на месяц `period`;
   * факт — за диапазон (для дашборда это может быть неделя или квартал).
   */
  async rows(
    auth: AuthContext,
    period: string,
    range: Range,
    opts: { group?: KpiGroup; userIds?: string[]; code?: PermissionCode } = {},
  ): Promise<KpiRowDto[]> {
    const where: Prisma.UserWhereInput = {
      AND: [
        this.usersWhere(auth, opts.code ?? 'kpi.read'),
        { status: 'ACTIVE', role: { code: { in: ['MANAGER', 'ROP', 'EXECUTOR'] } } },
        opts.group ? { role: { code: opts.group } } : {},
        opts.userIds ? { id: { in: opts.userIds } } : {},
      ],
    };
    const people = await this.people(where);
    return this.build(people, period, range);
  }

  async build(people: Person[], period: string, range: Range): Promise<KpiRowDto[]> {
    const ids = (g: KpiGroup) => people.filter((p) => GROUP_OF[p.role.code] === g).map((p) => p.id);
    const [mgr, exe, targets, usd] = await Promise.all([
      this.managers(ids('MANAGER'), range),
      this.executors(ids('EXECUTOR'), range),
      this.targetsOf(
        people.map((p) => p.id),
        period,
      ),
      this.usdRate(),
    ]);
    const rows: KpiRowDto[] = [];
    for (const p of people) {
      const group = GROUP_OF[p.role.code];
      if (!group) continue;
      const mine = targets.filter((t) => t.userId === p.id);
      let facts: Partial<Record<KpiMetric, number>> = {};
      const row: KpiRowDto = {
        user: { id: p.id, name: p.fullName },
        role: p.role.code,
        team: p.team,
        targets: [],
        kpiPct: null,
      };
      if (group === 'MANAGER') {
        const k = mgr.get(p.id)!;
        row.manager = k;
        facts = {
          REVENUE: Number(k.revenueUzs),
          ORDERS: k.orders,
          LEADS: k.leads,
          MEETINGS: k.meetings,
        };
      } else if (group === 'EXECUTOR') {
        const k = exe.get(p.id)!;
        row.executor = k;
        facts = { TASKS: k.done };
      } else {
        const teamIds = p.headedTeams.length
          ? p.headedTeams.map((t) => t.id)
          : p.teamId
            ? [p.teamId]
            : [];
        const own = mine.find((t) => t.metric === 'REVENUE');
        let plan = ZERO;
        if (own) plan = own.currency === 'USD' ? own.targetValue.mul(usd) : own.targetValue;
        else {
          const team = await this.prisma.kpiTarget.findMany({
            where: {
              period,
              metric: 'REVENUE',
              user: { teamId: { in: teamIds }, role: { code: 'MANAGER' } },
            },
          });
          plan = team.reduce(
            (s, t) => s.add(t.currency === 'USD' ? t.targetValue.mul(usd) : t.targetValue),
            ZERO,
          );
        }
        const k = await this.rop(teamIds, range, plan);
        row.rop = k;
        const [leads, meetings] = await Promise.all([
          this.prisma.lead.count({
            where: {
              teamId: { in: teamIds },
              createdAt: { gte: range.from, lt: range.to },
              deletedAt: null,
            },
          }),
          this.prisma.meeting.count({
            where: {
              teamId: { in: teamIds },
              status: 'DONE',
              startsAt: { gte: range.from, lt: range.to },
            },
          }),
        ]);
        facts = {
          REVENUE: Number(k.teamRevenueUzs),
          ORDERS: k.orders,
          LEADS: leads,
          MEETINGS: meetings,
        };
      }
      row.targets = this.progress(mine, facts, usd);
      row.kpiPct = averagePct(row.targets.map((t) => t.pct));
      rows.push(row);
    }
    return rows;
  }

  async targets(auth: AuthContext, userId: string, period: string) {
    const visible = await this.prisma.user.count({
      where: { AND: [this.usersWhere(auth, 'kpi.read'), { id: userId }] },
    });
    if (!visible) throw notFound('Сотрудник');
    return this.prisma.kpiTarget.findMany({ where: { userId, period } });
  }
}

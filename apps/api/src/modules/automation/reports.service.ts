import { Injectable } from '@nestjs/common';
import { ACTIVE_PROJECT_STATUSES, OPEN_TASK_STATUSES } from '@fluggi/contracts';
import { companyDate, companyDayStart, marginPct, monthRange } from '@fluggi/domain';
import { Prisma } from '@fluggi/db';
import { parseDate } from '../../core/http/serialize';
import { PrismaService } from '../../core/prisma/prisma.service';

const ZERO = new Prisma.Decimal(0);
const DAY = 86_400_000;
const mln = (v: Prisma.Decimal | number) => {
  const n = Number(v);
  if (Math.abs(n) >= 1e6)
    return `${(n / 1e6).toLocaleString('ru-RU', { maximumFractionDigits: 1 })} млн`;
  return `${Math.round(n).toLocaleString('ru-RU')}`;
};
const uzs = (v: Prisma.Decimal | number) => `${Math.round(Number(v)).toLocaleString('ru-RU')} UZS`;
const dayTitle = (d: Date) =>
  new Date(d.getTime() + 5 * 3_600_000)
    .toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', timeZone: 'UTC' })
    .toUpperCase();

export interface ReportScope {
  /** Отделы для отчёта РОП; пусто — вся компания. */
  teamIds?: string[];
}

/**
 * Отчёты руководителю (ТЗ §56–57). Тексты — для Telegram (HTML) и in-app.
 */
@Injectable()
export class ReportsService {
  constructor(private readonly prisma: PrismaService) {}

  private deal(scope: ReportScope): Prisma.DealWhereInput {
    return scope.teamIds ? { teamId: { in: scope.teamIds } } : {};
  }

  private async collected(scope: ReportScope, from: Date, to: Date) {
    const rows = await this.prisma.payment.findMany({
      where: { status: 'PAID', paidAt: { gte: from, lt: to }, deal: this.deal(scope) },
      select: {
        type: true,
        amountUzs: true,
        deal: { select: { owner: { select: { fullName: true } } } },
      },
    });
    const net = rows.reduce(
      (s, p) => (p.type === 'REFUND' ? s.sub(p.amountUzs) : s.add(p.amountUzs)),
      ZERO,
    );
    const byManager = new Map<string, Prisma.Decimal>();
    for (const p of rows) {
      const name = p.deal.owner.fullName;
      byManager.set(
        name,
        (byManager.get(name) ?? ZERO)[p.type === 'REFUND' ? 'sub' : 'add'](p.amountUzs),
      );
    }
    const ranking = [...byManager].sort((a, b) => Number(b[1]) - Number(a[1]));
    return {
      net,
      ranking,
      refunds: rows.filter((p) => p.type === 'REFUND').reduce((s, p) => s.add(p.amountUzs), ZERO),
    };
  }

  /** План месяца: сумма целей «Выручка» менеджеров (UZS; USD по текущему курсу). */
  private async monthPlan(scope: ReportScope, period: string) {
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
    return targets.reduce(
      (s, t) => s.add(t.currency === 'USD' ? t.targetValue.mul(rate) : t.targetValue),
      ZERO,
    );
  }

  /** Прогноз месяца: получено + взвешенная воронка открытых сделок (ТЗ §40). */
  private async forecast(scope: ReportScope, collectedMonth: Prisma.Decimal) {
    const open = await this.prisma.deal.findMany({
      where: { status: 'OPEN', deletedAt: null, ...this.deal(scope) },
      select: {
        amountUzs: true,
        probabilityOverride: true,
        stage: { select: { probability: true } },
      },
    });
    const weighted = open.reduce(
      (s, d) => s.add(d.amountUzs.mul(d.probabilityOverride ?? d.stage.probability).div(100)),
      ZERO,
    );
    return collectedMonth.add(weighted);
  }

  async planProgress(scope: ReportScope, now = new Date()) {
    const period = companyDate(now).slice(0, 7);
    const m = monthRange(period);
    const [plan, got] = await Promise.all([
      this.monthPlan(scope, period),
      this.collected(scope, m.from, m.to),
    ]);
    return { period, plan, collected: got.net };
  }

  /** Ежедневный отчёт за день (ТЗ §56). */
  async daily(scope: ReportScope, now = new Date()): Promise<string> {
    const date = companyDate(now);
    const from = companyDayStart(date);
    const to = new Date(from.getTime() + DAY);
    const inDay = { gte: from, lt: to };
    const team = scope.teamIds ? { teamId: { in: scope.teamIds } } : {};
    const [
      leads,
      qualified,
      meetings,
      proposals,
      contracts,
      day,
      projects,
      overdueTasks,
      overdueProjects,
      month,
    ] = await Promise.all([
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
          status: { in: [...OPEN_TASK_STATUSES] },
          deadline: { lt: now },
          project: { deletedAt: null, ...team },
        },
      }),
      this.prisma.project.count({
        where: {
          ...team,
          deletedAt: null,
          status: { in: [...ACTIVE_PROJECT_STATUSES] },
          deadline: { lt: parseDate(date)! },
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
  async weekly(scope: ReportScope, now = new Date()): Promise<string> {
    const to = companyDayStart(companyDate(now));
    const from = new Date(to.getTime() - 7 * DAY);
    const inWeek = { gte: from, lt: to };
    const team = scope.teamIds ? { teamId: { in: scope.teamIds } } : {};
    const dateRange = {
      gte: new Date(from.getTime() + 5 * 3_600_000),
      lt: new Date(to.getTime() + 5 * 3_600_000),
    };
    const [leads, converted, deals, won, money, projectExp, companyExp, projects, overdueTasks] =
      await Promise.all([
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
            status: { in: [...OPEN_TASK_STATUSES] },
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
      `Прибыль: ${uzs(profit)}${marginPct(profit.toString(), money.net.toString()) ? ` (${marginPct(profit.toString(), money.net.toString())}%)` : ''}`,
      `Расходы: ${uzs(expenses)}`,
      `Проекты: ${projects}`,
      `Просроченные задачи: ${overdueTasks}`,
      '',
      'Эффективность сотрудников:',
      ...(top.length ? top : ['—']),
    ].join('\n');
  }
}

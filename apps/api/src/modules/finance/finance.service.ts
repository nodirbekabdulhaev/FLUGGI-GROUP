import { Injectable } from '@nestjs/common';
import {
  formatNumber,
  resolvePeriodQuery,
  type CategoryAmountDto,
  type FinanceSummaryDto,
  type Paginated,
  type periodQuerySchema,
  type ProjectFinanceDto,
  type projectProfitQuerySchema,
  type ProjectProfitDto,
} from '@fluggi/contracts';
import { companyFinance, projectFinance } from '@fluggi/domain';
import { Prisma } from '@fluggi/db';
import type { z } from 'zod';
import type { AuthContext } from '../../core/auth/auth-context';
import { PrismaService } from '../../core/prisma/prisma.service';
import { CrmAccessService } from '../crm/crm-access.service';
import { ProjectAccessService } from '../projects/project-access.service';
import { ExchangeRateService } from '../references/exchange-rate.service';
import { ExpensesService } from './expenses.service';
import { OverheadService } from './overhead.service';

const ZERO = new Prisma.Decimal(0);
const dec = (v: Prisma.Decimal | null | undefined) => v ?? ZERO;
const money = (v: Prisma.Decimal) => v.toFixed(2);

/** Финансы проекта и компании (ТЗ §25, §27; формулы — docs/BUSINESS_RULES.md §4). */
@Injectable()
export class FinanceService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly projects: ProjectAccessService,
    private readonly crm: CrmAccessService,
    private readonly expenses: ExpensesService,
    private readonly overhead: OverheadService,
    private readonly rates: ExchangeRateService,
  ) {}

  /** Названия категорий по коду (справочник редактирует CEO). */
  private async categoryNames() {
    const rows = await this.prisma.financeCategory.findMany({ select: { code: true, name: true } });
    return new Map(rows.map((r) => [r.code, r.name]));
  }

  /** Плановые (ещё не начисленные) расходы по тарифу, UZS. */
  private async plannedCost(projectId: string) {
    const lines = await this.prisma.projectCostLine.findMany({
      where: { projectId, status: 'PLANNED' },
      select: { quantity: true, rate: true, currency: true },
    });
    if (!lines.length) return ZERO;
    const usd = lines.some((l) => l.currency === 'USD')
      ? new Prisma.Decimal(await this.rates.rateFor('USD').catch(() => '0'))
      : ZERO;
    return lines.reduce(
      (s, l) => s.add(l.quantity.mul(l.rate).mul(l.currency === 'USD' ? usd : 1)),
      ZERO,
    );
  }

  /** Получено по сделкам: оплаты минус возвраты, по каждой сделке. */
  private async collectedByDeal(dealIds: string[]) {
    const rows = await this.prisma.payment.groupBy({
      by: ['dealId', 'type'],
      where: { dealId: { in: dealIds }, status: 'PAID' },
      _sum: { amountUzs: true },
    });
    const map = new Map<string, Prisma.Decimal>();
    for (const r of rows) {
      const v = dec(r._sum.amountUzs);
      map.set(r.dealId, (map.get(r.dealId) ?? ZERO).add(r.type === 'REFUND' ? v.neg() : v));
    }
    return map;
  }

  private async expensesByProject(projectIds: string[]) {
    const rows = await this.prisma.expense.groupBy({
      by: ['projectId'],
      where: { projectId: { in: projectIds }, deletedAt: null },
      _sum: { amountUzs: true },
    });
    return new Map(rows.map((r) => [r.projectId!, dec(r._sum.amountUzs)]));
  }

  /** Финансовая карточка проекта (ТЗ §25). */
  async project(auth: AuthContext, id: string): Promise<ProjectFinanceDto> {
    const p = await this.projects.project(auth, id, 'finance.read');
    const [collected, byCat, commissions, names, overhead, planned] = await Promise.all([
      this.collectedByDeal([p.dealId]),
      this.prisma.expense.groupBy({
        by: ['category'],
        where: { projectId: id, deletedAt: null },
        _sum: { amountUzs: true },
      }),
      this.prisma.commission.aggregate({
        where: { dealId: p.dealId, status: { not: 'CANCELLED' } },
        _sum: { amountUzs: true },
      }),
      this.categoryNames(),
      this.overhead.forProjects([p]),
      this.plannedCost(id),
    ]);
    const overheadUzs = overhead.get(p.id) ?? ZERO;
    const expenses = byCat.reduce((s, r) => s.add(dec(r._sum.amountUzs)), ZERO);
    const got = collected.get(p.dealId) ?? ZERO;
    const f = projectFinance(p.priceUzs.toString(), expenses.toString());
    return {
      revenueUzs: money(p.priceUzs),
      collectedUzs: money(got),
      receivableUzs: money(Prisma.Decimal.max(p.priceUzs.sub(got), ZERO)),
      expensesUzs: money(expenses),
      byCategory: byCat
        .map((r) => ({
          category: r.category,
          name: names.get(r.category) ?? r.category,
          amountUzs: money(dec(r._sum.amountUzs)),
        }))
        .sort((a, b) => Number(b.amountUzs) - Number(a.amountUzs)),
      grossProfitUzs: f.grossProfit,
      marginPct: f.marginPct,
      commissionsUzs: money(dec(commissions._sum.amountUzs)),
      overheadUzs: money(overheadUzs),
      netProfitUzs: money(new Prisma.Decimal(f.grossProfit).sub(overheadUzs)),
      plannedCostUzs: money(planned),
    };
  }

  /**
   * Финансовый дашборд (ТЗ §27) за период. РОП видит показатели своего отдела,
   * CEO — компании целиком, включая расходы компании и операционную прибыль.
   */
  async summary(
    auth: AuthContext,
    q: z.output<typeof periodQuerySchema>,
  ): Promise<FinanceSummaryDto> {
    const { from, to } = resolvePeriodQuery(q);
    const deals = this.crm.dealWhere(auth, 'finance.read');
    const projectScope = this.projects.projectWhere(auth, 'finance.read');
    const company = auth.permissions['finance.company.read'] === 'ALL';
    const inRange = { gte: from, lt: to };
    // Дата расхода — календарная (без времени); границы периода — полночь по Ташкенту.
    const dateRange = {
      gte: new Date(from.getTime() + 5 * 3_600_000),
      lt: new Date(to.getTime() + 5 * 3_600_000),
    };

    const [
      revenue,
      collected,
      refunds,
      projectExp,
      companyExp,
      commissions,
      byCat,
      otherIncome,
      names,
    ] = await Promise.all([
      this.prisma.contract.aggregate({
        where: { status: 'SIGNED', signedAt: inRange, deal: deals },
        _sum: { amountUzs: true },
      }),
      this.prisma.payment.aggregate({
        where: { status: 'PAID', type: { not: 'REFUND' }, paidAt: inRange, deal: deals },
        _sum: { amountUzs: true },
      }),
      this.prisma.payment.aggregate({
        where: { status: 'PAID', type: 'REFUND', paidAt: inRange, deal: deals },
        _sum: { amountUzs: true },
      }),
      this.prisma.expense.aggregate({
        where: {
          deletedAt: null,
          scope: 'PROJECT',
          expenseDate: dateRange,
          project: projectScope,
        },
        _sum: { amountUzs: true },
      }),
      company
        ? this.prisma.expense.aggregate({
            where: { deletedAt: null, scope: 'COMPANY', expenseDate: dateRange },
            _sum: { amountUzs: true },
          })
        : null,
      this.prisma.commission.aggregate({
        where: { status: { not: 'CANCELLED' }, createdAt: inRange, deal: deals },
        _sum: { amountUzs: true },
      }),
      this.prisma.expense.groupBy({
        by: ['category'],
        where: { AND: [this.expenses.where(auth), { expenseDate: dateRange }] },
        _sum: { amountUzs: true },
      }),
      company
        ? this.prisma.otherIncome.aggregate({
            where: { deletedAt: null, incomeDate: dateRange },
            _sum: { amountUzs: true },
          })
        : null,
      this.categoryNames(),
    ]);

    const f = companyFinance({
      collected: dec(collected._sum.amountUzs).toString(),
      refunds: dec(refunds._sum.amountUzs).toString(),
      projectExpenses: dec(projectExp._sum.amountUzs).toString(),
      companyExpenses: dec(companyExp?._sum.amountUzs).toString(),
      commissions: dec(commissions._sum.amountUzs).toString(),
      otherIncome: dec(otherIncome?._sum.amountUzs).toString(),
    });
    const expensesByCategory: CategoryAmountDto[] = byCat
      .map((r) => ({
        category: r.category,
        name: names.get(r.category) ?? r.category,
        amountUzs: money(dec(r._sum.amountUzs)),
      }))
      .sort((a, b) => Number(b.amountUzs) - Number(a.amountUzs));

    return {
      from: from.toISOString(),
      to: to.toISOString(),
      revenueUzs: money(dec(revenue._sum.amountUzs)),
      collectedUzs: money(dec(collected._sum.amountUzs)),
      refundsUzs: money(dec(refunds._sum.amountUzs)),
      receivablesUzs: money(await this.receivables(deals)),
      projectExpensesUzs: money(dec(projectExp._sum.amountUzs)),
      companyExpensesUzs: company ? money(dec(companyExp?._sum.amountUzs)) : null,
      commissionsUzs: money(dec(commissions._sum.amountUzs)),
      otherIncomeUzs: company ? money(dec(otherIncome?._sum.amountUzs)) : null,
      grossProfitUzs: f.grossProfit,
      operatingProfitUzs: company ? f.operatingProfit : null,
      marginPct: f.marginPct,
      expensesByCategory,
    };
  }

  /** Дебиторка на сегодня: по каждой сделке подписано − получено (не меньше нуля). */
  private async receivables(deals: Prisma.DealWhereInput) {
    const signed = await this.prisma.contract.groupBy({
      by: ['dealId'],
      where: { status: 'SIGNED', deal: deals },
      _sum: { amountUzs: true },
    });
    const got = await this.collectedByDeal(signed.map((s) => s.dealId));
    return signed.reduce(
      (s, r) =>
        s.add(Prisma.Decimal.max(dec(r._sum.amountUzs).sub(got.get(r.dealId) ?? ZERO), ZERO)),
      ZERO,
    );
  }

  /** Прибыльность проектов: выручка, получено, расходы, валовая прибыль и маржа. */
  async projectsProfit(
    auth: AuthContext,
    q: z.output<typeof projectProfitQuerySchema>,
  ): Promise<Paginated<ProjectProfitDto>> {
    const where: Prisma.ProjectWhereInput = {
      AND: [
        this.projects.projectWhere(auth, 'finance.read'),
        q.q
          ? {
              OR: [
                { name: { contains: q.q, mode: 'insensitive' } },
                { client: { name: { contains: q.q, mode: 'insensitive' } } },
              ],
            }
          : {},
      ],
    };
    const [rows, total] = await Promise.all([
      this.prisma.project.findMany({
        where,
        include: { client: { select: { id: true, name: true } } },
        orderBy: { createdAt: 'desc' },
        skip: (q.page - 1) * q.pageSize,
        take: q.pageSize,
      }),
      this.prisma.project.count({ where }),
    ]);
    const [expenses, collected, overhead] = await Promise.all([
      this.expensesByProject(rows.map((r) => r.id)),
      this.collectedByDeal(rows.map((r) => r.dealId)),
      this.overhead.forProjects(rows),
    ]);
    return {
      items: rows.map((p) => {
        const exp = expenses.get(p.id) ?? ZERO;
        const f = projectFinance(p.priceUzs.toString(), exp.toString());
        return {
          project: { id: p.id, name: p.name, number: formatNumber('P', p.number) },
          client: p.client,
          status: p.status,
          revenueUzs: money(p.priceUzs),
          collectedUzs: money(collected.get(p.dealId) ?? ZERO),
          expensesUzs: money(exp),
          grossProfitUzs: f.grossProfit,
          marginPct: f.marginPct,
          overheadUzs: money(overhead.get(p.id) ?? ZERO),
          netProfitUzs: money(new Prisma.Decimal(f.grossProfit).sub(overhead.get(p.id) ?? ZERO)),
        };
      }),
      total,
      page: q.page,
      pageSize: q.pageSize,
    };
  }
}

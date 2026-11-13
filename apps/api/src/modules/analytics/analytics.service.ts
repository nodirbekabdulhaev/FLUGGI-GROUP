import { Injectable } from '@nestjs/common';
import {
  resolvePeriodQuery,
  type analyticsQuerySchema,
  type BreakdownRowDto,
  type ForecastDto,
  type ForecastMonthDto,
  type FunnelStepDto,
  type LossReasonRowDto,
  type SalesAnalyticsDto,
} from '@fluggi/contracts';
import {
  bucketKey,
  bucketKeys,
  companyDate,
  conversion,
  granularityFor,
  monthRange,
} from '@fluggi/domain';
import { Prisma } from '@fluggi/db';
import type { z } from 'zod';
import type { AuthContext } from '../../core/auth/auth-context';
import { PrismaService } from '../../core/prisma/prisma.service';
import { CrmAccessService } from '../crm/crm-access.service';

type Query = z.output<typeof analyticsQuerySchema>;

const ZERO = new Prisma.Decimal(0);
const s = (d: Prisma.Decimal) => d.toFixed(2);
const pctChange = (now: number, before: number) =>
  before > 0 ? Math.round(((now - before) / before) * 1000) / 10 : null;
/** Оплачено за вычетом возвратов. */
const net = (rows: { type: string; amountUzs: Prisma.Decimal }[]) =>
  rows.reduce(
    (acc, p) => (p.type === 'REFUND' ? acc.sub(p.amountUzs) : acc.add(p.amountUzs)),
    ZERO,
  );

/**
 * Аналитика продаж (ТЗ §39–42). Все выборки ограничены правом analytics.read:
 * CEO — вся компания, РОП — свои отделы, менеджер — свои сделки.
 */
@Injectable()
export class AnalyticsService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly crm: CrmAccessService,
  ) {}

  dealScope(auth: AuthContext, q: Pick<Query, 'teamId' | 'userId'>): Prisma.DealWhereInput {
    return {
      AND: [
        this.crm.dealWhere(auth, 'analytics.read'),
        q.teamId ? { teamId: q.teamId } : {},
        q.userId ? { ownerId: q.userId } : {},
      ],
    };
  }

  leadScope(auth: AuthContext, q: Pick<Query, 'teamId' | 'userId'>): Prisma.LeadWhereInput {
    return {
      AND: [
        this.crm.leadWhere(auth, 'analytics.read'),
        q.teamId ? { teamId: q.teamId } : {},
        q.userId ? { ownerId: q.userId } : {},
      ],
    };
  }

  private async totals(auth: AuthContext, q: Query, from: Date, to: Date) {
    const range = { gte: from, lt: to };
    const deal = this.dealScope(auth, q);
    const [contracts, payments, won, leads] = await Promise.all([
      this.prisma.contract.findMany({
        where: { status: 'SIGNED', signedAt: range, deal },
        select: { signedAt: true, amountUzs: true },
      }),
      this.prisma.payment.findMany({
        where: { status: 'PAID', paidAt: range, deal },
        select: { paidAt: true, type: true, amountUzs: true },
      }),
      this.prisma.deal.findMany({
        where: { AND: [deal, { wonAt: range }] },
        select: { wonAt: true },
      }),
      this.prisma.lead.findMany({
        where: { AND: [this.leadScope(auth, q), { createdAt: range }] },
        select: { createdAt: true },
      }),
    ]);
    return { contracts, payments, won, leads };
  }

  /** Динамика продаж по дням / неделям / месяцам и сравнение с прошлым периодом. */
  async sales(auth: AuthContext, q: Query): Promise<SalesAnalyticsDto> {
    const { from, to } = resolvePeriodQuery(q);
    const g = granularityFor(from, to);
    const prevFrom = new Date(from.getTime() - (to.getTime() - from.getTime()));
    const [cur, prev] = await Promise.all([
      this.totals(auth, q, from, to),
      this.totals(auth, q, prevFrom, from),
    ]);
    const points = new Map(
      bucketKeys(from, to, g).map((key) => [
        key,
        { key, revenue: ZERO, collected: ZERO, won: 0, leads: 0 },
      ]),
    );
    const at = (d: Date | null) => (d ? points.get(bucketKey(d, g)) : undefined);
    for (const c of cur.contracts) {
      const p = at(c.signedAt);
      if (p) p.revenue = p.revenue.add(c.amountUzs);
    }
    for (const pay of cur.payments) {
      const p = at(pay.paidAt);
      if (p)
        p.collected =
          pay.type === 'REFUND' ? p.collected.sub(pay.amountUzs) : p.collected.add(pay.amountUzs);
    }
    for (const d of cur.won) {
      const p = at(d.wonAt);
      if (p) p.won += 1;
    }
    for (const l of cur.leads) {
      const p = at(l.createdAt);
      if (p) p.leads += 1;
    }
    const revenue = cur.contracts.reduce((a, c) => a.add(c.amountUzs), ZERO);
    const collected = net(cur.payments);
    const prevRevenue = prev.contracts.reduce((a, c) => a.add(c.amountUzs), ZERO);
    return {
      granularity: g,
      points: [...points.values()].map((p) => ({
        key: p.key,
        revenue: s(p.revenue),
        collected: s(p.collected),
        won: p.won,
        leads: p.leads,
      })),
      totals: {
        revenue: s(revenue),
        collected: s(collected),
        won: cur.won.length,
        leads: cur.leads.length,
        avgCheck: s(cur.contracts.length ? revenue.div(cur.contracts.length) : ZERO),
      },
      change: {
        revenue: pctChange(Number(revenue), Number(prevRevenue)),
        collected: pctChange(Number(collected), Number(net(prev.payments))),
        won: pctChange(cur.won.length, prev.won.length),
        leads: pctChange(cur.leads.length, prev.leads.length),
      },
    };
  }

  /** Воронка (ТЗ §39): лиды, созданные в периоде, и как далеко они дошли. */
  async funnel(auth: AuthContext, q: Query): Promise<FunnelStepDto[]> {
    const { from, to } = resolvePeriodQuery(q);
    const base = { AND: [this.leadScope(auth, q), { createdAt: { gte: from, lt: to } }] };
    const count = (extra: Prisma.LeadWhereInput) =>
      this.prisma.lead.count({ where: { AND: [base, extra] } });
    const [leads, qualified, meetings, proposals, contracts, paid] = await Promise.all([
      count({}),
      count({ dealId: { not: null } }),
      count({
        OR: [
          { meetings: { some: { status: 'DONE' } } },
          { deal: { meetings: { some: { status: 'DONE' } } } },
        ],
      }),
      count({ deal: { proposals: { some: { sentAt: { not: null } } } } }),
      count({ deal: { contracts: { some: { status: 'SIGNED' } } } }),
      count({ deal: { payments: { some: { status: 'PAID', type: { not: 'REFUND' } } } } }),
    ]);
    const steps: [FunnelStepDto['key'], string, number][] = [
      ['leads', 'Лиды', leads],
      ['qualified', 'Квалифицированы', qualified],
      ['meetings', 'Встреча проведена', meetings],
      ['proposals', 'КП отправлено', proposals],
      ['contracts', 'Договор подписан', contracts],
      ['paid', 'Оплачено', paid],
    ];
    return steps.map(([key, label, n], i) => ({
      key,
      label,
      count: n,
      fromPrev: i === 0 ? 100 : conversion(n, steps[i - 1]![2]),
      fromStart: conversion(n, leads),
    }));
  }

  /** Лиды периода по источникам (ТЗ §42) или по услугам. */
  async breakdown(
    auth: AuthContext,
    q: Query,
    by: 'source' | 'service',
  ): Promise<BreakdownRowDto[]> {
    const { from, to } = resolvePeriodQuery(q);
    const leads = await this.prisma.lead.findMany({
      where: { AND: [this.leadScope(auth, q), { createdAt: { gte: from, lt: to } }] },
      select: {
        source: { select: { id: true, nameRu: true } },
        service: { select: { id: true, nameRu: true } },
        deal: {
          select: {
            status: true,
            payments: { where: { status: 'PAID' }, select: { type: true, amountUzs: true } },
          },
        },
      },
    });
    const rows = new Map<
      string,
      {
        id: string | null;
        name: string;
        leads: number;
        deals: number;
        won: number;
        revenue: Prisma.Decimal;
      }
    >();
    for (const l of leads) {
      const ref = by === 'source' ? l.source : l.service;
      const key = ref?.id ?? 'none';
      const r = rows.get(key) ?? {
        id: ref?.id ?? null,
        name: ref?.nameRu ?? 'Не указано',
        leads: 0,
        deals: 0,
        won: 0,
        revenue: ZERO,
      };
      r.leads += 1;
      if (l.deal) {
        r.deals += 1;
        const paid = net(l.deal.payments);
        if (paid.gt(0)) {
          r.won += 1;
          r.revenue = r.revenue.add(paid);
        }
      }
      rows.set(key, r);
    }
    return [...rows.values()]
      .map((r) => ({
        id: r.id,
        name: r.name,
        leads: r.leads,
        deals: r.deals,
        won: r.won,
        conversion: conversion(r.won, r.leads),
        revenue: s(r.revenue),
        avgCheck: s(r.won ? r.revenue.div(r.won) : ZERO),
      }))
      .sort((a, b) => Number(b.revenue) - Number(a.revenue) || b.leads - a.leads);
  }

  /** Причины потерь (ТЗ §39): лиды и сделки, закрытые в периоде. */
  async losses(auth: AuthContext, q: Query): Promise<LossReasonRowDto[]> {
    const { from, to } = resolvePeriodQuery(q);
    const closed = { closedAt: { gte: from, lt: to } };
    const [leads, deals, reasons] = await Promise.all([
      this.prisma.lead.groupBy({
        by: ['lossReasonId'],
        where: { AND: [this.leadScope(auth, q), closed, { status: { in: ['LOST', 'REJECTED'] } }] },
        _count: true,
      }),
      this.prisma.deal.groupBy({
        by: ['lossReasonId'],
        where: { AND: [this.dealScope(auth, q), closed, { status: { in: ['LOST', 'REJECTED'] } }] },
        _count: true,
        _sum: { amountUzs: true },
      }),
      this.prisma.lossReason.findMany({ select: { id: true, nameRu: true } }),
    ]);
    const name = (id: string | null) => reasons.find((r) => r.id === id)?.nameRu ?? 'Не указано';
    const rows = new Map<string, LossReasonRowDto>();
    const get = (id: string | null) => {
      const key = id ?? 'none';
      const r = rows.get(key) ?? { id, name: name(id), leads: 0, deals: 0, amount: '0.00' };
      rows.set(key, r);
      return r;
    };
    for (const l of leads) get(l.lossReasonId).leads = l._count;
    for (const d of deals) {
      const r = get(d.lossReasonId);
      r.deals = d._count;
      r.amount = s(d._sum.amountUzs ?? ZERO);
    }
    return [...rows.values()].sort((a, b) => b.leads + b.deals - (a.leads + a.deals));
  }

  /** План месяца по целям «Выручка» менеджеров в области видимости (USD — по последнему курсу). */
  private async plan(auth: AuthContext, q: Query, month: string): Promise<Prisma.Decimal | null> {
    const scope = auth.permissions['analytics.read'];
    const user: Prisma.UserWhereInput = q.userId
      ? { id: q.userId }
      : scope === 'ALL'
        ? q.teamId
          ? { teamId: q.teamId }
          : {}
        : scope === 'TEAM'
          ? { teamId: { in: q.teamId ? [q.teamId] : auth.headedTeamIds.concat(auth.teamId ?? []) } }
          : { id: auth.userId };
    const [targets, usd] = await Promise.all([
      this.prisma.kpiTarget.findMany({
        where: { period: month, metric: 'REVENUE', user: { ...user, role: { code: 'MANAGER' } } },
      }),
      this.prisma.exchangeRate.findFirst({ where: { currency: 'USD' }, orderBy: { date: 'desc' } }),
    ]);
    if (!targets.length) return null;
    const rate = usd?.rateToUzs ?? ZERO;
    return targets.reduce(
      (acc, t) => acc.add(t.currency === 'USD' ? t.targetValue.mul(rate) : t.targetValue),
      ZERO,
    );
  }

  /**
   * Прогноз (ТЗ §40) на текущий и 2 следующих месяца:
   * получено + ожидаемые оплаты по графику + открытые сделки × вероятность.
   * Сделки, по которым уже стоят ожидаемые оплаты, во взвешенную воронку не входят — без двойного счёта.
   */
  async forecast(auth: AuthContext, q: Query, now = new Date()): Promise<ForecastDto> {
    const deal = this.dealScope(auth, q);
    const first = companyDate(now).slice(0, 7);
    const months = [0, 1, 2].map((i) => {
      const [y, m] = first.split('-').map(Number) as [number, number];
      const d = new Date(Date.UTC(y, m - 1 + i, 1));
      return d.toISOString().slice(0, 7);
    });
    const ranges = months.map((m) => monthRange(m));
    const end = ranges[2]!.to;
    const [paid, pending, open] = await Promise.all([
      this.prisma.payment.findMany({
        where: { status: 'PAID', paidAt: { gte: ranges[0]!.from, lt: end }, deal },
        select: { paidAt: true, type: true, amountUzs: true },
      }),
      this.prisma.payment.findMany({
        where: { status: 'PENDING', type: { not: 'REFUND' }, deal },
        select: { dueDate: true, amountUzs: true },
      }),
      this.prisma.deal.findMany({
        where: { AND: [deal, { status: 'OPEN' }] },
        select: {
          amountUzs: true,
          expectedCloseDate: true,
          probabilityOverride: true,
          stage: { select: { probability: true } },
          payments: { where: { status: 'PENDING' }, select: { id: true } },
        },
      }),
    ]);
    const monthOf = (d: Date) => companyDate(d).slice(0, 7);
    // Дата без времени (dueDate, expectedCloseDate) хранится как полночь UTC
    const monthOfDate = (d: Date | null) => {
      if (!d) return first;
      const m = d.toISOString().slice(0, 7);
      return m < first ? first : m;
    };
    const result: ForecastMonthDto[] = [];
    for (const month of months) {
      const collected = net(paid.filter((p) => p.paidAt && monthOf(p.paidAt) === month));
      const scheduled = pending
        .filter((p) => monthOfDate(p.dueDate) === month)
        .reduce((a, p) => a.add(p.amountUzs), ZERO);
      const weighted = open
        .filter((d) => d.payments.length === 0 && monthOfDate(d.expectedCloseDate) === month)
        .reduce(
          (a, d) => a.add(d.amountUzs.mul(d.probabilityOverride ?? d.stage.probability).div(100)),
          ZERO,
        );
      const plan = await this.plan(auth, q, month);
      result.push({
        month,
        collected: s(collected),
        scheduled: s(scheduled),
        weighted: s(weighted),
        total: s(collected.add(scheduled).add(weighted)),
        plan: plan ? s(plan) : null,
      });
    }
    return {
      months: result,
      pipeline: s(open.reduce((a, d) => a.add(d.amountUzs), ZERO)),
      openDeals: open.length,
    };
  }
}

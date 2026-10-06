import { Injectable } from '@nestjs/common';
import { calcCommission, pickRule, type Condition, type Metrics } from '@fluggi/domain';
import type { CommissionRoleTarget, Deal, Payment, Prisma } from '@fluggi/db';
import type { Tx } from '../../core/prisma/prisma.service';

const TZ_OFFSET_MS = 5 * 3600_000;

/** YYYY-MM по ташкентскому времени. */
export function periodOf(d: Date): string {
  return new Date(d.getTime() + TZ_OFFSET_MS).toISOString().slice(0, 7);
}

function periodRange(period: string): { from: Date; to: Date } {
  const [y, m] = period.split('-').map(Number) as [number, number];
  return {
    from: new Date(Date.UTC(y, m - 1, 1) - TZ_OFFSET_MS),
    to: new Date(Date.UTC(y, m, 1) - TZ_OFFSET_MS),
  };
}

/**
 * Начисление комиссий с подтверждённой оплаты (ТЗ §33–34, Rule 7).
 * Получатели: менеджер сделки и РОП её отдела. Правило выбирается по метрикам получателя
 * за месяц оплаты (заказы, выручка, средний чек), условия — из БД.
 */
@Injectable()
export class CommissionEngine {
  /** Метрики за месяц: менеджер — его сделки, РОП — сделки отдела. */
  async metrics(
    tx: Tx,
    role: CommissionRoleTarget,
    userId: string,
    teamId: string | null,
    period: string,
    usdRate: number,
  ): Promise<Metrics> {
    const { from, to } = periodRange(period);
    const dealFilter: Prisma.DealWhereInput =
      role === 'MANAGER' ? { ownerId: userId } : { teamId: teamId ?? '__none__' };
    const [orders, revenue] = await Promise.all([
      tx.deal.count({ where: { ...dealFilter, wonAt: { gte: from, lt: to } } }),
      tx.payment.findMany({
        where: { status: 'PAID', paidAt: { gte: from, lt: to }, deal: dealFilter },
        select: { amountUzs: true, type: true },
      }),
    ]);
    const revenueUzs = revenue.reduce(
      (s, p) => s + Number(p.amountUzs) * (p.type === 'REFUND' ? -1 : 1),
      0,
    );
    const avgUzs = orders > 0 ? revenueUzs / orders : 0;
    return {
      orders_count: orders,
      revenue_uzs: revenueUzs,
      revenue_usd: usdRate ? revenueUzs / usdRate : 0,
      avg_check_uzs: avgUzs,
      avg_check_usd: usdRate ? avgUzs / usdRate : 0,
    };
  }

  /**
   * Создаёт комиссии по платежу. Для возврата (REFUND) сторнирует комиссии исходного платежа
   * тем же правилом и ставкой (отрицательная сумма).
   */
  async accrue(tx: Tx, payment: Payment, deal: Deal, firstPaymentOfDeal: boolean) {
    const signedUzs = payment.type === 'REFUND' ? payment.amountUzs.neg() : payment.amountUzs;
    const period = periodOf(payment.paidAt ?? new Date());

    if (payment.type === 'REFUND' && payment.refundOfId) {
      const original = await tx.commission.findMany({
        where: { paymentId: payment.refundOfId },
        include: { rule: true },
      });
      const created = [];
      for (const c of original) {
        const calc = calcCommission(
          { calcType: c.rule.calcType, value: c.rate.toString() },
          signedUzs.toString(),
          { firstPaymentOfDeal: false, marginPct: null },
        );
        created.push(
          await tx.commission.create({
            data: {
              userId: c.userId,
              paymentId: payment.id,
              dealId: deal.id,
              ruleId: c.ruleId,
              role: c.role,
              period,
              baseAmountUzs: calc.base.toFixed(2),
              rate: calc.rate.toString(),
              amountUzs: calc.amount.toFixed(2),
              calcSnapshot: {
                refundOf: payment.refundOfId,
                originalCommission: c.id,
              } as Prisma.InputJsonValue,
            },
          }),
        );
      }
      return created;
    }

    const team = deal.teamId ? await tx.team.findUnique({ where: { id: deal.teamId } }) : null;
    const recipients: { role: CommissionRoleTarget; userId: string }[] = [
      { role: 'MANAGER', userId: deal.ownerId },
    ];
    if (team?.headId) recipients.push({ role: 'ROP', userId: team.headId });

    const usd = await tx.exchangeRate.findFirst({
      where: { currency: 'USD' },
      orderBy: { date: 'desc' },
    });
    const usdRate = usd ? Number(usd.rateToUzs) : 0;
    const created = [];
    for (const r of recipients) {
      const rules = await tx.commissionRule.findMany({
        where: { appliesTo: r.role, isActive: true },
      });
      const metrics = await this.metrics(tx, r.role, r.userId, deal.teamId, period, usdRate);
      const rule = pickRule(
        rules.map((x) => ({
          ...x,
          value: x.value.toString(),
          conditions: (x.conditions as Condition | null) ?? null,
        })),
        metrics,
        r.userId,
      );
      if (!rule) continue;
      const calc = calcCommission(rule, signedUzs.toString(), {
        firstPaymentOfDeal,
        marginPct: null,
      });
      if (calc.amount.isZero()) continue;
      created.push(
        await tx.commission.create({
          data: {
            userId: r.userId,
            paymentId: payment.id,
            dealId: deal.id,
            ruleId: rule.id,
            role: r.role,
            period,
            baseAmountUzs: calc.base.toFixed(2),
            rate: calc.rate.toString(),
            amountUzs: calc.amount.toFixed(2),
            calcSnapshot: {
              metrics,
              rule: rule.name,
              conditions: rule.conditions,
            } as unknown as Prisma.InputJsonValue,
          },
        }),
      );
    }
    return created;
  }
}

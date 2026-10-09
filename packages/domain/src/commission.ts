import Decimal from 'decimal.js';

/**
 * Движок комиссий (ТЗ §33–34). Правила — данные из БД; условие — ограниченный DSL:
 *   { "all" | "any": [ { "metric": "avg_check_usd", "op": ">", "value": 3000 }, … ] }
 * Вложенность групп допускается. Неизвестная метрика = 0.
 */
export const COMMISSION_METRICS = [
  'avg_check_usd',
  'avg_check_uzs',
  'orders_count',
  'revenue_uzs',
  'revenue_usd',
] as const;
export type CommissionMetric = (typeof COMMISSION_METRICS)[number];
export type ConditionOp = '>' | '>=' | '<' | '<=' | '=';

export interface ConditionLeaf {
  metric: CommissionMetric;
  op: ConditionOp;
  value: number;
}
export interface ConditionGroup {
  all?: Condition[];
  any?: Condition[];
}
export type Condition = ConditionLeaf | ConditionGroup;

export type Metrics = Partial<Record<CommissionMetric, number>>;

export function evaluateCondition(cond: Condition | null | undefined, metrics: Metrics): boolean {
  if (!cond) return true;
  if ('metric' in cond) {
    const v = metrics[cond.metric] ?? 0;
    switch (cond.op) {
      case '>':
        return v > cond.value;
      case '>=':
        return v >= cond.value;
      case '<':
        return v < cond.value;
      case '<=':
        return v <= cond.value;
      case '=':
        return v === cond.value;
      default:
        return false;
    }
  }
  if (cond.all) return cond.all.every((c) => evaluateCondition(c, metrics));
  if (cond.any) return cond.any.some((c) => evaluateCondition(c, metrics));
  return true;
}

export type CalcType = 'PERCENT_OF_PAYMENT' | 'PERCENT_OF_PROFIT' | 'FIXED_PER_DEAL';

export interface RuleInput {
  id: string;
  calcType: CalcType;
  value: string | number;
  conditions: Condition | null;
  priority: number;
  /** Правило персонально для сотрудника — приоритетнее общего при равном priority. */
  userId: string | null;
}

/** Выбор правила: подходящее по условиям с наибольшим приоритетом (персональное — раньше общего). */
export function pickRule<T extends RuleInput>(
  rules: T[],
  metrics: Metrics,
  userId: string,
): T | null {
  const applicable = rules
    .filter(
      (r) => (r.userId === null || r.userId === userId) && evaluateCondition(r.conditions, metrics),
    )
    .sort(
      (a, b) => b.priority - a.priority || Number(b.userId !== null) - Number(a.userId !== null),
    );
  return applicable[0] ?? null;
}

export interface CommissionCalc {
  base: Decimal;
  rate: Decimal;
  amount: Decimal;
}

/**
 * Сумма комиссии с одного платежа.
 * PERCENT_OF_PAYMENT — % от суммы платежа; PERCENT_OF_PROFIT — % от доли прибыли в платеже
 * (payment × маржа проекта); FIXED_PER_DEAL — фиксированная сумма, только с первого платежа сделки.
 * Возврат (отрицательная сумма) даёт отрицательную комиссию — сторно.
 */
export function calcCommission(
  rule: Pick<RuleInput, 'calcType' | 'value'>,
  paymentUzs: string | number,
  opts: { firstPaymentOfDeal: boolean; marginPct?: number | null },
): CommissionCalc {
  const payment = new Decimal(paymentUzs);
  const value = new Decimal(rule.value);
  switch (rule.calcType) {
    case 'PERCENT_OF_PAYMENT':
      return { base: payment, rate: value, amount: payment.mul(value).div(100).toDecimalPlaces(2) };
    case 'PERCENT_OF_PROFIT': {
      const margin = new Decimal(opts.marginPct ?? 0);
      const base = payment.mul(margin).div(100).toDecimalPlaces(2);
      return { base, rate: value, amount: base.mul(value).div(100).toDecimalPlaces(2) };
    }
    case 'FIXED_PER_DEAL': {
      const sign = payment.isNegative() ? -1 : 1;
      const amount =
        opts.firstPaymentOfDeal || payment.isNegative() ? value.mul(sign) : new Decimal(0);
      return { base: payment, rate: value, amount };
    }
  }
}

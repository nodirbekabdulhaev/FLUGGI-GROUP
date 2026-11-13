import Decimal from 'decimal.js';
/**
 * Движок комиссий (ТЗ §33–34). Правила — данные из БД; условие — ограниченный DSL:
 *   { "all" | "any": [ { "metric": "avg_check_usd", "op": ">", "value": 3000 }, … ] }
 * Вложенность групп допускается. Неизвестная метрика = 0.
 */
export declare const COMMISSION_METRICS: readonly ["avg_check_usd", "avg_check_uzs", "orders_count", "revenue_uzs", "revenue_usd"];
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
export declare function evaluateCondition(cond: Condition | null | undefined, metrics: Metrics): boolean;
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
export declare function pickRule<T extends RuleInput>(rules: T[], metrics: Metrics, userId: string): T | null;
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
export declare function calcCommission(rule: Pick<RuleInput, 'calcType' | 'value'>, paymentUzs: string | number, opts: {
    firstPaymentOfDeal: boolean;
    marginPct?: number | null;
}): CommissionCalc;

"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.COMMISSION_METRICS = void 0;
exports.evaluateCondition = evaluateCondition;
exports.pickRule = pickRule;
exports.calcCommission = calcCommission;
const decimal_js_1 = __importDefault(require("decimal.js"));
/**
 * Движок комиссий (ТЗ §33–34). Правила — данные из БД; условие — ограниченный DSL:
 *   { "all" | "any": [ { "metric": "avg_check_usd", "op": ">", "value": 3000 }, … ] }
 * Вложенность групп допускается. Неизвестная метрика = 0.
 */
exports.COMMISSION_METRICS = [
    'avg_check_usd',
    'avg_check_uzs',
    'orders_count',
    'revenue_uzs',
    'revenue_usd',
];
function evaluateCondition(cond, metrics) {
    if (!cond)
        return true;
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
    if (cond.all)
        return cond.all.every((c) => evaluateCondition(c, metrics));
    if (cond.any)
        return cond.any.some((c) => evaluateCondition(c, metrics));
    return true;
}
/** Выбор правила: подходящее по условиям с наибольшим приоритетом (персональное — раньше общего). */
function pickRule(rules, metrics, userId) {
    const applicable = rules
        .filter((r) => (r.userId === null || r.userId === userId) && evaluateCondition(r.conditions, metrics))
        .sort((a, b) => b.priority - a.priority || Number(b.userId !== null) - Number(a.userId !== null));
    return applicable[0] ?? null;
}
/**
 * Сумма комиссии с одного платежа.
 * PERCENT_OF_PAYMENT — % от суммы платежа; PERCENT_OF_PROFIT — % от доли прибыли в платеже
 * (payment × маржа проекта); FIXED_PER_DEAL — фиксированная сумма, только с первого платежа сделки.
 * Возврат (отрицательная сумма) даёт отрицательную комиссию — сторно.
 */
function calcCommission(rule, paymentUzs, opts) {
    const payment = new decimal_js_1.default(paymentUzs);
    const value = new decimal_js_1.default(rule.value);
    switch (rule.calcType) {
        case 'PERCENT_OF_PAYMENT':
            return { base: payment, rate: value, amount: payment.mul(value).div(100).toDecimalPlaces(2) };
        case 'PERCENT_OF_PROFIT': {
            const margin = new decimal_js_1.default(opts.marginPct ?? 0);
            const base = payment.mul(margin).div(100).toDecimalPlaces(2);
            return { base, rate: value, amount: base.mul(value).div(100).toDecimalPlaces(2) };
        }
        case 'FIXED_PER_DEAL': {
            const sign = payment.isNegative() ? -1 : 1;
            const amount = opts.firstPaymentOfDeal || payment.isNegative() ? value.mul(sign) : new decimal_js_1.default(0);
            return { base: payment, rate: value, amount };
        }
    }
}
//# sourceMappingURL=commission.js.map
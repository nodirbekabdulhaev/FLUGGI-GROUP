"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.marginPct = marginPct;
exports.projectFinance = projectFinance;
exports.companyFinance = companyFinance;
exports.overheadShare = overheadShare;
const decimal_js_1 = __importDefault(require("decimal.js"));
/**
 * Финансовые формулы (ТЗ §25, §27; docs/BUSINESS_RULES.md §4).
 * Все суммы — UZS, строки-decimal на входе и выходе, чтобы не терять копейки.
 */
const d = (v) => new decimal_js_1.default(v ?? 0);
/** Маржинальность %, 2 знака; null — выручки нет (делить не на что). */
function marginPct(profit, revenue) {
    const r = d(revenue);
    if (r.lte(0))
        return null;
    return d(profit).div(r).mul(100).toDecimalPlaces(2).toFixed(2);
}
/** Финансы проекта: валовая прибыль = выручка − расходы, маржа = прибыль / выручка. */
function projectFinance(revenueUzs, expensesUzs) {
    const profit = d(revenueUzs).minus(d(expensesUzs));
    return { grossProfit: profit.toFixed(2), marginPct: marginPct(profit.toString(), revenueUzs) };
}
/**
 * Финансы компании за период.
 *  Gross Profit     = Collected − Refunds − проектные расходы
 *  Operating Profit = Gross Profit − расходы компании − комиссии + прочие поступления
 *  Margin %         = Gross Profit / (Collected − Refunds)
 */
function companyFinance(x) {
    const net = d(x.collected).minus(d(x.refunds));
    const gross = net.minus(d(x.projectExpenses));
    const operating = gross
        .minus(d(x.companyExpenses))
        .minus(d(x.commissions))
        .plus(d(x.otherIncome ?? 0));
    return {
        grossProfit: gross.toFixed(2),
        operatingProfit: operating.toFixed(2),
        marginPct: marginPct(gross.toString(), net.toString()),
    };
}
/**
 * Доля накладных (аренда, офис) на один проект за месяц:
 * накладные месяца ÷ (заданный делитель или число проектов, бывших в работе в этом месяце).
 */
function overheadShare(monthOverheadUzs, activeProjects, divisor) {
    const n = divisor && divisor > 0 ? divisor : Math.max(1, activeProjects);
    return d(monthOverheadUzs).div(n).toFixed(2);
}
//# sourceMappingURL=finance.js.map
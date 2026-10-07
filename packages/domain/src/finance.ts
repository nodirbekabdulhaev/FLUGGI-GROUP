import Decimal from 'decimal.js';

/**
 * Финансовые формулы (ТЗ §25, §27; docs/BUSINESS_RULES.md §4).
 * Все суммы — UZS, строки-decimal на входе и выходе, чтобы не терять копейки.
 */

const d = (v: string | number | null | undefined) => new Decimal(v ?? 0);

/** Маржинальность %, 2 знака; null — выручки нет (делить не на что). */
export function marginPct(profit: string | number, revenue: string | number): string | null {
  const r = d(revenue);
  if (r.lte(0)) return null;
  return d(profit).div(r).mul(100).toDecimalPlaces(2).toFixed(2);
}

/** Финансы проекта: валовая прибыль = выручка − расходы, маржа = прибыль / выручка. */
export function projectFinance(revenueUzs: string | number, expensesUzs: string | number) {
  const profit = d(revenueUzs).minus(d(expensesUzs));
  return { grossProfit: profit.toFixed(2), marginPct: marginPct(profit.toString(), revenueUzs) };
}

/**
 * Финансы компании за период.
 *  Gross Profit     = Collected − Refunds − проектные расходы
 *  Operating Profit = Gross Profit − расходы компании − комиссии
 *  Margin %         = Gross Profit / (Collected − Refunds)
 */
export function companyFinance(x: {
  collected: string | number;
  refunds: string | number;
  projectExpenses: string | number;
  companyExpenses: string | number;
  commissions: string | number;
}) {
  const net = d(x.collected).minus(d(x.refunds));
  const gross = net.minus(d(x.projectExpenses));
  const operating = gross.minus(d(x.companyExpenses)).minus(d(x.commissions));
  return {
    grossProfit: gross.toFixed(2),
    operatingProfit: operating.toFixed(2),
    marginPct: marginPct(gross.toString(), net.toString()),
  };
}

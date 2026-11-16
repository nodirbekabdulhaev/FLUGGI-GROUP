/** Маржинальность %, 2 знака; null — выручки нет (делить не на что). */
export declare function marginPct(profit: string | number, revenue: string | number): string | null;
/** Финансы проекта: валовая прибыль = выручка − расходы, маржа = прибыль / выручка. */
export declare function projectFinance(revenueUzs: string | number, expensesUzs: string | number): {
    grossProfit: string;
    marginPct: string | null;
};
/**
 * Финансы компании за период.
 *  Gross Profit     = Collected − Refunds − проектные расходы
 *  Operating Profit = Gross Profit − расходы компании − комиссии + прочие поступления
 *  Margin %         = Gross Profit / (Collected − Refunds)
 */
export declare function companyFinance(x: {
    collected: string | number;
    refunds: string | number;
    projectExpenses: string | number;
    companyExpenses: string | number;
    commissions: string | number;
    /** Прочие поступления (не от клиентов) */
    otherIncome?: string | number;
}): {
    grossProfit: string;
    operatingProfit: string;
    marginPct: string | null;
};
/**
 * Доля накладных (аренда, офис) на один проект за месяц:
 * накладные месяца ÷ (заданный делитель или число проектов, бывших в работе в этом месяце).
 */
export declare function overheadShare(monthOverheadUzs: string | number, activeProjects: number, divisor?: number | null): string;

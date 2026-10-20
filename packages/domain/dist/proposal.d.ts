import Decimal from 'decimal.js';
export interface ProposalLineInput {
    quantity: string | number;
    unitPrice: string | number;
    /** Скидка на строку, % (0–100). */
    discountPct?: string | number;
}
export interface ProposalTotals {
    lines: {
        gross: Decimal;
        discount: Decimal;
        total: Decimal;
    }[];
    subtotal: Decimal;
    discountAmount: Decimal;
    total: Decimal;
}
/**
 * Итоги КП (ТЗ §15): по строке gross = кол-во × цена, скидка = gross × % / 100,
 * итог = gross − скидка. Округление до 2 знаков на каждой строке.
 */
export declare function proposalTotals(lines: ProposalLineInput[]): ProposalTotals;

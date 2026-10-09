import Decimal from 'decimal.js';

export interface ProposalLineInput {
  quantity: string | number;
  unitPrice: string | number;
  /** Скидка на строку, % (0–100). */
  discountPct?: string | number;
}

export interface ProposalTotals {
  lines: { gross: Decimal; discount: Decimal; total: Decimal }[];
  subtotal: Decimal;
  discountAmount: Decimal;
  total: Decimal;
}

/**
 * Итоги КП (ТЗ §15): по строке gross = кол-во × цена, скидка = gross × % / 100,
 * итог = gross − скидка. Округление до 2 знаков на каждой строке.
 */
export function proposalTotals(lines: ProposalLineInput[]): ProposalTotals {
  const out = lines.map((l) => {
    const gross = new Decimal(l.quantity).mul(l.unitPrice).toDecimalPlaces(2);
    const pct = Decimal.min(100, Decimal.max(0, new Decimal(l.discountPct ?? 0)));
    const discount = gross.mul(pct).div(100).toDecimalPlaces(2);
    return { gross, discount, total: gross.sub(discount) };
  });
  const subtotal = out.reduce((a, l) => a.add(l.gross), new Decimal(0));
  const discountAmount = out.reduce((a, l) => a.add(l.discount), new Decimal(0));
  return { lines: out, subtotal, discountAmount, total: subtotal.sub(discountAmount) };
}

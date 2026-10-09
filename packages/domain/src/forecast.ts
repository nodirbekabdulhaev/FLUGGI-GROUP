import Decimal from 'decimal.js';

export interface PipelineDeal {
  amountUzs: string | number;
  probability: number;
}

/** Pipeline и взвешенный прогноз (ТЗ §40): Σ amount × probability. */
export function forecast(deals: PipelineDeal[]): { pipeline: Decimal; weighted: Decimal } {
  let pipeline = new Decimal(0);
  let weighted = new Decimal(0);
  for (const d of deals) {
    const amount = new Decimal(d.amountUzs);
    pipeline = pipeline.add(amount);
    weighted = weighted.add(amount.mul(Math.min(100, Math.max(0, d.probability))).div(100));
  }
  return { pipeline: pipeline.toDecimalPlaces(2), weighted: weighted.toDecimalPlaces(2) };
}

/** Конверсия, % (0 при пустом знаменателе). */
export function conversion(converted: number, total: number): number {
  return total > 0 ? Math.round((converted / total) * 10000) / 100 : 0;
}

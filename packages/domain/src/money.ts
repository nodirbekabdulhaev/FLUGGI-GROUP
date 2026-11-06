import Decimal from 'decimal.js';

export type CurrencyCode = 'UZS' | 'USD';
export type MoneyInput = string | number | Decimal;

/** Конвертация в UZS по зафиксированному курсу. Округление до 2 знаков (банковское). */
export function toUzs(amount: MoneyInput, currency: CurrencyCode, rateToUzs: MoneyInput): Decimal {
  const value = new Decimal(amount);
  if (currency === 'UZS') return value.toDecimalPlaces(2, Decimal.ROUND_HALF_EVEN);
  return value.mul(rateToUzs).toDecimalPlaces(2, Decimal.ROUND_HALF_EVEN);
}

export function sum(values: MoneyInput[]): Decimal {
  return values.reduce<Decimal>((acc, v) => acc.add(v), new Decimal(0));
}

/** Процент a от b; при b = 0 возвращает 0. */
export function percent(part: MoneyInput, whole: MoneyInput, dp = 2): number {
  const w = new Decimal(whole);
  if (w.isZero()) return 0;
  return new Decimal(part).div(w).mul(100).toDecimalPlaces(dp).toNumber();
}

export { Decimal };

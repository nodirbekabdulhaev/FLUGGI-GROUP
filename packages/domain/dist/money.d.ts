import Decimal from 'decimal.js';
export type CurrencyCode = 'UZS' | 'USD';
export type MoneyInput = string | number | Decimal;
/** Конвертация в UZS по зафиксированному курсу. Округление до 2 знаков (банковское). */
export declare function toUzs(amount: MoneyInput, currency: CurrencyCode, rateToUzs: MoneyInput): Decimal;
export declare function sum(values: MoneyInput[]): Decimal;
/** Процент a от b; при b = 0 возвращает 0. */
export declare function percent(part: MoneyInput, whole: MoneyInput, dp?: number): number;
export { Decimal };

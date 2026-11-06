/**
 * Периоды для глобального фильтра CEO (Сегодня / Месяц / Квартал / Год / Свой).
 * Границы считаются в часовом поясе компании (Asia/Tashkent, UTC+5, без DST).
 */
export declare const PERIOD_PRESETS: readonly ["today", "week", "month", "quarter", "year", "custom"];
export type PeriodPreset = (typeof PERIOD_PRESETS)[number];
export interface DateRange {
    from: Date;
    /** Не включительно. */
    to: Date;
}
export declare function resolvePeriod(preset: Exclude<PeriodPreset, 'custom'>, now?: Date): DateRange;
/** Свой период по датам YYYY-MM-DD (обе включительно). */
export declare function resolveCustomPeriod(fromDate: string, toDate: string): DateRange | null;

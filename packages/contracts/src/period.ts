/**
 * Периоды для глобального фильтра CEO (Сегодня / Месяц / Квартал / Год / Свой).
 * Границы считаются в часовом поясе компании (Asia/Tashkent, UTC+5, без DST).
 */
export const PERIOD_PRESETS = ['today', 'month', 'quarter', 'year', 'custom'] as const;
export type PeriodPreset = (typeof PERIOD_PRESETS)[number];

export interface DateRange {
  from: Date;
  /** Не включительно. */
  to: Date;
}

const OFFSET_MS = 5 * 60 * 60 * 1000;

/** Полночь в Ташкенте для заданных компонент даты → UTC Date. */
function tashkentMidnight(year: number, monthIndex: number, day: number): Date {
  return new Date(Date.UTC(year, monthIndex, day) - OFFSET_MS);
}

export function resolvePeriod(
  preset: Exclude<PeriodPreset, 'custom'>,
  now: Date = new Date(),
): DateRange {
  const local = new Date(now.getTime() + OFFSET_MS);
  const y = local.getUTCFullYear();
  const m = local.getUTCMonth();
  const d = local.getUTCDate();
  switch (preset) {
    case 'today':
      return { from: tashkentMidnight(y, m, d), to: tashkentMidnight(y, m, d + 1) };
    case 'month':
      return { from: tashkentMidnight(y, m, 1), to: tashkentMidnight(y, m + 1, 1) };
    case 'quarter': {
      const q = Math.floor(m / 3) * 3;
      return { from: tashkentMidnight(y, q, 1), to: tashkentMidnight(y, q + 3, 1) };
    }
    case 'year':
      return { from: tashkentMidnight(y, 0, 1), to: tashkentMidnight(y + 1, 0, 1) };
  }
}

/** Свой период по датам YYYY-MM-DD (обе включительно). */
export function resolveCustomPeriod(fromDate: string, toDate: string): DateRange | null {
  const re = /^(\d{4})-(\d{2})-(\d{2})$/;
  const a = re.exec(fromDate);
  const b = re.exec(toDate);
  if (!a || !b) return null;
  const from = tashkentMidnight(Number(a[1]), Number(a[2]) - 1, Number(a[3]));
  const to = tashkentMidnight(Number(b[1]), Number(b[2]) - 1, Number(b[3]) + 1);
  return from < to ? { from, to } : null;
}

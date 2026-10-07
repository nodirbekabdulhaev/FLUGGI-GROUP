import Decimal from 'decimal.js';

/**
 * Посещаемость, KPI и зарплата (ТЗ §31, §32, §35). Время — по Ташкенту (UTC+5).
 */

const TZ_MS = 5 * 3_600_000;

/** «09:30» → минуты от полуночи. */
export function minutesOf(hhmm: string): number {
  const [h = 0, m = 0] = hhmm.split(':').map(Number);
  return h * 60 + m;
}

/** Момент → «HH:MM» по Ташкенту. */
export function tashkentTime(at: Date): string {
  return new Date(at.getTime() + TZ_MS).toISOString().slice(11, 16);
}

/** День недели ISO (1 — понедельник … 7 — воскресенье) для даты YYYY-MM-DD. */
export function isoWeekday(date: string): number {
  const d = new Date(`${date}T00:00:00Z`).getUTCDay();
  return d === 0 ? 7 : d;
}

/** Дата YYYY-MM-DD + «HH:MM» по Ташкенту → момент UTC. */
export function atTashkent(date: string, hhmm: string): Date {
  return new Date(Date.parse(`${date}T${hhmm}:00Z`) - TZ_MS);
}

/**
 * Опоздание: приход позже начала графика больше чем на «льготные» минуты.
 * Опоздание считается от начала графика (а не от конца льготного окна).
 */
export function lateMinutes(checkIn: Date, date: string, start: string, grace: number): number {
  const late = Math.floor((checkIn.getTime() - atTashkent(date, start).getTime()) / 60_000);
  return late > grace ? late : 0;
}

/** Отработано минут между приходом и уходом. */
export function workMinutes(checkIn: Date | null, checkOut: Date | null): number {
  if (!checkIn || !checkOut || checkOut <= checkIn) return 0;
  return Math.floor((checkOut.getTime() - checkIn.getTime()) / 60_000);
}

/** Выполнение цели, % (2 знака); null — цели нет или она нулевая. */
export function completionPct(
  fact: string | number,
  target: string | number | null,
): string | null {
  if (target === null) return null;
  const t = new Decimal(target);
  if (t.lte(0)) return null;
  return new Decimal(fact).div(t).mul(100).toDecimalPlaces(2).toFixed(2);
}

/** Среднее по списку процентов (null-ы пропускаются). */
export function averagePct(values: (string | null)[]): string | null {
  const v = values.filter((x): x is string => x !== null);
  if (v.length === 0) return null;
  return v
    .reduce((s, x) => s.add(x), new Decimal(0))
    .div(v.length)
    .toDecimalPlaces(2)
    .toFixed(2);
}

/** Итоговая зарплата (ТЗ §32): оклад + KPI-бонус + комиссия + прочие бонусы − штраф. */
export function finalSalary(x: {
  baseSalary: string | number;
  kpiBonus: string | number;
  commission: string | number;
  otherBonus: string | number;
  penalty: string | number;
}): string {
  return new Decimal(x.baseSalary)
    .add(x.kpiBonus)
    .add(x.commission)
    .add(x.otherBonus)
    .sub(x.penalty)
    .toFixed(2);
}

/** Предел выполнения KPI для бонуса: перевыполнение оплачивается до 120%. */
export const KPI_BONUS_CAP_PCT = 120;

/**
 * KPI-бонус за месяц: «бонус при 100%» × выполнение KPI (не больше 120%).
 * Нет целей (pct = null) или не задан бонус — 0.
 */
export function kpiBonusFor(target: string | number | null, pct: string | number | null): string {
  if (target === null || pct === null || Number(target) <= 0) return '0.00';
  const p = Math.min(Math.max(Number(pct), 0), KPI_BONUS_CAP_PCT);
  return new Decimal(target).mul(p).div(100).toDecimalPlaces(0).toFixed(2);
}

/** Границы месяца YYYY-MM по Ташкенту. */
export function monthRange(period: string): { from: Date; to: Date } {
  const [y, m] = period.split('-').map(Number) as [number, number];
  return {
    from: new Date(Date.UTC(y, m - 1, 1) - TZ_MS),
    to: new Date(Date.UTC(y, m, 1) - TZ_MS),
  };
}

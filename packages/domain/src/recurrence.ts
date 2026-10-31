import { addDays } from './tasks';

export type Frequency = 'MONTHLY' | 'QUARTERLY' | 'YEARLY';

export interface RecurrenceRule {
  frequency: Frequency;
  /** 1–28 */
  dayOfMonth: number;
  /** YEARLY: 1–12; QUARTERLY: 1–3 (месяц внутри квартала) */
  month?: number | null;
}

const pad = (n: number) => String(n).padStart(2, '0');

/** Месяцы, в которые наступает срок. */
function months(rule: RecurrenceRule): number[] {
  if (rule.frequency === 'MONTHLY') return [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12];
  if (rule.frequency === 'QUARTERLY') {
    const m = rule.month ?? 1;
    return [m, m + 3, m + 6, m + 9];
  }
  return [rule.month ?? 1];
}

/** Ближайший срок (YYYY-MM-DD) не раньше даты from. */
export function nextDue(rule: RecurrenceRule, from: string): string {
  const day = Math.min(28, Math.max(1, rule.dayOfMonth));
  const [y] = from.split('-').map(Number) as [number];
  for (const year of [y, y + 1]) {
    for (const m of months(rule)) {
      const d = `${year}-${pad(m)}-${pad(day)}`;
      if (d >= from) return d;
    }
  }
  return `${y + 2}-${pad(months(rule)[0]!)}-${pad(day)}`;
}

const MONTH_NAMES = [
  'январь',
  'февраль',
  'март',
  'апрель',
  'май',
  'июнь',
  'июль',
  'август',
  'сентябрь',
  'октябрь',
  'ноябрь',
  'декабрь',
];
const QUARTERS = ['I', 'II', 'III', 'IV'];

/**
 * Подстановка периода в название: «Налог с оборота за {прошлый_месяц}» → «… за сентябрь 2026».
 * {месяц} {прошлый_месяц} {квартал} {прошлый_квартал} {год} {прошлый_год} — относительно срока.
 */
export function fillPeriod(template: string, due: string): string {
  const [y, m] = due.split('-').map(Number) as [number, number];
  const prevMonth = addDays(`${y}-${pad(m)}-01`, -1);
  const [py, pm] = prevMonth.split('-').map(Number) as [number, number];
  const q = Math.floor((m - 1) / 3);
  const pq = q === 0 ? 3 : q - 1;
  const pqy = q === 0 ? y - 1 : y;
  return template
    .replaceAll('{прошлый_месяц}', `${MONTH_NAMES[pm - 1]} ${py}`)
    .replaceAll('{месяц}', `${MONTH_NAMES[m - 1]} ${y}`)
    .replaceAll('{прошлый_квартал}', `${QUARTERS[pq]} квартал ${pqy}`)
    .replaceAll('{квартал}', `${QUARTERS[q]} квартал ${y}`)
    .replaceAll('{прошлый_год}', `${y - 1}`)
    .replaceAll('{год}', `${y}`);
}

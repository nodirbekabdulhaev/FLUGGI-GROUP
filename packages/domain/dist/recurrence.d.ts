export type Frequency = 'MONTHLY' | 'QUARTERLY' | 'YEARLY';
export interface RecurrenceRule {
    frequency: Frequency;
    /** 1–28 */
    dayOfMonth: number;
    /** YEARLY: 1–12; QUARTERLY: 1–3 (месяц внутри квартала) */
    month?: number | null;
}
/** Ближайший срок (YYYY-MM-DD) не раньше даты from. */
export declare function nextDue(rule: RecurrenceRule, from: string): string;
/**
 * Подстановка периода в название: «Налог с оборота за {прошлый_месяц}» → «… за сентябрь 2026».
 * {месяц} {прошлый_месяц} {квартал} {прошлый_квартал} {год} {прошлый_год} — относительно срока.
 */
export declare function fillPeriod(template: string, due: string): string;

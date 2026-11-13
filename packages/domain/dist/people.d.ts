/** «09:30» → минуты от полуночи. */
export declare function minutesOf(hhmm: string): number;
/** Момент → «HH:MM» по Ташкенту. */
export declare function tashkentTime(at: Date): string;
/** День недели ISO (1 — понедельник … 7 — воскресенье) для даты YYYY-MM-DD. */
export declare function isoWeekday(date: string): number;
/** Дата YYYY-MM-DD + «HH:MM» по Ташкенту → момент UTC. */
export declare function atTashkent(date: string, hhmm: string): Date;
/**
 * Опоздание: приход позже начала графика больше чем на «льготные» минуты.
 * Опоздание считается от начала графика (а не от конца льготного окна).
 */
export declare function lateMinutes(checkIn: Date, date: string, start: string, grace: number): number;
/** Отработано минут между приходом и уходом. */
export declare function workMinutes(checkIn: Date | null, checkOut: Date | null): number;
/** Выполнение цели, % (2 знака); null — цели нет или она нулевая. */
export declare function completionPct(fact: string | number, target: string | number | null): string | null;
/** Среднее по списку процентов (null-ы пропускаются). */
export declare function averagePct(values: (string | null)[]): string | null;
/** Итоговая зарплата (ТЗ §32): оклад + сдельно + KPI-бонус + комиссия + прочие бонусы − штраф. */
export declare function finalSalary(x: {
    baseSalary: string | number;
    /** Сдельная оплата (исполнители) */
    pieceRate?: string | number;
    kpiBonus: string | number;
    commission: string | number;
    otherBonus: string | number;
    penalty: string | number;
}): string;
/** Предел выполнения KPI для бонуса: перевыполнение оплачивается до 120%. */
export declare const KPI_BONUS_CAP_PCT = 120;
/**
 * KPI-бонус за месяц: «бонус при 100%» × выполнение KPI (не больше 120%).
 * Нет целей (pct = null) или не задан бонус — 0.
 */
export declare function kpiBonusFor(target: string | number | null, pct: string | number | null): string;
/** Границы месяца YYYY-MM по Ташкенту. */
export declare function monthRange(period: string): {
    from: Date;
    to: Date;
};

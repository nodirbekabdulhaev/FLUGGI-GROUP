export type Granularity = 'day' | 'week' | 'month';
/** Шаг графика по длине периода: до 45 дней — дни, до ~6 месяцев — недели, дальше — месяцы. */
export declare function granularityFor(from: Date, to: Date): Granularity;
/** Ключ корзины (дата по Ташкенту): день — YYYY-MM-DD, неделя — понедельник, месяц — YYYY-MM. */
export declare function bucketKey(at: Date, g: Granularity): string;
/** Все корзины периода [from, to) — чтобы на графике не было «дыр». */
export declare function bucketKeys(from: Date, to: Date, g: Granularity): string[];
export type ClientHealthLevel = 'HEALTHY' | 'ATTENTION' | 'RISK' | 'LOST';
export interface ClientHealthInput {
    /** Дней с последнего контакта (активность, встреча, оплата); null — контактов не было. */
    daysSinceContact: number | null;
    /** Дней с создания клиента. */
    ageDays: number;
    overduePayments: number;
    overdueProjects: number;
    activeProjects: number;
    openDeals: number;
    /** Оплаченных сделок за всё время. */
    paidDeals: number;
    /** Проиграно сделок за последние 90 дней. */
    recentLostDeals: number;
}
export interface ClientHealthResult {
    score: number;
    level: ClientHealthLevel;
    /** Что снизило или повысило оценку — показывается пользователю. */
    reasons: string[];
}
/**
 * Оценка «здоровья» клиента 0–100 (ТЗ §43). Правила прозрачные — причины видны в карточке:
 *  − нет контакта 30 / 60 / 90 дней: −20 / −40 / −65
 *  − просроченная оплата: −25 за каждую (до −50); просроченный проект: −15
 *  − проигранная сделка за 90 дней: −10
 *  + повторные покупки (2+ оплаченные сделки): +10; проект в работе: +10
 * ≥70 — «Здоров», 40–69 — «Внимание», <40 — «Риск».
 * «Потерян» — нет контакта 180+ дней и ничего в работе.
 */
export declare function clientHealth(c: ClientHealthInput): ClientHealthResult;

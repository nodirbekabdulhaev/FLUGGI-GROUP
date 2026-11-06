/**
 * Lead scoring (ТЗ §10). Чистая функция: факторы → 0–100 и уровень.
 * Каждый фактор нормирован в 0..1, итог — взвешенное среднее по известным факторам
 * (неизвестный фактор не штрафует и не завышает оценку).
 */
export type ScoreLevel = 'LOW' | 'MEDIUM' | 'HIGH' | 'HOT';
export type CompanySizeCode = 'SOLO' | 'SMALL' | 'MEDIUM' | 'LARGE';
export type PriorityCode = 'LOW' | 'MEDIUM' | 'HIGH' | 'URGENT';
export interface LeadScoreInput {
    /** Бюджет в UZS. */
    budgetUzs?: number | null;
    /** Минимальная цена выбранной услуги в UZS (если задана в каталоге). */
    serviceMinPriceUzs?: number | null;
    hasService: boolean;
    priority: PriorityCode;
    /** Сколько дней до желаемой даты старта (отрицательное — уже прошла). */
    daysToDesiredDate?: number | null;
    companySize?: CompanySizeCode | null;
    /** 1–5. */
    interest?: number | null;
    /** Индекс этапа воронки лида: 0 — новый … 4 — встреча проведена. */
    stageIndex: number;
    stageCount: number;
}
export declare const SCORE_WEIGHTS: {
    readonly budget: 25;
    readonly urgency: 15;
    readonly serviceFit: 10;
    readonly companySize: 10;
    readonly interest: 20;
    readonly stage: 20;
};
export declare function scoreLevel(score: number): ScoreLevel;
export declare function computeLeadScore(input: LeadScoreInput): {
    score: number;
    level: ScoreLevel;
    factors: Record<string, number>;
};

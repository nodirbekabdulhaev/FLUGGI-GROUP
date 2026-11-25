import Decimal from 'decimal.js';
export interface PipelineDeal {
    amountUzs: string | number;
    probability: number;
}
/** Pipeline и взвешенный прогноз (ТЗ §40): Σ amount × probability. */
export declare function forecast(deals: PipelineDeal[]): {
    pipeline: Decimal;
    weighted: Decimal;
};
/** Конверсия, % (0 при пустом знаменателе). */
export declare function conversion(converted: number, total: number): number;

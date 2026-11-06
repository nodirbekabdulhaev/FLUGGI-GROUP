import { z } from 'zod';
export declare const analyticsQuerySchema: z.ZodObject<{
    period: z.ZodDefault<z.ZodEnum<{
        today: "today";
        week: "week";
        month: "month";
        quarter: "quarter";
        year: "year";
        custom: "custom";
    }>>;
    from: z.ZodOptional<z.ZodISODate>;
    to: z.ZodOptional<z.ZodISODate>;
    teamId: z.ZodOptional<z.ZodUUID>;
    userId: z.ZodOptional<z.ZodUUID>;
}, z.core.$strip>;
export type AnalyticsQuery = z.input<typeof analyticsQuerySchema>;
export type Granularity = 'day' | 'week' | 'month';
export interface SalesPointDto {
    /** День YYYY-MM-DD, понедельник недели или месяц YYYY-MM */
    key: string;
    /** Выручка — подписанные договоры, UZS */
    revenue: string;
    /** Оплачено за вычетом возвратов, UZS */
    collected: string;
    /** Выиграно сделок */
    won: number;
    /** Новых лидов */
    leads: number;
}
export interface SalesAnalyticsDto {
    granularity: Granularity;
    points: SalesPointDto[];
    totals: {
        revenue: string;
        collected: string;
        won: number;
        leads: number;
        avgCheck: string;
    };
    /** Тот же период до текущего — для сравнения, % изменения; null — не с чем сравнить */
    change: {
        revenue: number | null;
        collected: number | null;
        won: number | null;
        leads: number | null;
    };
}
export interface FunnelStepDto {
    key: 'leads' | 'qualified' | 'meetings' | 'proposals' | 'contracts' | 'paid';
    label: string;
    count: number;
    /** % от предыдущего шага */
    fromPrev: number;
    /** % от лидов */
    fromStart: number;
}
export interface BreakdownRowDto {
    id: string | null;
    name: string;
    leads: number;
    deals: number;
    won: number;
    /** Конверсия лидов в оплаченные сделки, % */
    conversion: number;
    revenue: string;
    avgCheck: string;
}
export interface LossReasonRowDto {
    id: string | null;
    name: string;
    leads: number;
    deals: number;
    /** Потерянная сумма по сделкам, UZS */
    amount: string;
}
export interface ForecastMonthDto {
    /** YYYY-MM */
    month: string;
    /** Уже получено */
    collected: string;
    /** Ожидаемые оплаты по графику (неоплаченные, срок в этом месяце; в текущем — и просроченные) */
    scheduled: string;
    /** Открытые сделки × вероятность (по ожидаемой дате закрытия; без даты — текущий месяц) */
    weighted: string;
    /** Итого прогноз = получено + ожидаемые оплаты + взвешенная воронка */
    total: string;
    /** План — сумма целей «Выручка» менеджеров */
    plan: string | null;
}
export interface ForecastDto {
    months: ForecastMonthDto[];
    /** Вся открытая воронка, без взвешивания */
    pipeline: string;
    openDeals: number;
}
export declare const CLIENT_HEALTH_LEVELS: readonly ["HEALTHY", "ATTENTION", "RISK", "LOST"];
export type ClientHealthLevel = (typeof CLIENT_HEALTH_LEVELS)[number];
export declare const clientAnalyticsQuerySchema: z.ZodObject<{
    sort: z.ZodDefault<z.ZodEnum<{
        ltv: "ltv";
        health: "health";
        lastPayment: "lastPayment";
    }>>;
    health: z.ZodOptional<z.ZodEnum<{
        LOST: "LOST";
        HEALTHY: "HEALTHY";
        ATTENTION: "ATTENTION";
        RISK: "RISK";
    }>>;
    page: z.ZodDefault<z.ZodCoercedNumber<unknown>>;
    pageSize: z.ZodDefault<z.ZodCoercedNumber<unknown>>;
}, z.core.$strip>;
export type ClientAnalyticsQuery = z.input<typeof clientAnalyticsQuerySchema>;
export interface ClientInsightDto {
    id: string;
    name: string;
    owner: {
        id: string;
        name: string;
    };
    /** LTV — оплачено за всё время за вычетом возвратов, UZS */
    ltv: string;
    paidDeals: number;
    avgCheck: string;
    firstPaymentAt: string | null;
    lastPaymentAt: string | null;
    lastContactAt: string | null;
    health: {
        score: number;
        level: ClientHealthLevel;
        reasons: string[];
    };
}
export interface ClientAnalyticsDto {
    items: ClientInsightDto[];
    total: number;
    page: number;
    pageSize: number;
    summary: {
        clients: number;
        avgLtv: string;
        repeatRate: number;
        byHealth: Record<ClientHealthLevel, number>;
    };
}
export declare const searchQuerySchema: z.ZodObject<{
    q: z.ZodString;
}, z.core.$strip>;
export type SearchKind = 'client' | 'lead' | 'deal' | 'project' | 'contract' | 'task' | 'user';
export interface SearchHitDto {
    kind: SearchKind;
    id: string;
    title: string;
    subtitle: string | null;
    link: string;
}
export declare const EXPORT_ENTITIES: readonly ["leads", "deals", "clients", "payments", "expenses", "projects", "tasks"];
export type ExportEntity = (typeof EXPORT_ENTITIES)[number];
export declare const exportQuerySchema: z.ZodObject<{
    format: z.ZodDefault<z.ZodEnum<{
        xlsx: "xlsx";
        csv: "csv";
    }>>;
    period: z.ZodDefault<z.ZodEnum<{
        today: "today";
        week: "week";
        month: "month";
        quarter: "quarter";
        year: "year";
        custom: "custom";
        all: "all";
    }>>;
    from: z.ZodOptional<z.ZodString>;
    to: z.ZodOptional<z.ZodString>;
}, z.core.$strip>;
export type ExportQuery = z.input<typeof exportQuerySchema>;

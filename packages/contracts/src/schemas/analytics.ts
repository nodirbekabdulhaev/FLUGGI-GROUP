import { z } from 'zod';
import { periodQuerySchema } from './finance';

// ─────────────────────────── Аналитика (ТЗ §39–43) ───────────────────────────

export const analyticsQuerySchema = periodQuerySchema.extend({
  /** Отдел (для CEO); РОП видит свои отделы, менеджер — себя. */
  teamId: z.uuid().optional(),
  /** Конкретный менеджер. */
  userId: z.uuid().optional(),
});
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
  totals: { revenue: string; collected: string; won: number; leads: number; avgCheck: string };
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

export const CLIENT_HEALTH_LEVELS = ['HEALTHY', 'ATTENTION', 'RISK', 'LOST'] as const;
export type ClientHealthLevel = (typeof CLIENT_HEALTH_LEVELS)[number];

export const clientAnalyticsQuerySchema = z.object({
  sort: z.enum(['ltv', 'health', 'lastPayment']).default('ltv'),
  health: z.enum(CLIENT_HEALTH_LEVELS).optional(),
  page: z.coerce.number().int().min(1).default(1),
  pageSize: z.coerce.number().int().min(1).max(100).default(25),
});
export type ClientAnalyticsQuery = z.input<typeof clientAnalyticsQuerySchema>;

export interface ClientInsightDto {
  id: string;
  name: string;
  owner: { id: string; name: string };
  /** LTV — оплачено за всё время за вычетом возвратов, UZS */
  ltv: string;
  paidDeals: number;
  avgCheck: string;
  firstPaymentAt: string | null;
  lastPaymentAt: string | null;
  lastContactAt: string | null;
  health: { score: number; level: ClientHealthLevel; reasons: string[] };
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

// ─────────────────────────── Поиск (ТЗ §44) ───────────────────────────

export const searchQuerySchema = z.object({ q: z.string().trim().min(2).max(100) });

export type SearchKind = 'client' | 'lead' | 'deal' | 'project' | 'contract' | 'task' | 'user';

export interface SearchHitDto {
  kind: SearchKind;
  id: string;
  title: string;
  subtitle: string | null;
  link: string;
}

// ─────────────────────────── Экспорт (ТЗ §44) ───────────────────────────

export const EXPORT_ENTITIES = [
  'leads',
  'deals',
  'clients',
  'payments',
  'expenses',
  'projects',
  'tasks',
] as const;
export type ExportEntity = (typeof EXPORT_ENTITIES)[number];

export const exportQuerySchema = z.object({
  format: z.enum(['csv', 'xlsx']).default('xlsx'),
  period: z.enum(['today', 'week', 'month', 'quarter', 'year', 'custom', 'all']).default('all'),
  from: z.string().optional(),
  to: z.string().optional(),
});
export type ExportQuery = z.input<typeof exportQuerySchema>;

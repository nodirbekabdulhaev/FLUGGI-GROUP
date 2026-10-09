'use client';

import type {
  AnalyticsQuery,
  BreakdownRowDto,
  ClientAnalyticsDto,
  ClientAnalyticsQuery,
  ClientInsightDto,
  ExportEntity,
  ForecastDto,
  FunnelStepDto,
  LossReasonRowDto,
  SalesAnalyticsDto,
  SearchHitDto,
} from '@fluggi/contracts';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api-client';

const q = (o: object) => o as Record<string, string | number | boolean | undefined>;
const opts = { placeholderData: keepPreviousData };

export const useSales = (p: AnalyticsQuery) =>
  useQuery({
    queryKey: ['analytics', 'sales', p],
    queryFn: () => api<SalesAnalyticsDto>('/analytics/sales', { query: q(p) }),
    ...opts,
  });
export const useFunnel = (p: AnalyticsQuery) =>
  useQuery({
    queryKey: ['analytics', 'funnel', p],
    queryFn: () => api<FunnelStepDto[]>('/analytics/funnel', { query: q(p) }),
    ...opts,
  });
export const useBreakdown = (by: 'sources' | 'services', p: AnalyticsQuery) =>
  useQuery({
    queryKey: ['analytics', by, p],
    queryFn: () => api<BreakdownRowDto[]>(`/analytics/${by}`, { query: q(p) }),
    ...opts,
  });
export const useLosses = (p: AnalyticsQuery) =>
  useQuery({
    queryKey: ['analytics', 'losses', p],
    queryFn: () => api<LossReasonRowDto[]>('/analytics/losses', { query: q(p) }),
    ...opts,
  });
export const useForecast = (p: Pick<AnalyticsQuery, 'teamId' | 'userId'>) =>
  useQuery({
    queryKey: ['analytics', 'forecast', p],
    queryFn: () => api<ForecastDto>('/analytics/forecast', { query: q(p) }),
    ...opts,
  });
export const useClientAnalytics = (p: ClientAnalyticsQuery) =>
  useQuery({
    queryKey: ['analytics', 'clients', p],
    queryFn: () => api<ClientAnalyticsDto>('/analytics/clients', { query: q(p) }),
    ...opts,
  });
export const useClientInsight = (id: string) =>
  useQuery({
    queryKey: ['analytics', 'client', id],
    queryFn: () => api<Omit<ClientInsightDto, 'name' | 'owner'>>(`/clients/${id}/insight`),
  });
export const useSearch = (text: string) =>
  useQuery({
    queryKey: ['search', text],
    queryFn: ({ signal }) => api<SearchHitDto[]>('/search', { query: { q: text }, signal }),
    enabled: text.trim().length >= 2,
    placeholderData: keepPreviousData,
    staleTime: 15_000,
  });

/** URL файла экспорта: браузер скачивает его сам (cookie-сессия, GET без CSRF). */
export function exportUrl(entity: ExportEntity, format: 'xlsx' | 'csv', period = 'all') {
  return `/api/v1/exports/${entity}?format=${format}&period=${period}`;
}

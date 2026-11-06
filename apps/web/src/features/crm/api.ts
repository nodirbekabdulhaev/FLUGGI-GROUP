'use client';

import type {
  ActivityDto,
  ClientDetailDto,
  ClientDto,
  ClientListQuery,
  CommentDto,
  DealDto,
  DealListQuery,
  LeadDto,
  LeadListQuery,
  MeetingDto,
  MeetingListQuery,
  NotificationDto,
  Paginated,
  PipelineDto,
  PipelineQuery,
  ReferencesDto,
  StageHistoryDto,
} from '@fluggi/contracts';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api-client';

type Target = { leadId?: string; dealId?: string; clientId?: string };
const q = (o: object) => o as Record<string, string | number | undefined>;

export const crmKeys = {
  refs: ['references'] as const,
  leads: (p: LeadListQuery) => ['leads', p] as const,
  lead: (id: string) => ['lead', id] as const,
  deals: (p: DealListQuery) => ['deals', p] as const,
  deal: (id: string) => ['deal', id] as const,
  clients: (p: ClientListQuery) => ['clients', p] as const,
  client: (id: string) => ['client', id] as const,
  pipeline: (p: PipelineQuery) => ['pipeline', p] as const,
  meetings: (p: MeetingListQuery) => ['meetings', p] as const,
  timeline: (t: Target) => ['timeline', t] as const,
  history: (t: Target) => ['stage-history', t] as const,
  comments: (t: Target) => ['comments', t] as const,
};

export function useReferences() {
  return useQuery({
    queryKey: crmKeys.refs,
    queryFn: () => api<ReferencesDto>('/references'),
    staleTime: 5 * 60_000,
  });
}

export function useLeads(p: LeadListQuery) {
  return useQuery({
    queryKey: crmKeys.leads(p),
    queryFn: ({ signal }) => api<Paginated<LeadDto>>('/leads', { query: q(p), signal }),
    placeholderData: keepPreviousData,
  });
}
export function useLead(id: string) {
  return useQuery({ queryKey: crmKeys.lead(id), queryFn: () => api<LeadDto>(`/leads/${id}`) });
}
export function useDeals(p: DealListQuery, enabled = true) {
  return useQuery({
    queryKey: crmKeys.deals(p),
    queryFn: ({ signal }) => api<Paginated<DealDto>>('/deals', { query: q(p), signal }),
    placeholderData: keepPreviousData,
    enabled,
  });
}
export function useDeal(id: string) {
  return useQuery({ queryKey: crmKeys.deal(id), queryFn: () => api<DealDto>(`/deals/${id}`) });
}
export function useClients(p: ClientListQuery, enabled = true) {
  return useQuery({
    queryKey: crmKeys.clients(p),
    queryFn: ({ signal }) => api<Paginated<ClientDto>>('/clients', { query: q(p), signal }),
    placeholderData: keepPreviousData,
    enabled,
  });
}
export function useClient(id: string | null | undefined) {
  return useQuery({
    queryKey: crmKeys.client(id ?? ''),
    queryFn: () => api<ClientDetailDto>(`/clients/${id}`),
    // Без id запрос не отправляется: иначе `/clients/` вернул бы список клиентов.
    enabled: Boolean(id),
  });
}
export function usePipeline(p: PipelineQuery) {
  return useQuery({
    queryKey: crmKeys.pipeline(p),
    queryFn: () => api<PipelineDto>('/pipeline', { query: q(p) }),
  });
}
export function useMeetings(p: MeetingListQuery, enabled = true) {
  return useQuery({
    queryKey: crmKeys.meetings(p),
    queryFn: ({ signal }) => api<Paginated<MeetingDto>>('/meetings', { query: q(p), signal }),
    placeholderData: keepPreviousData,
    enabled,
  });
}
export function useTimeline(t: Target) {
  return useQuery({
    queryKey: crmKeys.timeline(t),
    queryFn: () => api<ActivityDto[]>('/timeline', { query: q(t) }),
  });
}
export function useStageHistory(t: Target) {
  return useQuery({
    queryKey: crmKeys.history(t),
    queryFn: () => api<StageHistoryDto[]>('/stage-history', { query: q(t) }),
  });
}
export function useComments(t: Target) {
  return useQuery({
    queryKey: crmKeys.comments(t),
    queryFn: () => api<CommentDto[]>('/comments', { query: q(t) }),
  });
}
export function useNotifications(page: number) {
  return useQuery({
    queryKey: ['notifications', page],
    queryFn: () =>
      api<Paginated<NotificationDto>>('/notifications', { query: { page, pageSize: 30 } }),
  });
}
export function useUnreadCount() {
  return useQuery({
    queryKey: ['notifications', 'unread'],
    queryFn: () => api<{ count: number }>('/notifications/unread-count'),
    refetchInterval: 60_000,
    refetchOnWindowFocus: true,
  });
}

/** Любое изменение CRM-записи: обновить все связанные списки и карточки. */
export function useInvalidateCrm() {
  const qc = useQueryClient();
  return () =>
    Promise.all(
      [
        'leads',
        'lead',
        'deals',
        'deal',
        'clients',
        'client',
        'pipeline',
        'meetings',
        'timeline',
        'stage-history',
        'comments',
        'notifications',
        'proposals',
        'contracts',
        'payments',
        'commissions',
        'files',
        'projects',
        'project',
        'tasks',
        'task',
        'project-templates',
        'attendance',
      ].map((k) => qc.invalidateQueries({ queryKey: [k] })),
    );
}

/** Универсальная мутация CRM с инвалидацией кэша. */
export function useCrmMutation<TVars, TResult = unknown>(fn: (vars: TVars) => Promise<TResult>) {
  const invalidate = useInvalidateCrm();
  return useMutation({ mutationFn: fn, onSuccess: () => invalidate() });
}

'use client';

import type {
  FormSubmissionDto,
  InboxListQuery,
  IntegrationSettings,
  LeadFormDto,
  MetaStatusDto,
  Paginated,
  SocialMessageDto,
  SocialThreadDto,
} from '@fluggi/contracts';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api-client';

export function useLeadForms() {
  return useQuery({
    queryKey: ['integrations', 'forms'],
    queryFn: () => api<LeadFormDto[]>('/lead-forms'),
  });
}

export function useFormSubmissions(id: string | null) {
  return useQuery({
    queryKey: ['integrations', 'submissions', id],
    queryFn: () => api<FormSubmissionDto[]>(`/lead-forms/${id}/submissions`),
    enabled: Boolean(id),
  });
}

export function useIntegrationSettings() {
  return useQuery({
    queryKey: ['integrations', 'settings'],
    queryFn: () => api<IntegrationSettings>('/settings/integrations'),
  });
}

export function useMetaStatus() {
  return useQuery({
    queryKey: ['integrations', 'meta'],
    queryFn: () => api<MetaStatusDto>('/integrations/meta'),
  });
}

export function useIntegrationMutation<TVars, TResult = unknown>(
  fn: (vars: TVars) => Promise<TResult>,
) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: fn,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['integrations'] }),
  });
}

export function useInbox(q: InboxListQuery, enabled = true) {
  return useQuery({
    queryKey: ['inbox', 'list', q],
    queryFn: () =>
      api<Paginated<SocialThreadDto> & { unread: number }>('/inbox', {
        query: q as Record<string, string | number | undefined>,
      }),
    placeholderData: keepPreviousData,
    refetchInterval: 20_000,
    enabled,
  });
}

export function useThreadMessages(id: string | null) {
  return useQuery({
    queryKey: ['inbox', 'messages', id],
    queryFn: () => api<SocialMessageDto[]>(`/inbox/${id}/messages`),
    enabled: Boolean(id),
    refetchInterval: id ? 10_000 : false,
  });
}

export function useInboxMutation<TVars, TResult = unknown>(fn: (vars: TVars) => Promise<TResult>) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: fn,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['inbox'] }),
  });
}

'use client';

import type {
  AutomationSettings,
  FollowUpDto,
  FollowUpListQuery,
  JobRunDto,
  NotificationSettingDto,
  Paginated,
  ReportPreviewDto,
  TelegramStatusDto,
} from '@fluggi/contracts';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api-client';

export interface JobDto {
  name: string;
  label: string;
  schedule: string;
  last: JobRunDto | null;
}

export function useTelegramStatus() {
  return useQuery({
    queryKey: ['telegram'],
    queryFn: () => api<TelegramStatusDto>('/me/telegram'),
  });
}

export function useNotificationSettings() {
  return useQuery({
    queryKey: ['notifications', 'settings'],
    queryFn: () => api<NotificationSettingDto[]>('/notifications/settings'),
  });
}

export function useAutomationSettings() {
  return useQuery({
    queryKey: ['automation', 'settings'],
    queryFn: () => api<AutomationSettings>('/settings/automation'),
  });
}

export function useJobs() {
  return useQuery({
    queryKey: ['automation', 'jobs'],
    queryFn: () => api<JobDto[]>('/automation/jobs'),
  });
}

export function useReportPreview(kind: 'daily' | 'weekly', enabled: boolean) {
  return useQuery({
    queryKey: ['automation', 'report', kind],
    queryFn: () => api<ReportPreviewDto>('/reports/preview', { query: { kind } }),
    enabled,
  });
}

export function useFollowUps(q: FollowUpListQuery) {
  return useQuery({
    queryKey: ['follow-ups', q],
    queryFn: () =>
      api<Paginated<FollowUpDto>>('/follow-ups', {
        query: q as Record<string, string | number | boolean | undefined>,
      }),
    placeholderData: keepPreviousData,
  });
}

/** Мутация с обновлением перечисленных ключей кэша. */
export function useInvalidating<TVars, TResult = unknown>(
  keys: string[][],
  fn: (vars: TVars) => Promise<TResult>,
) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: fn,
    onSuccess: () => Promise.all(keys.map((queryKey) => qc.invalidateQueries({ queryKey }))),
  });
}

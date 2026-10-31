'use client';

import type {
  EmployeeRateDto,
  FinanceSettings,
  OtherIncomeDto,
  OtherIncomeListQuery,
  Paginated,
  ProjectCostLineDto,
  TariffDto,
  WorkItemDto,
} from '@fluggi/contracts';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api-client';

export function useTariffs(enabled = true, all = false) {
  return useQuery({
    queryKey: ['tariffs', all],
    queryFn: () => api<TariffDto[]>('/tariffs', { query: all ? { all: 'true' } : undefined }),
    enabled,
  });
}

export function useWorkItems(enabled = true) {
  return useQuery({
    queryKey: ['work-items'],
    queryFn: () => api<WorkItemDto[]>('/work-items'),
    enabled,
  });
}

export function useEmployeeRates(userId: string | null) {
  return useQuery({
    queryKey: ['employee-rates', userId],
    queryFn: () => api<EmployeeRateDto[]>(`/users/${userId}/rates`),
    enabled: Boolean(userId),
  });
}

export function useFinanceSettings(enabled = true) {
  return useQuery({
    queryKey: ['settings', 'finance'],
    queryFn: () => api<FinanceSettings>('/settings/finance'),
    enabled,
  });
}

export function useOtherIncomes(q: OtherIncomeListQuery) {
  return useQuery({
    queryKey: ['other-incomes', q],
    queryFn: () =>
      api<Paginated<OtherIncomeDto>>('/other-incomes', {
        query: q as Record<string, string | number | undefined>,
      }),
    placeholderData: keepPreviousData,
  });
}

export function useCostLines(projectId: string, enabled = true) {
  return useQuery({
    queryKey: ['payments', 'cost-lines', projectId],
    queryFn: () => api<ProjectCostLineDto[]>(`/projects/${projectId}/cost-lines`),
    enabled,
  });
}

/** Мутация справочников финансов: обновляет все связанные запросы. */
export function useCatalogMutation<TVars, TResult = unknown>(
  fn: (vars: TVars) => Promise<TResult>,
) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: fn,
    onSuccess: () =>
      Promise.all(
        [
          ['tariffs'],
          ['work-items'],
          ['employee-rates'],
          ['finance-categories'],
          ['settings', 'finance'],
          ['other-incomes'],
          ['payments'],
        ].map((queryKey) => qc.invalidateQueries({ queryKey })),
      ),
  });
}

'use client';

import type {
  CommissionRuleDto,
  ExpenseDto,
  ExpenseListQuery,
  FinanceSummaryDto,
  Paginated,
  PeriodQuery,
  ProjectFinanceDto,
  ProjectProfitDto,
} from '@fluggi/contracts';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api-client';

const q = (o: object) => o as Record<string, string | number | boolean | undefined>;

export function useFinanceSummary(p: PeriodQuery) {
  return useQuery({
    queryKey: ['payments', 'finance-summary', p],
    queryFn: () => api<FinanceSummaryDto>('/finance/summary', { query: q(p) }),
    placeholderData: keepPreviousData,
  });
}

export function useExpenses(p: ExpenseListQuery, enabled = true) {
  return useQuery({
    queryKey: ['payments', 'expenses', p],
    queryFn: () => api<Paginated<ExpenseDto> & { totalUzs: string }>('/expenses', { query: q(p) }),
    placeholderData: keepPreviousData,
    enabled,
  });
}

export function useProjectFinance(id: string, enabled = true) {
  return useQuery({
    queryKey: ['payments', 'project-finance', id],
    queryFn: () => api<ProjectFinanceDto>(`/projects/${id}/finance`),
    enabled,
  });
}

export function useProjectsProfit(p: { q?: string; page: number; pageSize: number }) {
  return useQuery({
    queryKey: ['payments', 'projects-profit', p],
    queryFn: () => api<Paginated<ProjectProfitDto>>('/finance/projects', { query: q(p) }),
    placeholderData: keepPreviousData,
  });
}

export function useCommissionRules() {
  return useQuery({
    queryKey: ['commissions', 'rules'],
    queryFn: () => api<CommissionRuleDto[]>('/commission-rules'),
  });
}

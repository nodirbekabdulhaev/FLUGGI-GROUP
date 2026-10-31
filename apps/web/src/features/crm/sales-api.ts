'use client';

import type {
  CommissionDto,
  CommissionListQuery,
  ContractDto,
  ContractListQuery,
  DealMoneyDto,
  FileDto,
  Paginated,
  PaymentDto,
  PaymentListQuery,
  ProposalDto,
  ProposalListQuery,
  ProposalVersionDto,
} from '@fluggi/contracts';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api-client';

const q = (o: object) => o as Record<string, string | number | undefined>;

export function useProposals(p: ProposalListQuery, enabled = true) {
  return useQuery({
    queryKey: ['proposals', p],
    queryFn: () => api<Paginated<ProposalDto>>('/proposals', { query: q(p) }),
    placeholderData: keepPreviousData,
    enabled,
  });
}
export function useProposalVersions(id: string | null) {
  return useQuery({
    queryKey: ['proposals', 'versions', id],
    queryFn: () => api<ProposalVersionDto[]>(`/proposals/${id}/versions`),
    enabled: Boolean(id),
  });
}
export function useContracts(p: ContractListQuery, enabled = true) {
  return useQuery({
    queryKey: ['contracts', p],
    queryFn: () => api<Paginated<ContractDto>>('/contracts', { query: q(p) }),
    placeholderData: keepPreviousData,
    enabled,
  });
}
export function usePayments(p: PaymentListQuery, enabled = true) {
  return useQuery({
    queryKey: ['payments', p],
    queryFn: () => api<Paginated<PaymentDto>>('/payments', { query: q(p) }),
    placeholderData: keepPreviousData,
    enabled,
  });
}
export function useDealMoney(dealId: string, enabled = true) {
  return useQuery({
    queryKey: ['payments', 'money', dealId],
    queryFn: () => api<DealMoneyDto>(`/deals/${dealId}/money`),
    enabled,
  });
}
export function useCommissions(p: CommissionListQuery) {
  return useQuery({
    queryKey: ['commissions', p],
    queryFn: () =>
      api<Paginated<CommissionDto> & { totalUzs: string }>('/commissions', { query: q(p) }),
    placeholderData: keepPreviousData,
  });
}
export function useDealFiles(dealId: string) {
  return useQuery({
    queryKey: ['files', dealId],
    queryFn: () => api<FileDto[]>('/files', { query: { dealId } }),
  });
}

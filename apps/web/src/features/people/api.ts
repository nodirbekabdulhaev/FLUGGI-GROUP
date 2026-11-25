'use client';

import type {
  AttendanceDto,
  AttendanceSummaryDto,
  AttendanceTodayDto,
  DashboardDto,
  KpiGroup,
  KpiRowDto,
  PayrollEntryDto,
  PeriodQuery,
  ScheduleDto,
} from '@fluggi/contracts';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api-client';

const q = (o: object) => o as Record<string, string | number | boolean | undefined>;

/** Текущий месяц по Ташкенту, YYYY-MM. */
export const currentMonth = () => new Date(Date.now() + 5 * 3_600_000).toISOString().slice(0, 7);

export function useDashboard(p: PeriodQuery) {
  return useQuery({
    queryKey: ['payments', 'dashboard', p],
    queryFn: () => api<DashboardDto>('/dashboard', { query: q(p) }),
    placeholderData: keepPreviousData,
  });
}

export function useKpi(period: string, group?: KpiGroup) {
  return useQuery({
    queryKey: ['payments', 'kpi', period, group],
    queryFn: () => api<KpiRowDto[]>('/kpi', { query: { period, group } }),
    placeholderData: keepPreviousData,
  });
}

export function useAttendanceToday() {
  return useQuery({
    queryKey: ['attendance', 'today'],
    queryFn: () => api<AttendanceTodayDto>('/attendance/today'),
  });
}

export function useAttendance(p: { dateFrom: string; dateTo: string; userId?: string }) {
  return useQuery({
    queryKey: ['attendance', 'list', p],
    queryFn: () => api<AttendanceDto[]>('/attendance', { query: q(p) }),
    placeholderData: keepPreviousData,
  });
}

export function useAttendanceSummary(p: { dateFrom: string; dateTo: string }) {
  return useQuery({
    queryKey: ['attendance', 'summary', p],
    queryFn: () => api<AttendanceSummaryDto[]>('/attendance/summary', { query: q(p) }),
    placeholderData: keepPreviousData,
  });
}

export function useSchedules(enabled = true) {
  return useQuery({
    queryKey: ['attendance', 'schedules'],
    queryFn: () => api<ScheduleDto[]>('/work-schedules'),
    enabled,
  });
}

export function usePayroll(period: string) {
  return useQuery({
    queryKey: ['payments', 'payroll', period],
    queryFn: () => api<PayrollEntryDto[]>('/payroll', { query: { period } }),
    placeholderData: keepPreviousData,
  });
}

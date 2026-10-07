import { z } from 'zod';
import {
  ATTENDANCE_STATUSES,
  CURRENCIES,
  KPI_METRICS,
  ROLE_CODES,
  type AttendanceStatus,
  type Currency,
  type KpiMetric,
  type PayrollStatus,
  type RoleCode,
} from '../enums';
import { dateOnly, moneySchema } from './fields';
import type { NamedRef } from './references';

export const monthSchema = z.string().regex(/^\d{4}-(0[1-9]|1[0-2])$/, 'Период в формате ГГГГ-ММ');

// ─────────────────────────── KPI (ТЗ §28–31) ───────────────────────────

export const KPI_GROUPS = ['MANAGER', 'ROP', 'EXECUTOR'] as const;
export type KpiGroup = (typeof KPI_GROUPS)[number];

export const kpiQuerySchema = z.object({
  period: monthSchema,
  group: z.enum(KPI_GROUPS).optional(),
});

/** Показатели менеджера за месяц (ТЗ §28). Деньги — UZS. */
export interface ManagerKpi {
  leads: number;
  processedLeads: number;
  meetings: number;
  proposals: number;
  contracts: number;
  payments: number;
  orders: number;
  revenueUzs: string;
  avgCheckUzs: string;
  /** Лиды, ставшие сделкой, из созданных за месяц, % */
  conversionPct: string | null;
}

/** Показатели РОП — по отделу (ТЗ §29). */
export interface RopKpi {
  teamRevenueUzs: string;
  orders: number;
  avgCheckUzs: string;
  conversionPct: string | null;
  managers: number;
  /** Выполнение плана отдела по выручке, % (сумма целей менеджеров и РОП) */
  planPct: string | null;
  planUzs: string;
  marginPct: string | null;
  projects: number;
  overdueTasks: number;
  overdueProjects: number;
}

/** Показатели исполнителя (ТЗ §30). */
export interface ExecutorKpi {
  tasks: number;
  done: number;
  overdue: number;
  /** Среднее время выполнения (взял в работу → готово), часов */
  avgHours: string | null;
  completionPct: string | null;
  reworks: number;
}

export interface TargetProgressDto {
  metric: KpiMetric;
  target: string;
  currency: Currency;
  fact: string;
  /** Выполнение, % (null — цели нет) */
  pct: string | null;
}

export interface KpiRowDto {
  user: NamedRef;
  role: RoleCode;
  team: NamedRef | null;
  manager?: ManagerKpi;
  rop?: RopKpi;
  executor?: ExecutorKpi;
  targets: TargetProgressDto[];
  /** Среднее выполнение целей, % (null — целей нет) */
  kpiPct: string | null;
}

export const setTargetsSchema = z.object({
  userId: z.uuid(),
  period: monthSchema,
  targets: z
    .array(
      z.object({
        metric: z.enum(KPI_METRICS),
        /** null/пусто — удалить цель */
        value: z
          .union([z.string(), z.number(), z.null()])
          .transform((v) => (v === null || v === '' ? null : String(v).replace(/\s/g, ''))),
        currency: z.enum(CURRENCIES).default('UZS'),
      }),
    )
    .max(KPI_METRICS.length),
});
export type SetTargetsInput = z.input<typeof setTargetsSchema>;

// ─────────────────────────── Графики (ТЗ §36) ───────────────────────────

const hhmm = z.string().regex(/^([01]\d|2[0-3]):[0-5]\d$/, 'Время в формате ЧЧ:ММ');

export const upsertScheduleSchema = z
  .object({
    name: z.string().trim().min(1, 'Укажите название').max(100),
    roleCode: z.enum(ROLE_CODES).nullish(),
    startTime: hhmm,
    endTime: hhmm,
    workDays: z.array(z.number().int().min(1).max(7)).min(1, 'Выберите рабочие дни').max(7),
    graceMinutes: z.coerce.number().int().min(0).max(180).default(0),
    isActive: z.boolean().default(true),
    /** Сотрудники с индивидуальным графиком */
    userIds: z.array(z.uuid()).max(500).default([]),
  })
  .refine((v) => v.startTime < v.endTime, {
    message: 'Конец раньше начала',
    path: ['endTime'],
  });
export type UpsertScheduleInput = z.input<typeof upsertScheduleSchema>;

export interface ScheduleDto {
  id: string;
  name: string;
  roleCode: RoleCode | null;
  startTime: string;
  endTime: string;
  workDays: number[];
  graceMinutes: number;
  isActive: boolean;
  users: NamedRef[];
}

// ─────────────────────────── Посещаемость (ТЗ §35) ───────────────────────────

export const attendanceQuerySchema = z.object({
  dateFrom: dateOnly,
  dateTo: dateOnly,
  userId: z.uuid().optional(),
});

export const upsertAttendanceSchema = z.object({
  userId: z.uuid(),
  date: dateOnly,
  status: z.enum(ATTENDANCE_STATUSES),
  /** HH:MM по Ташкенту */
  checkIn: hhmm.nullish(),
  checkOut: hhmm.nullish(),
  comment: z.string().trim().max(500).nullish(),
});
export type UpsertAttendanceInput = z.input<typeof upsertAttendanceSchema>;

export const checkSchema = z.object({ comment: z.string().trim().max(500).optional() });

export interface AttendanceDto {
  id: string;
  user: NamedRef;
  date: string;
  checkIn: string | null;
  checkOut: string | null;
  status: AttendanceStatus;
  lateMinutes: number;
  workMinutes: number;
  comment: string | null;
  editedBy: NamedRef | null;
}

export interface AttendanceTodayDto {
  date: string;
  schedule: Pick<ScheduleDto, 'id' | 'name' | 'startTime' | 'endTime' | 'workDays'> | null;
  workday: boolean;
  record: AttendanceDto | null;
}

export interface AttendanceSummaryDto {
  user: NamedRef;
  present: number;
  late: number;
  lateMinutes: number;
  absent: number;
  dayOff: number;
  vacation: number;
  sick: number;
  workHours: string;
}

// ─────────────────────────── Зарплата (ТЗ §32) ───────────────────────────

export const updatePayrollSchema = z.object({
  baseSalary: moneySchema.optional(),
  kpiBonus: moneySchema.optional(),
  otherBonus: moneySchema.optional(),
  penalty: moneySchema.optional(),
  comment: z.string().trim().max(1000).nullable().optional(),
  /** Сохранить оклад как постоянный для следующих месяцев */
  saveBaseSalary: z.boolean().optional(),
  /** KPI-бонус при 100% выполнения (постоянно, в карточке сотрудника); null — убрать */
  kpiBonusTarget: moneySchema.nullable().optional(),
});
export type UpdatePayrollInput = z.input<typeof updatePayrollSchema>;

export const payrollIdsSchema = z.object({ ids: z.array(z.uuid()).min(1).max(500) });

export interface PayrollEntryDto {
  id: string;
  user: NamedRef;
  role: RoleCode;
  period: string;
  baseSalary: string;
  kpiBonus: string;
  commission: string;
  otherBonus: string;
  penalty: string;
  finalSalary: string;
  kpiPct: string | null;
  /** KPI-бонус при 100% из карточки сотрудника; null — не задан (бонус вносится вручную) */
  kpiBonusTarget: string | null;
  status: PayrollStatus;
  comment: string | null;
  approvedBy: NamedRef | null;
  approvedAt: string | null;
  paidAt: string | null;
}

// ─────────────────────────── Дашборды (ТЗ §5, §58–60) ───────────────────────────

export interface CeoDashboardDto {
  revenueUzs: string;
  profitUzs: string;
  paidUzs: string;
  expectedUzs: string;
  newLeads: number;
  newDeals: number;
  contracts: number;
  projectsInProgress: number;
}

export interface TeamDashboardDto {
  team: NamedRef | null;
  kpi: RopKpi;
  leads: number;
  meetings: number;
  proposals: number;
  contracts: number;
  payments: number;
  managers: KpiRowDto[];
}

export interface OwnDashboardDto {
  kpi: KpiRowDto;
  deals: number;
  commissionUzs: string;
  tasksOpen: number;
  tasksOverdue: number;
}

export interface ExecutorDashboardDto {
  projects: number;
  today: number;
  overdue: number;
  inProgress: number;
  done: number;
}

/** Мой KPI за месяц: выполнение целей и сумма KPI (оклад виден только самому сотруднику). */
export interface MyKpiDto {
  period: string;
  /** Среднее выполнение целей, % (null — целей на месяц нет) */
  pct: string | null;
  targets: TargetProgressDto[];
  /** KPI-бонус при 100%; null — не задан */
  bonusTarget: string | null;
  /** KPI-бонус за месяц по текущему выполнению */
  bonusUzs: string;
  commissionUzs: string;
  baseSalary: string | null;
  /** Оклад + KPI-бонус + комиссия */
  expectedUzs: string;
}

export interface DashboardDto {
  from: string;
  to: string;
  myKpi?: MyKpiDto;
  ceo?: CeoDashboardDto;
  team?: TeamDashboardDto;
  own?: OwnDashboardDto;
  executor?: ExecutorDashboardDto;
}

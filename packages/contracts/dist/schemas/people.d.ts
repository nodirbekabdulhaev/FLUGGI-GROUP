import { z } from 'zod';
import { type AttendanceStatus, type Currency, type KpiMetric, type PayrollStatus, type RoleCode } from '../enums';
import type { NamedRef } from './references';
export declare const monthSchema: z.ZodString;
export declare const KPI_GROUPS: readonly ["MANAGER", "ROP", "EXECUTOR"];
export type KpiGroup = (typeof KPI_GROUPS)[number];
export declare const kpiQuerySchema: z.ZodObject<{
    period: z.ZodString;
    group: z.ZodOptional<z.ZodEnum<{
        ROP: "ROP";
        MANAGER: "MANAGER";
        EXECUTOR: "EXECUTOR";
    }>>;
}, z.core.$strip>;
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
export declare const setTargetsSchema: z.ZodObject<{
    userId: z.ZodUUID;
    period: z.ZodString;
    targets: z.ZodArray<z.ZodObject<{
        metric: z.ZodEnum<{
            REVENUE: "REVENUE";
            ORDERS: "ORDERS";
            LEADS: "LEADS";
            MEETINGS: "MEETINGS";
            TASKS: "TASKS";
        }>;
        value: z.ZodPipe<z.ZodUnion<readonly [z.ZodString, z.ZodNumber, z.ZodNull]>, z.ZodTransform<string | null, string | number | null>>;
        currency: z.ZodDefault<z.ZodEnum<{
            UZS: "UZS";
            USD: "USD";
        }>>;
    }, z.core.$strip>>;
}, z.core.$strip>;
export type SetTargetsInput = z.input<typeof setTargetsSchema>;
export declare const upsertScheduleSchema: z.ZodObject<{
    name: z.ZodString;
    roleCode: z.ZodOptional<z.ZodNullable<z.ZodEnum<{
        CEO: "CEO";
        ROP: "ROP";
        MANAGER: "MANAGER";
        EXECUTOR: "EXECUTOR";
        HR_ADMIN: "HR_ADMIN";
        PROJECT_MANAGER: "PROJECT_MANAGER";
    }>>>;
    startTime: z.ZodString;
    endTime: z.ZodString;
    workDays: z.ZodArray<z.ZodNumber>;
    graceMinutes: z.ZodDefault<z.ZodCoercedNumber<unknown>>;
    isActive: z.ZodDefault<z.ZodBoolean>;
    userIds: z.ZodDefault<z.ZodArray<z.ZodUUID>>;
}, z.core.$strip>;
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
export declare const attendanceQuerySchema: z.ZodObject<{
    dateFrom: z.ZodISODate;
    dateTo: z.ZodISODate;
    userId: z.ZodOptional<z.ZodUUID>;
}, z.core.$strip>;
export declare const upsertAttendanceSchema: z.ZodObject<{
    userId: z.ZodUUID;
    date: z.ZodISODate;
    status: z.ZodEnum<{
        PRESENT: "PRESENT";
        LATE: "LATE";
        ABSENT: "ABSENT";
        DAY_OFF: "DAY_OFF";
        VACATION: "VACATION";
        SICK: "SICK";
    }>;
    checkIn: z.ZodOptional<z.ZodNullable<z.ZodString>>;
    checkOut: z.ZodOptional<z.ZodNullable<z.ZodString>>;
    comment: z.ZodOptional<z.ZodNullable<z.ZodString>>;
}, z.core.$strip>;
export type UpsertAttendanceInput = z.input<typeof upsertAttendanceSchema>;
export declare const checkSchema: z.ZodObject<{
    comment: z.ZodOptional<z.ZodString>;
}, z.core.$strip>;
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
/**
 * Посещаемость и фиксированный оклад — только у менеджеров и РОП. Исполнители получают
 * сдельно (начисления по проектам), CEO и проект-менеджер приход не отмечают.
 */
export declare const ATTENDANCE_ROLES: readonly ["MANAGER", "ROP"];
export declare const FIXED_SALARY_ROLES: readonly ["MANAGER", "ROP"];
export declare const tracksAttendance: (role: string) => boolean;
export declare const hasFixedSalary: (role: string) => boolean;
export interface AttendanceTodayDto {
    /** Сотрудник отмечает приход (менеджер, РОП) */
    tracked: boolean;
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
export declare const updatePayrollSchema: z.ZodObject<{
    baseSalary: z.ZodOptional<z.ZodPipe<z.ZodPipe<z.ZodUnion<readonly [z.ZodString, z.ZodNumber]>, z.ZodTransform<string, string | number>>, z.ZodString>>;
    kpiBonus: z.ZodOptional<z.ZodPipe<z.ZodPipe<z.ZodUnion<readonly [z.ZodString, z.ZodNumber]>, z.ZodTransform<string, string | number>>, z.ZodString>>;
    otherBonus: z.ZodOptional<z.ZodPipe<z.ZodPipe<z.ZodUnion<readonly [z.ZodString, z.ZodNumber]>, z.ZodTransform<string, string | number>>, z.ZodString>>;
    penalty: z.ZodOptional<z.ZodPipe<z.ZodPipe<z.ZodUnion<readonly [z.ZodString, z.ZodNumber]>, z.ZodTransform<string, string | number>>, z.ZodString>>;
    comment: z.ZodOptional<z.ZodNullable<z.ZodString>>;
    saveBaseSalary: z.ZodOptional<z.ZodBoolean>;
    kpiBonusTarget: z.ZodOptional<z.ZodNullable<z.ZodPipe<z.ZodPipe<z.ZodUnion<readonly [z.ZodString, z.ZodNumber]>, z.ZodTransform<string, string | number>>, z.ZodString>>>;
}, z.core.$strip>;
export type UpdatePayrollInput = z.input<typeof updatePayrollSchema>;
export declare const payrollIdsSchema: z.ZodObject<{
    ids: z.ZodArray<z.ZodUUID>;
}, z.core.$strip>;
export interface PayrollEntryDto {
    id: string;
    user: NamedRef;
    role: RoleCode;
    period: string;
    baseSalary: string;
    /** Сдельная оплата: начисления исполнителю по проектам за месяц */
    pieceRate: string;
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
    /** Сдельно: начисления по проектам за месяц (исполнители) */
    pieceRateUzs: string;
    /** Оклад + сдельно + KPI-бонус + комиссия */
    expectedUzs: string;
}
/** Проект-менеджер направления: всё по проектам своих направлений. */
export interface ProjectsDashboardDto {
    directions: string[];
    active: number;
    /** Активные проекты с прошедшим дедлайном */
    overdueProjects: number;
    /** Активные проекты без исполнителей */
    unassigned: number;
    tasksOpen: number;
    tasksOverdue: number;
    /** Задачи со сроком сегодня */
    tasksToday: number;
    /** Проекты, завершённые за период */
    completed: number;
}
export interface DashboardDto {
    from: string;
    to: string;
    projects?: ProjectsDashboardDto;
    myKpi?: MyKpiDto;
    ceo?: CeoDashboardDto;
    team?: TeamDashboardDto;
    own?: OwnDashboardDto;
    executor?: ExecutorDashboardDto;
}

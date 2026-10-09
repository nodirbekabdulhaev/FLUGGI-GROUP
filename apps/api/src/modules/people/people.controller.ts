import { Body, Controller, Get, HttpCode, Param, Patch, Post, Put, Query } from '@nestjs/common';
import {
  attendanceQuerySchema,
  checkSchema,
  kpiQuerySchema,
  monthSchema,
  payrollIdsSchema,
  periodQuerySchema,
  setTargetsSchema,
  updatePayrollSchema,
  upsertAttendanceSchema,
  upsertScheduleSchema,
  type AttendanceDto,
  type AttendanceSummaryDto,
  type AttendanceTodayDto,
  type DashboardDto,
  type KpiRowDto,
  type PayrollEntryDto,
  type ScheduleDto,
} from '@fluggi/contracts';
import { monthRange } from '@fluggi/domain';
import { z } from 'zod';
import type { AuthContext, RequestMeta } from '../../core/auth/auth-context';
import {
  AuthenticatedOnly,
  CurrentUser,
  ReqMeta,
  RequirePermission,
} from '../../core/auth/decorators';
import { forbidden } from '../../core/http/app.exception';
import { UuidPipe } from '../../core/http/uuid.pipe';
import { zod } from '../../core/http/zod.pipe';
import { DashboardService } from './dashboard.service';
import { KpiService } from './kpi.service';
import { PeopleService } from './people.service';

const targetsQuery = z.object({ userId: z.uuid(), period: monthSchema });
const periodBody = z.object({ period: monthSchema });

/** Дашборд, KPI, цели, графики, посещаемость, зарплата (ТЗ §5, §28–32, §35–36, §58–60). */
@Controller()
export class PeopleController {
  constructor(
    private readonly kpi: KpiService,
    private readonly people: PeopleService,
    private readonly dashboard: DashboardService,
  ) {}

  @Get('dashboard')
  @AuthenticatedOnly()
  getDashboard(
    @CurrentUser() auth: AuthContext,
    @Query(zod(periodQuerySchema)) q: z.output<typeof periodQuerySchema>,
  ): Promise<DashboardDto> {
    return this.dashboard.get(auth, q);
  }

  // KPI и цели
  @Get('kpi')
  @RequirePermission('kpi.read')
  list(
    @CurrentUser() auth: AuthContext,
    @Query(zod(kpiQuerySchema)) q: z.output<typeof kpiQuerySchema>,
  ): Promise<KpiRowDto[]> {
    return this.kpi.rows(auth, q.period, monthRange(q.period), { group: q.group });
  }

  @Get('kpi/targets')
  @RequirePermission('kpi.read')
  targets(
    @CurrentUser() auth: AuthContext,
    @Query(zod(targetsQuery)) q: z.output<typeof targetsQuery>,
  ) {
    return this.kpi.targets(auth, q.userId, q.period);
  }

  @Put('kpi/targets')
  @RequirePermission('kpi.target.manage')
  setTargets(
    @CurrentUser() auth: AuthContext,
    @Body(zod(setTargetsSchema)) body: z.output<typeof setTargetsSchema>,
    @ReqMeta() meta: RequestMeta,
  ) {
    return this.people.setTargets(auth, body, meta);
  }

  // Рабочие графики
  @Get('work-schedules')
  @AuthenticatedOnly()
  schedules(@CurrentUser() auth: AuthContext): Promise<ScheduleDto[]> {
    if (!auth.permissions['schedule.manage'] && !auth.permissions['attendance.manage'])
      throw forbidden();
    return this.people.schedules();
  }

  @Post('work-schedules')
  @RequirePermission('schedule.manage', 'ALL')
  createSchedule(
    @CurrentUser() auth: AuthContext,
    @Body(zod(upsertScheduleSchema)) body: z.output<typeof upsertScheduleSchema>,
    @ReqMeta() meta: RequestMeta,
  ): Promise<ScheduleDto[]> {
    return this.people.upsertSchedule(auth, null, body, meta);
  }

  @Put('work-schedules/:id')
  @RequirePermission('schedule.manage', 'ALL')
  updateSchedule(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @Body(zod(upsertScheduleSchema)) body: z.output<typeof upsertScheduleSchema>,
    @ReqMeta() meta: RequestMeta,
  ): Promise<ScheduleDto[]> {
    return this.people.upsertSchedule(auth, id, body, meta);
  }

  // Посещаемость
  @Get('attendance/today')
  @AuthenticatedOnly()
  today(@CurrentUser() auth: AuthContext): Promise<AttendanceTodayDto> {
    return this.people.today(auth);
  }

  @Post('attendance/check-in')
  @HttpCode(200)
  @AuthenticatedOnly()
  checkIn(
    @CurrentUser() auth: AuthContext,
    @Body(zod(checkSchema)) body: z.output<typeof checkSchema>,
  ): Promise<AttendanceDto> {
    return this.people.checkIn(auth, body.comment);
  }

  @Post('attendance/check-out')
  @HttpCode(200)
  @AuthenticatedOnly()
  checkOut(
    @CurrentUser() auth: AuthContext,
    @Body(zod(checkSchema)) body: z.output<typeof checkSchema>,
  ): Promise<AttendanceDto> {
    return this.people.checkOut(auth, body.comment);
  }

  @Get('attendance')
  @RequirePermission('attendance.read')
  attendance(
    @CurrentUser() auth: AuthContext,
    @Query(zod(attendanceQuerySchema)) q: z.output<typeof attendanceQuerySchema>,
  ): Promise<AttendanceDto[]> {
    return this.people.list(auth, q);
  }

  @Get('attendance/summary')
  @RequirePermission('attendance.read')
  attendanceSummary(
    @CurrentUser() auth: AuthContext,
    @Query(zod(attendanceQuerySchema)) q: z.output<typeof attendanceQuerySchema>,
  ): Promise<AttendanceSummaryDto[]> {
    return this.people.summary(auth, q);
  }

  @Put('attendance')
  @RequirePermission('attendance.manage')
  upsertAttendance(
    @CurrentUser() auth: AuthContext,
    @Body(zod(upsertAttendanceSchema)) body: z.output<typeof upsertAttendanceSchema>,
    @ReqMeta() meta: RequestMeta,
  ): Promise<AttendanceDto> {
    return this.people.upsertAttendance(auth, body, meta);
  }

  // Зарплата
  @Get('payroll')
  @RequirePermission('payroll.read')
  payroll(
    @CurrentUser() auth: AuthContext,
    @Query(zod(periodBody)) q: z.output<typeof periodBody>,
  ): Promise<PayrollEntryDto[]> {
    return this.people.payroll(auth, q.period);
  }

  @Post('payroll/calculate')
  @HttpCode(200)
  @RequirePermission('payroll.manage', 'ALL')
  calculate(
    @CurrentUser() auth: AuthContext,
    @Body(zod(periodBody)) body: z.output<typeof periodBody>,
    @ReqMeta() meta: RequestMeta,
  ): Promise<PayrollEntryDto[]> {
    return this.people.calculate(auth, body.period, meta);
  }

  @Patch('payroll/:id')
  @RequirePermission('payroll.manage', 'ALL')
  updatePayroll(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @Body(zod(updatePayrollSchema)) body: z.output<typeof updatePayrollSchema>,
    @ReqMeta() meta: RequestMeta,
  ): Promise<PayrollEntryDto> {
    return this.people.updatePayroll(auth, id, body, meta);
  }

  @Post('payroll/approve')
  @HttpCode(200)
  @RequirePermission('payroll.manage', 'ALL')
  approvePayroll(
    @CurrentUser() auth: AuthContext,
    @Body(zod(payrollIdsSchema)) body: z.output<typeof payrollIdsSchema>,
    @ReqMeta() meta: RequestMeta,
  ) {
    return this.people.payrollTransition(auth, body.ids, 'APPROVED', meta);
  }

  @Post('payroll/pay')
  @HttpCode(200)
  @RequirePermission('payroll.manage', 'ALL')
  payPayroll(
    @CurrentUser() auth: AuthContext,
    @Body(zod(payrollIdsSchema)) body: z.output<typeof payrollIdsSchema>,
    @ReqMeta() meta: RequestMeta,
  ) {
    return this.people.payrollTransition(auth, body.ids, 'PAID', meta);
  }
}

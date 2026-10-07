import { Injectable } from '@nestjs/common';
import {
  type AttendanceDto,
  type AttendanceSummaryDto,
  type AttendanceTodayDto,
  type attendanceQuerySchema,
  type PayrollEntryDto,
  type ScheduleDto,
  type setTargetsSchema,
  type updatePayrollSchema,
  type upsertAttendanceSchema,
  type upsertScheduleSchema,
} from '@fluggi/contracts';
import {
  atTashkent,
  companyDate,
  finalSalary,
  isoWeekday,
  lateMinutes,
  monthRange,
  tashkentTime,
  workMinutes,
} from '@fluggi/domain';
import { Prisma } from '@fluggi/db';
import type { z } from 'zod';
import type { AuthContext, RequestMeta } from '../../core/auth/auth-context';
import { AuditService } from '../../core/audit/audit.service';
import { businessRule, conflict, forbidden, notFound } from '../../core/http/app.exception';
import { dateOnly, parseDate } from '../../core/http/serialize';
import { PrismaService } from '../../core/prisma/prisma.service';
import { KpiService } from './kpi.service';

const named = (u: { id: string; fullName: string } | null) =>
  u ? { id: u.id, name: u.fullName } : null;

const attendanceInclude = {
  user: { select: { id: true, fullName: true } },
  editedBy: { select: { id: true, fullName: true } },
} satisfies Prisma.AttendanceInclude;

type AttendanceRow = Prisma.AttendanceGetPayload<{ include: typeof attendanceInclude }>;

const toAttendance = (a: AttendanceRow): AttendanceDto => ({
  id: a.id,
  user: named(a.user)!,
  date: dateOnly(a.date)!,
  checkIn: a.checkIn ? tashkentTime(a.checkIn) : null,
  checkOut: a.checkOut ? tashkentTime(a.checkOut) : null,
  status: a.status,
  lateMinutes: a.lateMinutes,
  workMinutes: a.workMinutes,
  comment: a.comment,
  editedBy: named(a.editedBy),
});

const payrollInclude = {
  user: { select: { id: true, fullName: true, role: { select: { code: true } } } },
  approvedBy: { select: { id: true, fullName: true } },
} satisfies Prisma.PayrollEntryInclude;

type PayrollRow = Prisma.PayrollEntryGetPayload<{ include: typeof payrollInclude }>;

const toPayroll = (p: PayrollRow): PayrollEntryDto => ({
  id: p.id,
  user: named(p.user)!,
  role: p.user.role.code,
  period: p.period,
  baseSalary: p.baseSalary.toFixed(2),
  kpiBonus: p.kpiBonus.toFixed(2),
  commission: p.commission.toFixed(2),
  otherBonus: p.otherBonus.toFixed(2),
  penalty: p.penalty.toFixed(2),
  finalSalary: p.finalSalary.toFixed(2),
  kpiPct: p.kpiPct?.toFixed(2) ?? null,
  status: p.status,
  comment: p.comment,
  approvedBy: named(p.approvedBy),
  approvedAt: p.approvedAt?.toISOString() ?? null,
  paidAt: p.paidAt?.toISOString() ?? null,
});

/** Цели KPI, графики, посещаемость и зарплата (ТЗ §31, §32, §35, §36). */
@Injectable()
export class PeopleService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly kpi: KpiService,
    private readonly audit: AuditService,
  ) {}

  // ─────────────────────────── Цели (§31) ───────────────────────────

  /** CEO/HR — любому сотруднику; РОП — сотрудникам своего отдела, но не себе. */
  async setTargets(auth: AuthContext, input: z.output<typeof setTargetsSchema>, meta: RequestMeta) {
    const scope = auth.permissions['kpi.target.manage'];
    if (!scope) throw forbidden();
    if (scope !== 'ALL' && input.userId === auth.userId)
      throw forbidden('Свои цели устанавливает руководитель');
    const user = await this.prisma.user.findFirst({
      where: { AND: [this.kpi.usersWhere(auth, 'kpi.target.manage'), { id: input.userId }] },
    });
    if (!user) throw notFound('Сотрудник');
    await this.prisma.$transaction(async (tx) => {
      for (const t of input.targets) {
        const key = {
          userId_period_metric: { userId: input.userId, period: input.period, metric: t.metric },
        };
        const before = await tx.kpiTarget.findUnique({ where: key });
        if (t.value === null) {
          if (before) await tx.kpiTarget.delete({ where: key });
        } else {
          if (!/^\d{1,16}(\.\d{1,2})?$/.test(t.value))
            throw businessRule('Некорректное значение цели');
          await tx.kpiTarget.upsert({
            where: key,
            update: { targetValue: t.value, currency: t.currency, setById: auth.userId },
            create: {
              userId: input.userId,
              period: input.period,
              metric: t.metric,
              targetValue: t.value,
              currency: t.currency,
              setById: auth.userId,
            },
          });
        }
        if (
          (before?.targetValue.toFixed(2) ?? null) !==
          (t.value === null ? null : Number(t.value).toFixed(2))
        )
          await this.audit.log(tx, {
            actorId: auth.userId,
            action: 'kpi.target',
            entityType: 'user',
            entityId: input.userId,
            changes: {
              [`${input.period}.${t.metric}`]: {
                old: before?.targetValue.toFixed(2) ?? null,
                new: t.value,
              },
            },
            meta,
          });
      }
    });
    return this.kpi.targets(auth, input.userId, input.period);
  }

  // ─────────────────────────── Графики (§36) ───────────────────────────

  async schedules(): Promise<ScheduleDto[]> {
    const rows = await this.prisma.workSchedule.findMany({
      include: { employees: { include: { user: { select: { id: true, fullName: true } } } } },
      orderBy: { name: 'asc' },
    });
    return rows.map((s) => ({
      id: s.id,
      name: s.name,
      roleCode: s.roleCode,
      startTime: s.startTime,
      endTime: s.endTime,
      workDays: s.workDays,
      graceMinutes: s.graceMinutes,
      isActive: s.isActive,
      users: s.employees.map((e) => named(e.user)!),
    }));
  }

  async upsertSchedule(
    auth: AuthContext,
    id: string | null,
    input: z.output<typeof upsertScheduleSchema>,
    meta: RequestMeta,
  ): Promise<ScheduleDto[]> {
    const data = {
      name: input.name,
      roleCode: input.roleCode ?? null,
      startTime: input.startTime,
      endTime: input.endTime,
      workDays: [...new Set(input.workDays)].sort(),
      graceMinutes: input.graceMinutes,
      isActive: input.isActive,
    };
    await this.prisma.$transaction(async (tx) => {
      if (data.roleCode && data.isActive) {
        const clash = await tx.workSchedule.findFirst({
          where: { roleCode: data.roleCode, isActive: true, ...(id ? { id: { not: id } } : {}) },
        });
        if (clash) throw conflict(`У роли уже есть график «${clash.name}»`);
      }
      const before = id ? await tx.workSchedule.findUnique({ where: { id } }) : null;
      if (id && !before) throw notFound('График');
      const s = id
        ? await tx.workSchedule.update({ where: { id }, data })
        : await tx.workSchedule.create({ data });
      await tx.employee.updateMany({
        where: { scheduleId: s.id, userId: { notIn: input.userIds } },
        data: { scheduleId: null },
      });
      if (input.userIds.length)
        await tx.employee.updateMany({
          where: { userId: { in: input.userIds } },
          data: { scheduleId: s.id },
        });
      await this.audit.log(tx, {
        actorId: auth.userId,
        action: id ? 'schedule.update' : 'schedule.create',
        entityType: 'work_schedule',
        entityId: s.id,
        changes: {
          time: {
            old: before ? `${before.startTime}–${before.endTime}` : null,
            new: `${s.startTime}–${s.endTime}`,
          },
          users: { old: null, new: input.userIds.length },
        },
        meta,
      });
    });
    return this.schedules();
  }

  /** Индивидуальный график сотрудника, иначе — график его роли. */
  private async scheduleOf(userId: string) {
    const user = await this.prisma.user.findUniqueOrThrow({
      where: { id: userId },
      include: { role: true, employee: { include: { schedule: true } } },
    });
    const own = user.employee?.schedule;
    if (own?.isActive) return own;
    return this.prisma.workSchedule.findFirst({
      where: { roleCode: user.role.code, isActive: true },
    });
  }

  // ─────────────────────────── Посещаемость (§35) ───────────────────────────

  async today(auth: AuthContext): Promise<AttendanceTodayDto> {
    const date = companyDate(new Date());
    const [schedule, record] = await Promise.all([
      this.scheduleOf(auth.userId),
      this.prisma.attendance.findUnique({
        where: { userId_date: { userId: auth.userId, date: parseDate(date)! } },
        include: attendanceInclude,
      }),
    ]);
    return {
      date,
      schedule: schedule
        ? {
            id: schedule.id,
            name: schedule.name,
            startTime: schedule.startTime,
            endTime: schedule.endTime,
            workDays: schedule.workDays,
          }
        : null,
      workday: schedule ? schedule.workDays.includes(isoWeekday(date)) : true,
      record: record ? toAttendance(record) : null,
    };
  }

  /** Отметка прихода: опоздание считается по графику (ТЗ §35–36). */
  async checkIn(auth: AuthContext, comment: string | undefined): Promise<AttendanceDto> {
    const now = new Date();
    const date = companyDate(now);
    const key = { userId_date: { userId: auth.userId, date: parseDate(date)! } };
    const existing = await this.prisma.attendance.findUnique({ where: key });
    if (existing?.checkIn) throw conflict('Приход уже отмечен');
    if (existing && ['VACATION', 'SICK', 'DAY_OFF'].includes(existing.status))
      throw businessRule('На сегодня оформлен отпуск, больничный или выходной');
    const schedule = await this.scheduleOf(auth.userId);
    const late =
      schedule && schedule.workDays.includes(isoWeekday(date))
        ? lateMinutes(now, date, schedule.startTime, schedule.graceMinutes)
        : 0;
    const data = {
      checkIn: now,
      status: late > 0 ? ('LATE' as const) : ('PRESENT' as const),
      lateMinutes: late,
      comment: comment ?? existing?.comment ?? null,
    };
    const row = await this.prisma.attendance.upsert({
      where: key,
      update: data,
      create: { userId: auth.userId, date: parseDate(date)!, ...data },
      include: attendanceInclude,
    });
    return toAttendance(row);
  }

  async checkOut(auth: AuthContext, comment: string | undefined): Promise<AttendanceDto> {
    const now = new Date();
    const date = companyDate(now);
    const key = { userId_date: { userId: auth.userId, date: parseDate(date)! } };
    const existing = await this.prisma.attendance.findUnique({ where: key });
    if (!existing?.checkIn) throw businessRule('Сначала отметьте приход');
    if (existing.checkOut) throw conflict('Уход уже отмечен');
    const row = await this.prisma.attendance.update({
      where: key,
      data: {
        checkOut: now,
        workMinutes: workMinutes(existing.checkIn, now),
        comment: comment ?? existing.comment,
      },
      include: attendanceInclude,
    });
    return toAttendance(row);
  }

  private attendanceWhere(auth: AuthContext, q: z.output<typeof attendanceQuerySchema>) {
    return {
      user: this.kpi.usersWhere(auth, 'attendance.read'),
      date: { gte: parseDate(q.dateFrom)!, lte: parseDate(q.dateTo)! },
      ...(q.userId ? { userId: q.userId } : {}),
    } satisfies Prisma.AttendanceWhereInput;
  }

  async list(
    auth: AuthContext,
    q: z.output<typeof attendanceQuerySchema>,
  ): Promise<AttendanceDto[]> {
    const rows = await this.prisma.attendance.findMany({
      where: this.attendanceWhere(auth, q),
      include: attendanceInclude,
      orderBy: [{ date: 'desc' }, { user: { fullName: 'asc' } }],
      take: 2000,
    });
    return rows.map(toAttendance);
  }

  async summary(
    auth: AuthContext,
    q: z.output<typeof attendanceQuerySchema>,
  ): Promise<AttendanceSummaryDto[]> {
    const [users, rows] = await Promise.all([
      this.prisma.user.findMany({
        where: {
          AND: [this.kpi.usersWhere(auth, 'attendance.read'), { status: 'ACTIVE' }],
          ...(q.userId ? { id: q.userId } : {}),
        },
        select: { id: true, fullName: true },
        orderBy: { fullName: 'asc' },
      }),
      this.prisma.attendance.findMany({ where: this.attendanceWhere(auth, q) }),
    ]);
    return users.map((u) => {
      const mine = rows.filter((r) => r.userId === u.id);
      const count = (s: string) => mine.filter((r) => r.status === s).length;
      return {
        user: named(u)!,
        present: count('PRESENT') + count('LATE'),
        late: count('LATE'),
        lateMinutes: mine.reduce((s, r) => s + r.lateMinutes, 0),
        absent: count('ABSENT'),
        dayOff: count('DAY_OFF'),
        vacation: count('VACATION'),
        sick: count('SICK'),
        workHours: (mine.reduce((s, r) => s + r.workMinutes, 0) / 60).toFixed(1),
      };
    });
  }

  /** Внесение/исправление записи HR или CEO (отпуск, больничный, забыл отметиться). */
  async upsertAttendance(
    auth: AuthContext,
    input: z.output<typeof upsertAttendanceSchema>,
    meta: RequestMeta,
  ): Promise<AttendanceDto> {
    const user = await this.prisma.user.findFirst({
      where: { AND: [this.kpi.usersWhere(auth, 'attendance.manage'), { id: input.userId }] },
    });
    if (!user) throw notFound('Сотрудник');
    const present = input.status === 'PRESENT' || input.status === 'LATE';
    const checkIn = present && input.checkIn ? atTashkent(input.date, input.checkIn) : null;
    const checkOut = present && input.checkOut ? atTashkent(input.date, input.checkOut) : null;
    if (checkIn && checkOut && checkOut <= checkIn) throw businessRule('Уход раньше прихода');
    let status = input.status;
    let late = 0;
    if (present && checkIn) {
      const schedule = await this.scheduleOf(input.userId);
      if (schedule && schedule.workDays.includes(isoWeekday(input.date)))
        late = lateMinutes(checkIn, input.date, schedule.startTime, schedule.graceMinutes);
      status = late > 0 ? 'LATE' : 'PRESENT';
    }
    const key = { userId_date: { userId: input.userId, date: parseDate(input.date)! } };
    const before = await this.prisma.attendance.findUnique({ where: key });
    const data = {
      status,
      checkIn,
      checkOut,
      lateMinutes: late,
      workMinutes: workMinutes(checkIn, checkOut),
      comment: input.comment ?? null,
      editedById: auth.userId,
    };
    return this.prisma.$transaction(async (tx) => {
      const row = await tx.attendance.upsert({
        where: key,
        update: data,
        create: { userId: input.userId, date: parseDate(input.date)!, ...data },
        include: attendanceInclude,
      });
      await this.audit.log(tx, {
        actorId: auth.userId,
        action: 'attendance.update',
        entityType: 'attendance',
        entityId: row.id,
        changes: {
          status: { old: before?.status ?? null, new: status },
          checkIn: {
            old: before?.checkIn ? tashkentTime(before.checkIn) : null,
            new: input.checkIn ?? null,
          },
          checkOut: {
            old: before?.checkOut ? tashkentTime(before.checkOut) : null,
            new: input.checkOut ?? null,
          },
        },
        meta,
      });
      return toAttendance(row);
    });
  }

  // ─────────────────────────── Зарплата (§32) ───────────────────────────

  /** Свою зарплату видит каждый, всех — только CEO (решение заказчика). */
  async payroll(auth: AuthContext, period: string): Promise<PayrollEntryDto[]> {
    const all = auth.permissions['payroll.read'] === 'ALL';
    if (!auth.permissions['payroll.read']) throw forbidden();
    const rows = await this.prisma.payrollEntry.findMany({
      where: { period, ...(all ? {} : { userId: auth.userId }) },
      include: payrollInclude,
      orderBy: { user: { fullName: 'asc' } },
    });
    return rows.map(toPayroll);
  }

  private async commissionOf(userId: string, period: string) {
    const c = await this.prisma.commission.aggregate({
      where: { userId, period, status: { in: ['APPROVED', 'PAID'] } },
      _sum: { amountUzs: true },
    });
    return c._sum.amountUzs ?? new Prisma.Decimal(0);
  }

  /**
   * Расчёт месяца: строки-черновики для всех активных сотрудников (кроме CEO). Комиссия — сумма утверждённых
   * и выплаченных комиссий месяца; оклад — из карточки; бонусы и штрафы вносятся вручную
   * и при пересчёте сохраняются. Утверждённые строки не пересчитываются.
   */
  async calculate(
    auth: AuthContext,
    period: string,
    meta: RequestMeta,
  ): Promise<PayrollEntryDto[]> {
    const users = await this.prisma.user.findMany({
      // Владелец (CEO) в ведомость не входит; остальные — даже без оклада, чтобы его можно было внести.
      where: { deletedAt: null, status: 'ACTIVE', role: { code: { not: 'CEO' } } },
      include: { employee: true },
    });
    const kpi = await this.kpi.rows(auth, period, monthRange(period), { code: 'payroll.manage' });
    const kpiOf = new Map(kpi.map((r) => [r.user.id, r.kpiPct]));
    await this.prisma.$transaction(async (tx) => {
      for (const u of users) {
        const existing = await tx.payrollEntry.findUnique({
          where: { userId_period: { userId: u.id, period } },
        });
        if (existing && existing.status !== 'DRAFT') continue;
        const commission = await this.commissionOf(u.id, period);
        const base = existing?.baseSalary ?? u.employee?.baseSalary ?? new Prisma.Decimal(0);
        const parts = {
          baseSalary: base,
          kpiBonus: existing?.kpiBonus ?? new Prisma.Decimal(0),
          commission,
          otherBonus: existing?.otherBonus ?? new Prisma.Decimal(0),
          penalty: existing?.penalty ?? new Prisma.Decimal(0),
        };
        const data = {
          ...parts,
          finalSalary: finalSalary({
            baseSalary: parts.baseSalary.toString(),
            kpiBonus: parts.kpiBonus.toString(),
            commission: parts.commission.toString(),
            otherBonus: parts.otherBonus.toString(),
            penalty: parts.penalty.toString(),
          }),
          kpiPct: kpiOf.get(u.id) ?? null,
        };
        await tx.payrollEntry.upsert({
          where: { userId_period: { userId: u.id, period } },
          update: data,
          create: { userId: u.id, period, ...data },
        });
      }
      await this.audit.log(tx, {
        actorId: auth.userId,
        action: 'payroll.calculate',
        entityType: 'payroll',
        entityId: null,
        changes: { period: { old: null, new: period } },
        meta,
      });
    });
    return this.payroll(auth, period);
  }

  async updatePayroll(
    auth: AuthContext,
    id: string,
    input: z.output<typeof updatePayrollSchema>,
    meta: RequestMeta,
  ): Promise<PayrollEntryDto> {
    const e = await this.prisma.payrollEntry.findUnique({ where: { id } });
    if (!e) throw notFound('Начисление');
    if (e.status !== 'DRAFT') throw businessRule('Утверждённую зарплату изменить нельзя');
    const parts = {
      baseSalary: input.baseSalary ?? e.baseSalary.toFixed(2),
      kpiBonus: input.kpiBonus ?? e.kpiBonus.toFixed(2),
      commission: e.commission.toFixed(2),
      otherBonus: input.otherBonus ?? e.otherBonus.toFixed(2),
      penalty: input.penalty ?? e.penalty.toFixed(2),
    };
    return this.prisma.$transaction(async (tx) => {
      const row = await tx.payrollEntry.update({
        where: { id },
        data: {
          baseSalary: parts.baseSalary,
          kpiBonus: parts.kpiBonus,
          otherBonus: parts.otherBonus,
          penalty: parts.penalty,
          finalSalary: finalSalary(parts),
          comment: input.comment === undefined ? undefined : input.comment,
        },
        include: payrollInclude,
      });
      if (input.saveBaseSalary && input.baseSalary !== undefined)
        await tx.employee.upsert({
          where: { userId: e.userId },
          update: { baseSalary: input.baseSalary },
          create: { userId: e.userId, baseSalary: input.baseSalary },
        });
      await this.audit.log(tx, {
        actorId: auth.userId,
        action: 'payroll.update',
        entityType: 'payroll',
        entityId: id,
        changes: {
          baseSalary: { old: e.baseSalary.toFixed(2), new: Number(parts.baseSalary).toFixed(2) },
          kpiBonus: { old: e.kpiBonus.toFixed(2), new: Number(parts.kpiBonus).toFixed(2) },
          otherBonus: { old: e.otherBonus.toFixed(2), new: Number(parts.otherBonus).toFixed(2) },
          penalty: { old: e.penalty.toFixed(2), new: Number(parts.penalty).toFixed(2) },
        },
        meta,
      });
      return toPayroll(row);
    });
  }

  async payrollTransition(
    auth: AuthContext,
    ids: string[],
    to: 'APPROVED' | 'PAID',
    meta: RequestMeta,
  ): Promise<{ updated: number }> {
    const from = to === 'APPROVED' ? 'DRAFT' : 'APPROVED';
    return this.prisma.$transaction(async (tx) => {
      const rows = await tx.payrollEntry.findMany({ where: { id: { in: ids } } });
      if (rows.length !== ids.length) throw notFound('Начисление');
      if (rows.some((r) => r.status !== from))
        throw businessRule(
          to === 'APPROVED'
            ? 'Утвердить можно только черновики'
            : 'Выплатить можно только утверждённую зарплату',
        );
      const now = new Date();
      await tx.payrollEntry.updateMany({
        where: { id: { in: ids } },
        data:
          to === 'APPROVED'
            ? { status: to, approvedById: auth.userId, approvedAt: now }
            : { status: to, paidAt: now },
      });
      for (const r of rows)
        await this.audit.log(tx, {
          actorId: auth.userId,
          action: to === 'APPROVED' ? 'payroll.approve' : 'payroll.pay',
          entityType: 'payroll',
          entityId: r.id,
          changes: {
            status: { old: from, new: to },
            finalSalary: { old: null, new: r.finalSalary.toFixed(2) },
          },
          meta,
        });
      return { updated: rows.length };
    });
  }
}

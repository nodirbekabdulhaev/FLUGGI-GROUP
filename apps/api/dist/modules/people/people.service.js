"use strict";
var __decorate = (this && this.__decorate) || function (decorators, target, key, desc) {
    var c = arguments.length, r = c < 3 ? target : desc === null ? desc = Object.getOwnPropertyDescriptor(target, key) : desc, d;
    if (typeof Reflect === "object" && typeof Reflect.decorate === "function") r = Reflect.decorate(decorators, target, key, desc);
    else for (var i = decorators.length - 1; i >= 0; i--) if (d = decorators[i]) r = (c < 3 ? d(r) : c > 3 ? d(target, key, r) : d(target, key)) || r;
    return c > 3 && r && Object.defineProperty(target, key, r), r;
};
var __metadata = (this && this.__metadata) || function (k, v) {
    if (typeof Reflect === "object" && typeof Reflect.metadata === "function") return Reflect.metadata(k, v);
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.PeopleService = void 0;
const common_1 = require("@nestjs/common");
const contracts_1 = require("@fluggi/contracts");
const domain_1 = require("@fluggi/domain");
const db_1 = require("@fluggi/db");
const audit_service_1 = require("../../core/audit/audit.service");
const app_exception_1 = require("../../core/http/app.exception");
const serialize_1 = require("../../core/http/serialize");
const prisma_service_1 = require("../../core/prisma/prisma.service");
const kpi_service_1 = require("./kpi.service");
const named = (u) => u ? { id: u.id, name: u.fullName } : null;
const attendanceInclude = {
    user: { select: { id: true, fullName: true } },
    editedBy: { select: { id: true, fullName: true } },
};
const toAttendance = (a) => ({
    id: a.id,
    user: named(a.user),
    date: (0, serialize_1.dateOnly)(a.date),
    checkIn: a.checkIn ? (0, domain_1.tashkentTime)(a.checkIn) : null,
    checkOut: a.checkOut ? (0, domain_1.tashkentTime)(a.checkOut) : null,
    status: a.status,
    lateMinutes: a.lateMinutes,
    workMinutes: a.workMinutes,
    comment: a.comment,
    editedBy: named(a.editedBy),
});
const payrollInclude = {
    user: {
        select: {
            id: true,
            fullName: true,
            role: { select: { code: true } },
            employee: { select: { kpiBonusTarget: true } },
        },
    },
    approvedBy: { select: { id: true, fullName: true } },
};
const toPayroll = (p) => ({
    id: p.id,
    user: named(p.user),
    role: p.user.role.code,
    period: p.period,
    baseSalary: p.baseSalary.toFixed(2),
    pieceRate: p.pieceRate.toFixed(2),
    kpiBonus: p.kpiBonus.toFixed(2),
    commission: p.commission.toFixed(2),
    otherBonus: p.otherBonus.toFixed(2),
    penalty: p.penalty.toFixed(2),
    finalSalary: p.finalSalary.toFixed(2),
    kpiPct: p.kpiPct?.toFixed(2) ?? null,
    kpiBonusTarget: p.user.employee?.kpiBonusTarget?.toFixed(2) ?? null,
    status: p.status,
    comment: p.comment,
    approvedBy: named(p.approvedBy),
    approvedAt: p.approvedAt?.toISOString() ?? null,
    paidAt: p.paidAt?.toISOString() ?? null,
});
/** Цели KPI, графики, посещаемость и зарплата (ТЗ §31, §32, §35, §36). */
let PeopleService = class PeopleService {
    prisma;
    kpi;
    audit;
    constructor(prisma, kpi, audit) {
        this.prisma = prisma;
        this.kpi = kpi;
        this.audit = audit;
    }
    // ─────────────────────────── Цели (§31) ───────────────────────────
    /** CEO/HR — любому сотруднику; РОП — сотрудникам своего отдела, но не себе. */
    async setTargets(auth, input, meta) {
        const scope = auth.permissions['kpi.target.manage'];
        if (!scope)
            throw (0, app_exception_1.forbidden)();
        if (scope !== 'ALL' && input.userId === auth.userId)
            throw (0, app_exception_1.forbidden)('Свои цели устанавливает руководитель');
        const user = await this.prisma.user.findFirst({
            where: { AND: [this.kpi.usersWhere(auth, 'kpi.target.manage'), { id: input.userId }] },
        });
        if (!user)
            throw (0, app_exception_1.notFound)('Сотрудник');
        await this.prisma.$transaction(async (tx) => {
            for (const t of input.targets) {
                const key = {
                    userId_period_metric: { userId: input.userId, period: input.period, metric: t.metric },
                };
                const before = await tx.kpiTarget.findUnique({ where: key });
                if (t.value === null) {
                    if (before)
                        await tx.kpiTarget.delete({ where: key });
                }
                else {
                    if (!/^\d{1,16}(\.\d{1,2})?$/.test(t.value))
                        throw (0, app_exception_1.businessRule)('Некорректное значение цели');
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
                if ((before?.targetValue.toFixed(2) ?? null) !==
                    (t.value === null ? null : Number(t.value).toFixed(2)))
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
    async schedules() {
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
            users: s.employees.map((e) => named(e.user)),
        }));
    }
    async upsertSchedule(auth, id, input, meta) {
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
                if (clash)
                    throw (0, app_exception_1.conflict)(`У роли уже есть график «${clash.name}»`);
            }
            const before = id ? await tx.workSchedule.findUnique({ where: { id } }) : null;
            if (id && !before)
                throw (0, app_exception_1.notFound)('График');
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
    async scheduleOf(userId) {
        const user = await this.prisma.user.findUniqueOrThrow({
            where: { id: userId },
            include: { role: true, employee: { include: { schedule: true } } },
        });
        const own = user.employee?.schedule;
        if (own?.isActive)
            return own;
        return this.prisma.workSchedule.findFirst({
            where: { roleCode: user.role.code, isActive: true },
        });
    }
    // ─────────────────────────── Посещаемость (§35) ───────────────────────────
    async today(auth) {
        const date = (0, domain_1.companyDate)(new Date());
        const [schedule, record] = await Promise.all([
            this.scheduleOf(auth.userId),
            this.prisma.attendance.findUnique({
                where: { userId_date: { userId: auth.userId, date: (0, serialize_1.parseDate)(date) } },
                include: attendanceInclude,
            }),
        ]);
        return {
            tracked: (0, contracts_1.tracksAttendance)(auth.roleCode),
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
            workday: schedule ? schedule.workDays.includes((0, domain_1.isoWeekday)(date)) : true,
            record: record ? toAttendance(record) : null,
        };
    }
    /** Отметка прихода: опоздание считается по графику (ТЗ §35–36). */
    assertTracked(role) {
        if (!(0, contracts_1.tracksAttendance)(role))
            throw (0, app_exception_1.businessRule)('Приход отмечают только менеджеры и РОП');
    }
    async checkIn(auth, comment) {
        this.assertTracked(auth.roleCode);
        const now = new Date();
        const date = (0, domain_1.companyDate)(now);
        const key = { userId_date: { userId: auth.userId, date: (0, serialize_1.parseDate)(date) } };
        const existing = await this.prisma.attendance.findUnique({ where: key });
        if (existing?.checkIn)
            throw (0, app_exception_1.conflict)('Приход уже отмечен');
        if (existing && ['VACATION', 'SICK', 'DAY_OFF'].includes(existing.status))
            throw (0, app_exception_1.businessRule)('На сегодня оформлен отпуск, больничный или выходной');
        const schedule = await this.scheduleOf(auth.userId);
        const late = schedule && schedule.workDays.includes((0, domain_1.isoWeekday)(date))
            ? (0, domain_1.lateMinutes)(now, date, schedule.startTime, schedule.graceMinutes)
            : 0;
        const data = {
            checkIn: now,
            status: late > 0 ? 'LATE' : 'PRESENT',
            lateMinutes: late,
            comment: comment ?? existing?.comment ?? null,
        };
        const row = await this.prisma.attendance.upsert({
            where: key,
            update: data,
            create: { userId: auth.userId, date: (0, serialize_1.parseDate)(date), ...data },
            include: attendanceInclude,
        });
        return toAttendance(row);
    }
    async checkOut(auth, comment) {
        this.assertTracked(auth.roleCode);
        const now = new Date();
        const date = (0, domain_1.companyDate)(now);
        const key = { userId_date: { userId: auth.userId, date: (0, serialize_1.parseDate)(date) } };
        const existing = await this.prisma.attendance.findUnique({ where: key });
        if (!existing?.checkIn)
            throw (0, app_exception_1.businessRule)('Сначала отметьте приход');
        if (existing.checkOut)
            throw (0, app_exception_1.conflict)('Уход уже отмечен');
        const row = await this.prisma.attendance.update({
            where: key,
            data: {
                checkOut: now,
                workMinutes: (0, domain_1.workMinutes)(existing.checkIn, now),
                comment: comment ?? existing.comment,
            },
            include: attendanceInclude,
        });
        return toAttendance(row);
    }
    attendanceWhere(auth, q) {
        return {
            user: {
                AND: [
                    this.kpi.usersWhere(auth, 'attendance.read'),
                    { role: { code: { in: [...contracts_1.ATTENDANCE_ROLES] } } },
                ],
            },
            date: { gte: (0, serialize_1.parseDate)(q.dateFrom), lte: (0, serialize_1.parseDate)(q.dateTo) },
            ...(q.userId ? { userId: q.userId } : {}),
        };
    }
    async list(auth, q) {
        const rows = await this.prisma.attendance.findMany({
            where: this.attendanceWhere(auth, q),
            include: attendanceInclude,
            orderBy: [{ date: 'desc' }, { user: { fullName: 'asc' } }],
            take: 2000,
        });
        return rows.map(toAttendance);
    }
    async summary(auth, q) {
        const [users, rows] = await Promise.all([
            this.prisma.user.findMany({
                where: {
                    AND: [
                        this.kpi.usersWhere(auth, 'attendance.read'),
                        { status: 'ACTIVE', role: { code: { in: [...contracts_1.ATTENDANCE_ROLES] } } },
                    ],
                    ...(q.userId ? { id: q.userId } : {}),
                },
                select: { id: true, fullName: true },
                orderBy: { fullName: 'asc' },
            }),
            this.prisma.attendance.findMany({ where: this.attendanceWhere(auth, q) }),
        ]);
        return users.map((u) => {
            const mine = rows.filter((r) => r.userId === u.id);
            const count = (s) => mine.filter((r) => r.status === s).length;
            return {
                user: named(u),
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
    async upsertAttendance(auth, input, meta) {
        const user = await this.prisma.user.findFirst({
            where: { AND: [this.kpi.usersWhere(auth, 'attendance.manage'), { id: input.userId }] },
            include: { role: true },
        });
        if (!user)
            throw (0, app_exception_1.notFound)('Сотрудник');
        this.assertTracked(user.role.code);
        const present = input.status === 'PRESENT' || input.status === 'LATE';
        const checkIn = present && input.checkIn ? (0, domain_1.atTashkent)(input.date, input.checkIn) : null;
        const checkOut = present && input.checkOut ? (0, domain_1.atTashkent)(input.date, input.checkOut) : null;
        if (checkIn && checkOut && checkOut <= checkIn)
            throw (0, app_exception_1.businessRule)('Уход раньше прихода');
        let status = input.status;
        let late = 0;
        if (present && checkIn) {
            const schedule = await this.scheduleOf(input.userId);
            if (schedule && schedule.workDays.includes((0, domain_1.isoWeekday)(input.date)))
                late = (0, domain_1.lateMinutes)(checkIn, input.date, schedule.startTime, schedule.graceMinutes);
            status = late > 0 ? 'LATE' : 'PRESENT';
        }
        const key = { userId_date: { userId: input.userId, date: (0, serialize_1.parseDate)(input.date) } };
        const before = await this.prisma.attendance.findUnique({ where: key });
        const data = {
            status,
            checkIn,
            checkOut,
            lateMinutes: late,
            workMinutes: (0, domain_1.workMinutes)(checkIn, checkOut),
            comment: input.comment ?? null,
            editedById: auth.userId,
        };
        return this.prisma.$transaction(async (tx) => {
            const row = await tx.attendance.upsert({
                where: key,
                update: data,
                create: { userId: input.userId, date: (0, serialize_1.parseDate)(input.date), ...data },
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
                        old: before?.checkIn ? (0, domain_1.tashkentTime)(before.checkIn) : null,
                        new: input.checkIn ?? null,
                    },
                    checkOut: {
                        old: before?.checkOut ? (0, domain_1.tashkentTime)(before.checkOut) : null,
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
    async payroll(auth, period) {
        const all = auth.permissions['payroll.read'] === 'ALL';
        if (!auth.permissions['payroll.read'])
            throw (0, app_exception_1.forbidden)();
        const rows = await this.prisma.payrollEntry.findMany({
            where: { period, ...(all ? {} : { userId: auth.userId }) },
            include: payrollInclude,
            orderBy: { user: { fullName: 'asc' } },
        });
        return rows.map(toPayroll);
    }
    async commissionOf(userId, period) {
        const c = await this.prisma.commission.aggregate({
            where: { userId, period, status: { in: ['APPROVED', 'PAID'] } },
            _sum: { amountUzs: true },
        });
        return c._sum.amountUzs ?? new db_1.Prisma.Decimal(0);
    }
    /**
     * Расчёт месяца: строки-черновики для всех активных сотрудников (кроме CEO). Комиссия — сумма утверждённых
     * и выплаченных комиссий месяца; оклад — из карточки; бонусы и штрафы вносятся вручную
     * и при пересчёте сохраняются. Утверждённые строки не пересчитываются.
     */
    async calculate(auth, period, meta) {
        const users = await this.prisma.user.findMany({
            // Владелец (CEO) в ведомость не входит; остальные — даже без оклада, чтобы его можно было внести.
            where: { deletedAt: null, status: 'ACTIVE', role: { code: { not: 'CEO' } } },
            include: { employee: true, role: true },
        });
        const kpi = await this.kpi.rows(auth, period, (0, domain_1.monthRange)(period), { code: 'payroll.manage' });
        // Сдельно: начисления исполнителям по проектам (расходы «Исполнитель» с получателем) за месяц
        const range = (0, domain_1.monthRange)(period);
        const piece = await this.prisma.expense.groupBy({
            by: ['payeeUserId'],
            where: {
                deletedAt: null,
                category: 'EXECUTOR',
                payeeUserId: { not: null },
                expenseDate: { gte: range.from, lt: range.to },
            },
            _sum: { amountUzs: true },
        });
        const pieceOf = new Map(piece.map((p) => [p.payeeUserId, p._sum.amountUzs ?? new db_1.Prisma.Decimal(0)]));
        const kpiOf = new Map(kpi.map((r) => [r.user.id, r.kpiPct]));
        await this.prisma.$transaction(async (tx) => {
            for (const u of users) {
                const existing = await tx.payrollEntry.findUnique({
                    where: { userId_period: { userId: u.id, period } },
                });
                if (existing && existing.status !== 'DRAFT')
                    continue;
                const commission = await this.commissionOf(u.id, period);
                // Фиксированный оклад — только у менеджеров и РОП
                const base = (0, contracts_1.hasFixedSalary)(u.role.code)
                    ? (existing?.baseSalary ?? u.employee?.baseSalary ?? new db_1.Prisma.Decimal(0))
                    : new db_1.Prisma.Decimal(0);
                const pieceRate = pieceOf.get(u.id) ?? new db_1.Prisma.Decimal(0);
                // KPI-бонус: из «бонуса при 100%» × выполнение KPI; без него — сохраняется ручная сумма
                const target = u.employee?.kpiBonusTarget ?? null;
                const pct = kpiOf.get(u.id) ?? null;
                const parts = {
                    baseSalary: base,
                    pieceRate,
                    kpiBonus: target
                        ? new db_1.Prisma.Decimal((0, domain_1.kpiBonusFor)(target.toString(), pct))
                        : (existing?.kpiBonus ?? new db_1.Prisma.Decimal(0)),
                    commission,
                    otherBonus: existing?.otherBonus ?? new db_1.Prisma.Decimal(0),
                    penalty: existing?.penalty ?? new db_1.Prisma.Decimal(0),
                };
                const data = {
                    ...parts,
                    finalSalary: (0, domain_1.finalSalary)({
                        baseSalary: parts.baseSalary.toString(),
                        pieceRate: parts.pieceRate.toString(),
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
    async updatePayroll(auth, id, input, meta) {
        const e = await this.prisma.payrollEntry.findUnique({
            where: { id },
            include: { user: { include: { role: true } } },
        });
        if (!e)
            throw (0, app_exception_1.notFound)('Начисление');
        if (e.status !== 'DRAFT')
            throw (0, app_exception_1.businessRule)('Утверждённую зарплату изменить нельзя');
        if (input.baseSalary !== undefined &&
            Number(input.baseSalary) !== 0 &&
            !(0, contracts_1.hasFixedSalary)(e.user.role.code))
            throw (0, app_exception_1.businessRule)('Фиксированный оклад — только у менеджеров и РОП', [
                { path: 'baseSalary', message: 'У этой роли оклада нет: сдельно, бонусы, KPI' },
            ]);
        const parts = {
            baseSalary: input.baseSalary ?? e.baseSalary.toFixed(2),
            pieceRate: e.pieceRate.toFixed(2),
            // Новый «бонус при 100%» сразу пересчитывает KPI-бонус месяца (если сумму не ввели вручную)
            kpiBonus: input.kpiBonus ??
                (input.kpiBonusTarget !== undefined && input.kpiBonusTarget !== null
                    ? (0, domain_1.kpiBonusFor)(input.kpiBonusTarget, e.kpiPct?.toString() ?? null)
                    : e.kpiBonus.toFixed(2)),
            commission: e.commission.toFixed(2),
            otherBonus: input.otherBonus ?? e.otherBonus.toFixed(2),
            penalty: input.penalty ?? e.penalty.toFixed(2),
        };
        return this.prisma.$transaction(async (tx) => {
            if (input.kpiBonusTarget !== undefined)
                await tx.employee.upsert({
                    where: { userId: e.userId },
                    update: { kpiBonusTarget: input.kpiBonusTarget },
                    create: { userId: e.userId, kpiBonusTarget: input.kpiBonusTarget },
                });
            const row = await tx.payrollEntry.update({
                where: { id },
                data: {
                    baseSalary: parts.baseSalary,
                    kpiBonus: parts.kpiBonus,
                    otherBonus: parts.otherBonus,
                    penalty: parts.penalty,
                    finalSalary: (0, domain_1.finalSalary)(parts),
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
    async payrollTransition(auth, ids, to, meta) {
        const from = to === 'APPROVED' ? 'DRAFT' : 'APPROVED';
        return this.prisma.$transaction(async (tx) => {
            const rows = await tx.payrollEntry.findMany({ where: { id: { in: ids } } });
            if (rows.length !== ids.length)
                throw (0, app_exception_1.notFound)('Начисление');
            if (rows.some((r) => r.status !== from))
                throw (0, app_exception_1.businessRule)(to === 'APPROVED'
                    ? 'Утвердить можно только черновики'
                    : 'Выплатить можно только утверждённую зарплату');
            const now = new Date();
            await tx.payrollEntry.updateMany({
                where: { id: { in: ids } },
                data: to === 'APPROVED'
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
};
exports.PeopleService = PeopleService;
exports.PeopleService = PeopleService = __decorate([
    (0, common_1.Injectable)(),
    __metadata("design:paramtypes", [prisma_service_1.PrismaService,
        kpi_service_1.KpiService,
        audit_service_1.AuditService])
], PeopleService);
//# sourceMappingURL=people.service.js.map
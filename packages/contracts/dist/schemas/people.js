"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.payrollIdsSchema = exports.updatePayrollSchema = exports.hasFixedSalary = exports.tracksAttendance = exports.FIXED_SALARY_ROLES = exports.ATTENDANCE_ROLES = exports.checkSchema = exports.upsertAttendanceSchema = exports.attendanceQuerySchema = exports.upsertScheduleSchema = exports.setTargetsSchema = exports.kpiQuerySchema = exports.KPI_GROUPS = exports.monthSchema = void 0;
const zod_1 = require("zod");
const enums_1 = require("../enums");
const fields_1 = require("./fields");
exports.monthSchema = zod_1.z.string().regex(/^\d{4}-(0[1-9]|1[0-2])$/, 'Период в формате ГГГГ-ММ');
// ─────────────────────────── KPI (ТЗ §28–31) ───────────────────────────
exports.KPI_GROUPS = ['MANAGER', 'ROP', 'EXECUTOR'];
exports.kpiQuerySchema = zod_1.z.object({
    period: exports.monthSchema,
    group: zod_1.z.enum(exports.KPI_GROUPS).optional(),
});
exports.setTargetsSchema = zod_1.z.object({
    userId: zod_1.z.uuid(),
    period: exports.monthSchema,
    targets: zod_1.z
        .array(zod_1.z.object({
        metric: zod_1.z.enum(enums_1.KPI_METRICS),
        /** null/пусто — удалить цель */
        value: zod_1.z
            .union([zod_1.z.string(), zod_1.z.number(), zod_1.z.null()])
            .transform((v) => (v === null || v === '' ? null : String(v).replace(/\s/g, ''))),
        currency: zod_1.z.enum(enums_1.CURRENCIES).default('UZS'),
    }))
        .max(enums_1.KPI_METRICS.length),
});
// ─────────────────────────── Графики (ТЗ §36) ───────────────────────────
const hhmm = zod_1.z.string().regex(/^([01]\d|2[0-3]):[0-5]\d$/, 'Время в формате ЧЧ:ММ');
exports.upsertScheduleSchema = zod_1.z
    .object({
    name: zod_1.z.string().trim().min(1, 'Укажите название').max(100),
    roleCode: zod_1.z.enum(enums_1.ROLE_CODES).nullish(),
    startTime: hhmm,
    endTime: hhmm,
    workDays: zod_1.z.array(zod_1.z.number().int().min(1).max(7)).min(1, 'Выберите рабочие дни').max(7),
    graceMinutes: zod_1.z.coerce.number().int().min(0).max(180).default(0),
    isActive: zod_1.z.boolean().default(true),
    /** Сотрудники с индивидуальным графиком */
    userIds: zod_1.z.array(zod_1.z.uuid()).max(500).default([]),
})
    .refine((v) => v.startTime < v.endTime, {
    message: 'Конец раньше начала',
    path: ['endTime'],
});
// ─────────────────────────── Посещаемость (ТЗ §35) ───────────────────────────
exports.attendanceQuerySchema = zod_1.z.object({
    dateFrom: fields_1.dateOnly,
    dateTo: fields_1.dateOnly,
    userId: zod_1.z.uuid().optional(),
});
exports.upsertAttendanceSchema = zod_1.z.object({
    userId: zod_1.z.uuid(),
    date: fields_1.dateOnly,
    status: zod_1.z.enum(enums_1.ATTENDANCE_STATUSES),
    /** HH:MM по Ташкенту */
    checkIn: hhmm.nullish(),
    checkOut: hhmm.nullish(),
    comment: zod_1.z.string().trim().max(500).nullish(),
});
exports.checkSchema = zod_1.z.object({ comment: zod_1.z.string().trim().max(500).optional() });
/**
 * Посещаемость и фиксированный оклад — только у менеджеров и РОП. Исполнители получают
 * сдельно (начисления по проектам), CEO и проект-менеджер приход не отмечают.
 */
exports.ATTENDANCE_ROLES = ['MANAGER', 'ROP'];
exports.FIXED_SALARY_ROLES = ['MANAGER', 'ROP'];
const tracksAttendance = (role) => exports.ATTENDANCE_ROLES.includes(role);
exports.tracksAttendance = tracksAttendance;
const hasFixedSalary = (role) => exports.FIXED_SALARY_ROLES.includes(role);
exports.hasFixedSalary = hasFixedSalary;
// ─────────────────────────── Зарплата (ТЗ §32) ───────────────────────────
exports.updatePayrollSchema = zod_1.z.object({
    baseSalary: fields_1.moneySchema.optional(),
    kpiBonus: fields_1.moneySchema.optional(),
    otherBonus: fields_1.moneySchema.optional(),
    penalty: fields_1.moneySchema.optional(),
    comment: zod_1.z.string().trim().max(1000).nullable().optional(),
    /** Сохранить оклад как постоянный для следующих месяцев */
    saveBaseSalary: zod_1.z.boolean().optional(),
    /** KPI-бонус при 100% выполнения (постоянно, в карточке сотрудника); null — убрать */
    kpiBonusTarget: fields_1.moneySchema.nullable().optional(),
});
exports.payrollIdsSchema = zod_1.z.object({ ids: zod_1.z.array(zod_1.z.uuid()).min(1).max(500) });
//# sourceMappingURL=people.js.map
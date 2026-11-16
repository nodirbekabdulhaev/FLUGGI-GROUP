"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.KPI_BONUS_CAP_PCT = void 0;
exports.minutesOf = minutesOf;
exports.tashkentTime = tashkentTime;
exports.isoWeekday = isoWeekday;
exports.atTashkent = atTashkent;
exports.lateMinutes = lateMinutes;
exports.workMinutes = workMinutes;
exports.completionPct = completionPct;
exports.averagePct = averagePct;
exports.finalSalary = finalSalary;
exports.kpiBonusFor = kpiBonusFor;
exports.monthRange = monthRange;
const decimal_js_1 = __importDefault(require("decimal.js"));
/**
 * Посещаемость, KPI и зарплата (ТЗ §31, §32, §35). Время — по Ташкенту (UTC+5).
 */
const TZ_MS = 5 * 3_600_000;
/** «09:30» → минуты от полуночи. */
function minutesOf(hhmm) {
    const [h = 0, m = 0] = hhmm.split(':').map(Number);
    return h * 60 + m;
}
/** Момент → «HH:MM» по Ташкенту. */
function tashkentTime(at) {
    return new Date(at.getTime() + TZ_MS).toISOString().slice(11, 16);
}
/** День недели ISO (1 — понедельник … 7 — воскресенье) для даты YYYY-MM-DD. */
function isoWeekday(date) {
    const d = new Date(`${date}T00:00:00Z`).getUTCDay();
    return d === 0 ? 7 : d;
}
/** Дата YYYY-MM-DD + «HH:MM» по Ташкенту → момент UTC. */
function atTashkent(date, hhmm) {
    return new Date(Date.parse(`${date}T${hhmm}:00Z`) - TZ_MS);
}
/**
 * Опоздание: приход позже начала графика больше чем на «льготные» минуты.
 * Опоздание считается от начала графика (а не от конца льготного окна).
 */
function lateMinutes(checkIn, date, start, grace) {
    const late = Math.floor((checkIn.getTime() - atTashkent(date, start).getTime()) / 60_000);
    return late > grace ? late : 0;
}
/** Отработано минут между приходом и уходом. */
function workMinutes(checkIn, checkOut) {
    if (!checkIn || !checkOut || checkOut <= checkIn)
        return 0;
    return Math.floor((checkOut.getTime() - checkIn.getTime()) / 60_000);
}
/** Выполнение цели, % (2 знака); null — цели нет или она нулевая. */
function completionPct(fact, target) {
    if (target === null)
        return null;
    const t = new decimal_js_1.default(target);
    if (t.lte(0))
        return null;
    return new decimal_js_1.default(fact).div(t).mul(100).toDecimalPlaces(2).toFixed(2);
}
/** Среднее по списку процентов (null-ы пропускаются). */
function averagePct(values) {
    const v = values.filter((x) => x !== null);
    if (v.length === 0)
        return null;
    return v
        .reduce((s, x) => s.add(x), new decimal_js_1.default(0))
        .div(v.length)
        .toDecimalPlaces(2)
        .toFixed(2);
}
/** Итоговая зарплата (ТЗ §32): оклад + сдельно + KPI-бонус + комиссия + прочие бонусы − штраф. */
function finalSalary(x) {
    return new decimal_js_1.default(x.baseSalary)
        .add(x.pieceRate ?? 0)
        .add(x.kpiBonus)
        .add(x.commission)
        .add(x.otherBonus)
        .sub(x.penalty)
        .toFixed(2);
}
/** Предел выполнения KPI для бонуса: перевыполнение оплачивается до 120%. */
exports.KPI_BONUS_CAP_PCT = 120;
/**
 * KPI-бонус за месяц: «бонус при 100%» × выполнение KPI (не больше 120%).
 * Нет целей (pct = null) или не задан бонус — 0.
 */
function kpiBonusFor(target, pct) {
    if (target === null || pct === null || Number(target) <= 0)
        return '0.00';
    const p = Math.min(Math.max(Number(pct), 0), exports.KPI_BONUS_CAP_PCT);
    return new decimal_js_1.default(target).mul(p).div(100).toDecimalPlaces(0).toFixed(2);
}
/** Границы месяца YYYY-MM по Ташкенту. */
function monthRange(period) {
    const [y, m] = period.split('-').map(Number);
    return {
        from: new Date(Date.UTC(y, m - 1, 1) - TZ_MS),
        to: new Date(Date.UTC(y, m, 1) - TZ_MS),
    };
}
//# sourceMappingURL=people.js.map
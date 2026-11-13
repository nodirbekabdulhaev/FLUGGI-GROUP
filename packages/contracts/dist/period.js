"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.PERIOD_PRESETS = void 0;
exports.resolvePeriod = resolvePeriod;
exports.resolveCustomPeriod = resolveCustomPeriod;
/**
 * Периоды для глобального фильтра CEO (Сегодня / Месяц / Квартал / Год / Свой).
 * Границы считаются в часовом поясе компании (Asia/Tashkent, UTC+5, без DST).
 */
exports.PERIOD_PRESETS = ['today', 'week', 'month', 'quarter', 'year', 'custom'];
const OFFSET_MS = 5 * 60 * 60 * 1000;
/** Полночь в Ташкенте для заданных компонент даты → UTC Date. */
function tashkentMidnight(year, monthIndex, day) {
    return new Date(Date.UTC(year, monthIndex, day) - OFFSET_MS);
}
function resolvePeriod(preset, now = new Date()) {
    const local = new Date(now.getTime() + OFFSET_MS);
    const y = local.getUTCFullYear();
    const m = local.getUTCMonth();
    const d = local.getUTCDate();
    switch (preset) {
        case 'today':
            return { from: tashkentMidnight(y, m, d), to: tashkentMidnight(y, m, d + 1) };
        case 'week': {
            // Неделя с понедельника (ISO).
            const weekday = (local.getUTCDay() + 6) % 7;
            return {
                from: tashkentMidnight(y, m, d - weekday),
                to: tashkentMidnight(y, m, d - weekday + 7),
            };
        }
        case 'month':
            return { from: tashkentMidnight(y, m, 1), to: tashkentMidnight(y, m + 1, 1) };
        case 'quarter': {
            const q = Math.floor(m / 3) * 3;
            return { from: tashkentMidnight(y, q, 1), to: tashkentMidnight(y, q + 3, 1) };
        }
        case 'year':
            return { from: tashkentMidnight(y, 0, 1), to: tashkentMidnight(y + 1, 0, 1) };
    }
}
/** Свой период по датам YYYY-MM-DD (обе включительно). */
function resolveCustomPeriod(fromDate, toDate) {
    const re = /^(\d{4})-(\d{2})-(\d{2})$/;
    const a = re.exec(fromDate);
    const b = re.exec(toDate);
    if (!a || !b)
        return null;
    const from = tashkentMidnight(Number(a[1]), Number(a[2]) - 1, Number(a[3]));
    const to = tashkentMidnight(Number(b[1]), Number(b[2]) - 1, Number(b[3]) + 1);
    return from < to ? { from, to } : null;
}
//# sourceMappingURL=period.js.map
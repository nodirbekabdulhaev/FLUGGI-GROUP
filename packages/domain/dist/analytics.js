"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.granularityFor = granularityFor;
exports.bucketKey = bucketKey;
exports.bucketKeys = bucketKeys;
exports.clientHealth = clientHealth;
const tasks_1 = require("./tasks");
const people_1 = require("./people");
const DAY_MS = 86_400_000;
/** Шаг графика по длине периода: до 45 дней — дни, до ~6 месяцев — недели, дальше — месяцы. */
function granularityFor(from, to) {
    const days = (to.getTime() - from.getTime()) / DAY_MS;
    if (days <= 45)
        return 'day';
    if (days <= 190)
        return 'week';
    return 'month';
}
/** Ключ корзины (дата по Ташкенту): день — YYYY-MM-DD, неделя — понедельник, месяц — YYYY-MM. */
function bucketKey(at, g) {
    const date = (0, tasks_1.companyDate)(at);
    if (g === 'day')
        return date;
    if (g === 'month')
        return date.slice(0, 7);
    return (0, tasks_1.addDays)(date, 1 - (0, people_1.isoWeekday)(date));
}
/** Все корзины периода [from, to) — чтобы на графике не было «дыр». */
function bucketKeys(from, to, g) {
    const keys = [];
    const last = (0, tasks_1.companyDate)(new Date(to.getTime() - 1));
    let date = (0, tasks_1.companyDate)(from);
    while (date <= last) {
        const k = bucketKey(new Date(`${date}T12:00:00+05:00`), g);
        if (keys[keys.length - 1] !== k)
            keys.push(k);
        date = (0, tasks_1.addDays)(date, 1);
    }
    return keys;
}
/**
 * Оценка «здоровья» клиента 0–100 (ТЗ §43). Правила прозрачные — причины видны в карточке:
 *  − нет контакта 30 / 60 / 90 дней: −20 / −40 / −65
 *  − просроченная оплата: −25 за каждую (до −50); просроченный проект: −15
 *  − проигранная сделка за 90 дней: −10
 *  + повторные покупки (2+ оплаченные сделки): +10; проект в работе: +10
 * ≥70 — «Здоров», 40–69 — «Внимание», <40 — «Риск».
 * «Потерян» — нет контакта 180+ дней и ничего в работе.
 */
function clientHealth(c) {
    const reasons = [];
    const silent = c.daysSinceContact ?? c.ageDays;
    const busy = c.activeProjects + c.openDeals > 0;
    if (silent >= 180 && !busy)
        return { score: 0, level: 'LOST', reasons: [`Нет контакта ${silent} дн., ничего в работе`] };
    let score = 100;
    if (silent >= 90) {
        score -= 65;
        reasons.push(`Нет контакта ${silent} дн.`);
    }
    else if (silent >= 60) {
        score -= 40;
        reasons.push(`Нет контакта ${silent} дн.`);
    }
    else if (silent >= 30) {
        score -= 20;
        reasons.push(`Нет контакта ${silent} дн.`);
    }
    if (c.overduePayments > 0) {
        score -= Math.min(50, 25 * c.overduePayments);
        reasons.push(`Просрочено оплат: ${c.overduePayments}`);
    }
    if (c.overdueProjects > 0) {
        score -= 15;
        reasons.push(`Просрочен проект`);
    }
    if (c.recentLostDeals > 0) {
        score -= 10;
        reasons.push(`Проиграна сделка за 90 дней`);
    }
    if (c.paidDeals >= 2) {
        score += 10;
        reasons.push(`Повторные покупки: ${c.paidDeals}`);
    }
    if (c.activeProjects > 0) {
        score += 10;
        reasons.push(`Проектов в работе: ${c.activeProjects}`);
    }
    score = Math.max(0, Math.min(100, score));
    const level = score >= 70 ? 'HEALTHY' : score >= 40 ? 'ATTENTION' : 'RISK';
    return { score, level, reasons };
}
//# sourceMappingURL=analytics.js.map
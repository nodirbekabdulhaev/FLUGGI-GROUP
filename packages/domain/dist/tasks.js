"use strict";
/**
 * Сроки проектов и задач (ТЗ §22–24). Чистые функции — без БД и часов:
 * текущее время передаётся аргументом, чтобы расчёт был проверяемым.
 */
Object.defineProperty(exports, "__esModule", { value: true });
exports.companyDate = companyDate;
exports.companyDayStart = companyDayStart;
exports.taskOverdueDays = taskOverdueDays;
exports.projectOverdueDays = projectOverdueDays;
exports.addDays = addDays;
exports.templateTaskDates = templateTaskDates;
exports.sortBetween = sortBetween;
exports.isRework = isRework;
const DAY_MS = 86_400_000;
/** Ташкент — UTC+5 без перехода на летнее время. */
const TZ_OFFSET_MS = 5 * 3_600_000;
/** Календарная дата YYYY-MM-DD в часовом поясе компании. */
function companyDate(at) {
    return new Date(at.getTime() + TZ_OFFSET_MS).toISOString().slice(0, 10);
}
/** Начало календарного дня компании (полночь по Ташкенту) в UTC. */
function companyDayStart(date) {
    return new Date(new Date(`${date}T00:00:00Z`).getTime() - TZ_OFFSET_MS);
}
/**
 * Дней просрочки задачи: дедлайн прошёл, а задача не завершена.
 * Любая просрочка считается минимум одним днём («Просрочено 1 день»).
 */
function taskOverdueDays(deadline, isOpen, now) {
    if (!deadline || !isOpen)
        return 0;
    const late = now.getTime() - deadline.getTime();
    return late > 0 ? Math.ceil(late / DAY_MS) : 0;
}
/**
 * Дней просрочки проекта: дедлайн — дата, проект просрочен со следующего дня.
 */
function projectOverdueDays(deadline, isActive, now) {
    if (!deadline || !isActive)
        return 0;
    const today = companyDate(now);
    if (today <= deadline)
        return 0;
    return Math.round((Date.parse(today) - Date.parse(deadline)) / DAY_MS);
}
/** Дата + N дней (YYYY-MM-DD). */
function addDays(date, days) {
    return new Date(Date.parse(`${date}T00:00:00Z`) + days * DAY_MS).toISOString().slice(0, 10);
}
/**
 * Сроки задачи из шаблона: старт = старт проекта + смещение,
 * дедлайн — конец рабочего дня (18:00 по Ташкенту) через durationDays − 1 дней.
 */
function templateTaskDates(projectStart, startOffsetDays, durationDays) {
    const startDate = addDays(projectStart, startOffsetDays);
    const endDate = addDays(startDate, Math.max(1, durationDays) - 1);
    return {
        startDate,
        deadline: new Date(companyDayStart(endDate).getTime() + 18 * 3_600_000),
    };
}
/**
 * Позиция карточки в колонке Kanban между соседями (дробный sort_order —
 * перемещение меняет одну строку, а не всю колонку).
 */
function sortBetween(prev, next) {
    if (prev === null && next === null)
        return 1000;
    if (prev === null)
        return next - 1000;
    if (next === null)
        return prev + 1000;
    return (prev + next) / 2;
}
/** Задача вернулась с проверки в работу — это переделка (ТЗ §30: качество исполнителя). */
function isRework(from, to) {
    return from === 'REVIEW' && (to === 'IN_PROGRESS' || to === 'TODO');
}
//# sourceMappingURL=tasks.js.map
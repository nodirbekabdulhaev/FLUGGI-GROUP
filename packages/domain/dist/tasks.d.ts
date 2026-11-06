/**
 * Сроки проектов и задач (ТЗ §22–24). Чистые функции — без БД и часов:
 * текущее время передаётся аргументом, чтобы расчёт был проверяемым.
 */
/** Календарная дата YYYY-MM-DD в часовом поясе компании. */
export declare function companyDate(at: Date): string;
/** Начало календарного дня компании (полночь по Ташкенту) в UTC. */
export declare function companyDayStart(date: string): Date;
/**
 * Дней просрочки задачи: дедлайн прошёл, а задача не завершена.
 * Любая просрочка считается минимум одним днём («Просрочено 1 день»).
 */
export declare function taskOverdueDays(deadline: Date | null, isOpen: boolean, now: Date): number;
/**
 * Дней просрочки проекта: дедлайн — дата, проект просрочен со следующего дня.
 */
export declare function projectOverdueDays(deadline: string | null, isActive: boolean, now: Date): number;
/** Дата + N дней (YYYY-MM-DD). */
export declare function addDays(date: string, days: number): string;
/**
 * Сроки задачи из шаблона: старт = старт проекта + смещение,
 * дедлайн — конец рабочего дня (18:00 по Ташкенту) через durationDays − 1 дней.
 */
export declare function templateTaskDates(projectStart: string, startOffsetDays: number, durationDays: number): {
    startDate: string;
    deadline: Date;
};
/**
 * Позиция карточки в колонке Kanban между соседями (дробный sort_order —
 * перемещение меняет одну строку, а не всю колонку).
 */
export declare function sortBetween(prev: number | null, next: number | null): number;
/** Задача вернулась с проверки в работу — это переделка (ТЗ §30: качество исполнителя). */
export declare function isRework(from: string, to: string): boolean;

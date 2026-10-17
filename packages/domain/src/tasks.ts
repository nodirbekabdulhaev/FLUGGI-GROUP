/**
 * Сроки проектов и задач (ТЗ §22–24). Чистые функции — без БД и часов:
 * текущее время передаётся аргументом, чтобы расчёт был проверяемым.
 */

const DAY_MS = 86_400_000;
/** Ташкент — UTC+5 без перехода на летнее время. */
const TZ_OFFSET_MS = 5 * 3_600_000;

/** Календарная дата YYYY-MM-DD в часовом поясе компании. */
export function companyDate(at: Date): string {
  return new Date(at.getTime() + TZ_OFFSET_MS).toISOString().slice(0, 10);
}

/** Начало календарного дня компании (полночь по Ташкенту) в UTC. */
export function companyDayStart(date: string): Date {
  return new Date(new Date(`${date}T00:00:00Z`).getTime() - TZ_OFFSET_MS);
}

/**
 * Дней просрочки задачи: дедлайн прошёл, а задача не завершена.
 * Любая просрочка считается минимум одним днём («Просрочено 1 день»).
 */
export function taskOverdueDays(deadline: Date | null, isOpen: boolean, now: Date): number {
  if (!deadline || !isOpen) return 0;
  const late = now.getTime() - deadline.getTime();
  return late > 0 ? Math.ceil(late / DAY_MS) : 0;
}

/**
 * Дней просрочки проекта: дедлайн — дата, проект просрочен со следующего дня.
 */
export function projectOverdueDays(deadline: string | null, isActive: boolean, now: Date): number {
  if (!deadline || !isActive) return 0;
  const today = companyDate(now);
  if (today <= deadline) return 0;
  return Math.round((Date.parse(today) - Date.parse(deadline)) / DAY_MS);
}

/** Дата + N дней (YYYY-MM-DD). */
export function addDays(date: string, days: number): string {
  return new Date(Date.parse(`${date}T00:00:00Z`) + days * DAY_MS).toISOString().slice(0, 10);
}

/**
 * Сроки задачи из шаблона: старт = старт проекта + смещение,
 * дедлайн — конец рабочего дня (18:00 по Ташкенту) через durationDays − 1 дней.
 */
export function templateTaskDates(
  projectStart: string,
  startOffsetDays: number,
  durationDays: number,
): { startDate: string; deadline: Date } {
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
export function sortBetween(prev: number | null, next: number | null): number {
  if (prev === null && next === null) return 1000;
  if (prev === null) return next! - 1000;
  if (next === null) return prev + 1000;
  return (prev + next) / 2;
}

/** Задача вернулась с проверки в работу — это переделка (ТЗ §30: качество исполнителя). */
export function isRework(from: string, to: string): boolean {
  return from === 'REVIEW' && (to === 'IN_PROGRESS' || to === 'TODO');
}

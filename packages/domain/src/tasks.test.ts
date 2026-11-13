import { describe, expect, it } from 'vitest';
import {
  addDays,
  companyDate,
  isRework,
  projectOverdueDays,
  sortBetween,
  taskOverdueDays,
  templateTaskDates,
} from './tasks';

describe('сроки задач', () => {
  const now = new Date('2026-10-07T10:00:00Z');

  it('незавершённая задача с прошедшим дедлайном просрочена минимум на 1 день', () => {
    expect(taskOverdueDays(new Date('2026-10-07T09:00:00Z'), true, now)).toBe(1);
    expect(taskOverdueDays(new Date('2026-10-04T10:00:00Z'), true, now)).toBe(3);
    expect(taskOverdueDays(new Date('2026-09-30T09:00:00Z'), true, now)).toBe(8);
  });

  it('завершённая задача и задача без дедлайна не просрочены', () => {
    expect(taskOverdueDays(new Date('2026-10-01T00:00:00Z'), false, now)).toBe(0);
    expect(taskOverdueDays(null, true, now)).toBe(0);
    expect(taskOverdueDays(new Date('2026-10-08T00:00:00Z'), true, now)).toBe(0);
  });

  it('проект просрочен со следующего дня после дедлайна по Ташкенту', () => {
    expect(projectOverdueDays('2026-10-07', true, now)).toBe(0);
    expect(projectOverdueDays('2026-10-06', true, now)).toBe(1);
    // 20:00 UTC 7 октября — уже 8 октября в Ташкенте
    expect(projectOverdueDays('2026-10-07', true, new Date('2026-10-07T20:00:00Z'))).toBe(1);
    expect(projectOverdueDays('2026-10-01', false, now)).toBe(0);
  });

  it('дата компании учитывает UTC+5', () => {
    expect(companyDate(new Date('2026-10-07T18:59:00Z'))).toBe('2026-10-07');
    expect(companyDate(new Date('2026-10-07T19:00:00Z'))).toBe('2026-10-08');
    expect(addDays('2026-12-30', 3)).toBe('2027-01-02');
  });

  it('сроки задачи из шаблона', () => {
    const d = templateTaskDates('2026-10-07', 3, 2);
    expect(d.startDate).toBe('2026-10-10');
    expect(d.deadline.toISOString()).toBe('2026-10-11T13:00:00.000Z'); // 18:00 Ташкент
  });

  it('позиция в колонке и переделки', () => {
    expect(sortBetween(null, null)).toBe(1000);
    expect(sortBetween(1000, 2000)).toBe(1500);
    expect(sortBetween(null, 1000)).toBe(0);
    expect(sortBetween(3000, null)).toBe(4000);
    expect(isRework('REVIEW', 'IN_PROGRESS')).toBe(true);
    expect(isRework('IN_PROGRESS', 'REVIEW')).toBe(false);
  });
});

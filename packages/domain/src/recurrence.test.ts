import { describe, expect, it } from 'vitest';
import { fillPeriod, nextDue } from './recurrence';

describe('регулярные дела', () => {
  it('ежемесячно 4-го: этот месяц, если ещё не прошло, иначе следующий', () => {
    const r = { frequency: 'MONTHLY' as const, dayOfMonth: 4 };
    expect(nextDue(r, '2026-10-03')).toBe('2026-10-04');
    expect(nextDue(r, '2026-10-04')).toBe('2026-10-04');
    expect(nextDue(r, '2026-10-05')).toBe('2026-11-04');
    expect(nextDue(r, '2026-12-20')).toBe('2027-01-04');
  });

  it('ежеквартально — первый месяц квартала; ежегодно — заданный месяц', () => {
    const q = { frequency: 'QUARTERLY' as const, dayOfMonth: 15, month: 1 };
    expect(nextDue(q, '2026-10-07')).toBe('2026-10-15');
    expect(nextDue(q, '2026-10-16')).toBe('2027-01-15');
    expect(nextDue({ ...q, month: 2 }, '2026-03-01')).toBe('2026-05-15');
    const y = { frequency: 'YEARLY' as const, dayOfMonth: 1, month: 2 };
    expect(nextDue(y, '2026-10-07')).toBe('2027-02-01');
    expect(nextDue({ frequency: 'YEARLY', dayOfMonth: 1, month: 12 }, '2026-10-07')).toBe(
      '2026-12-01',
    );
    expect(nextDue({ frequency: 'MONTHLY', dayOfMonth: 31 }, '2026-02-01')).toBe('2026-02-28');
  });

  it('период в названии считается от срока', () => {
    expect(fillPeriod('Налог с оборота за {прошлый_месяц}', '2026-10-04')).toBe(
      'Налог с оборота за сентябрь 2026',
    );
    expect(fillPeriod('за {прошлый_месяц}', '2027-01-04')).toBe('за декабрь 2026');
    expect(fillPeriod('Отчёт за {прошлый_квартал}', '2026-10-04')).toBe(
      'Отчёт за III квартал 2026',
    );
    expect(fillPeriod('Отчёт за {прошлый_квартал}', '2027-01-04')).toBe('Отчёт за IV квартал 2026');
    expect(fillPeriod('Стат за {прошлый_год}; баланс {год}', '2027-02-01')).toBe(
      'Стат за 2026; баланс 2027',
    );
  });
});

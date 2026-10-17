import { describe, expect, it } from 'vitest';
import {
  atTashkent,
  averagePct,
  completionPct,
  finalSalary,
  isoWeekday,
  lateMinutes,
  monthRange,
  tashkentTime,
  workMinutes,
} from './people';

describe('посещаемость', () => {
  it('опоздание считается от начала графика, льготные минуты не в счёт', () => {
    expect(lateMinutes(atTashkent('2026-10-07', '09:08'), '2026-10-07', '09:00', 10)).toBe(0);
    expect(lateMinutes(atTashkent('2026-10-07', '09:25'), '2026-10-07', '09:00', 10)).toBe(25);
    expect(lateMinutes(atTashkent('2026-10-07', '08:40'), '2026-10-07', '09:00', 0)).toBe(0);
  });

  it('время по Ташкенту, день недели и отработанные минуты', () => {
    expect(tashkentTime(new Date('2026-10-07T04:15:00Z'))).toBe('09:15');
    expect(isoWeekday('2026-10-07')).toBe(3); // среда
    expect(isoWeekday('2026-10-11')).toBe(7); // воскресенье
    expect(workMinutes(atTashkent('2026-10-07', '09:00'), atTashkent('2026-10-07', '18:30'))).toBe(
      570,
    );
    expect(workMinutes(atTashkent('2026-10-07', '09:00'), null)).toBe(0);
  });
});

describe('KPI и зарплата', () => {
  it('выполнение цели: пример ТЗ §31 — 700 из 1000 = 70%', () => {
    expect(completionPct(700, 1000)).toBe('70.00');
    expect(completionPct(5, null)).toBeNull();
    expect(completionPct(5, 0)).toBeNull();
    expect(averagePct(['70.00', null, '130.00'])).toBe('100.00');
    expect(averagePct([null])).toBeNull();
  });

  it('итоговая зарплата по формуле ТЗ §32', () => {
    expect(
      finalSalary({
        baseSalary: '5000000',
        kpiBonus: '1000000',
        commission: '900000',
        otherBonus: '200000',
        penalty: '150000',
      }),
    ).toBe('6950000.00');
  });

  it('границы месяца по Ташкенту', () => {
    const r = monthRange('2026-10');
    expect(r.from.toISOString()).toBe('2026-09-30T19:00:00.000Z');
    expect(r.to.toISOString()).toBe('2026-10-31T19:00:00.000Z');
  });
});

describe('KPI-бонус', () => {
  it('пропорционален выполнению, перевыполнение — до 120%', async () => {
    const { kpiBonusFor } = await import('./people');
    expect(kpiBonusFor('2000000', '75')).toBe('1500000.00');
    expect(kpiBonusFor('2000000', '150')).toBe('2400000.00');
    expect(kpiBonusFor('2000000', null)).toBe('0.00');
    expect(kpiBonusFor(null, '90')).toBe('0.00');
    expect(kpiBonusFor('1000000', '33.333')).toBe('333330.00');
  });
});

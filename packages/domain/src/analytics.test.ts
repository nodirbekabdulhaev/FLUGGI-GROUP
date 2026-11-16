import { describe, expect, it } from 'vitest';
import { bucketKey, bucketKeys, clientHealth, granularityFor } from './analytics';

const base = {
  daysSinceContact: 5,
  ageDays: 100,
  overduePayments: 0,
  overdueProjects: 0,
  activeProjects: 0,
  openDeals: 0,
  paidDeals: 1,
  recentLostDeals: 0,
};

describe('временные ряды', () => {
  it('шаг по длине периода', () => {
    const d = (s: string) => new Date(s);
    expect(granularityFor(d('2026-10-01'), d('2026-11-01'))).toBe('day');
    expect(granularityFor(d('2026-07-01'), d('2026-10-01'))).toBe('week');
    expect(granularityFor(d('2026-01-01'), d('2027-01-01'))).toBe('month');
  });

  it('корзины — по дате Ташкента, неделя с понедельника', () => {
    // 2026-10-07 20:00 UTC = 2026-10-08 01:00 Ташкент (четверг)
    const at = new Date('2026-10-07T20:00:00Z');
    expect(bucketKey(at, 'day')).toBe('2026-10-08');
    expect(bucketKey(at, 'week')).toBe('2026-10-05');
    expect(bucketKey(at, 'month')).toBe('2026-10');
  });

  it('все корзины периода без пропусков', () => {
    const from = new Date('2026-09-30T19:00:00Z'); // 1 октября 00:00 Ташкент
    const to = new Date('2026-10-31T19:00:00Z');
    const days = bucketKeys(from, to, 'day');
    expect(days).toHaveLength(31);
    expect(days[0]).toBe('2026-10-01');
    expect(days.at(-1)).toBe('2026-10-31');
    expect(bucketKeys(from, to, 'week')).toEqual([
      '2026-09-28',
      '2026-10-05',
      '2026-10-12',
      '2026-10-19',
      '2026-10-26',
    ]);
    expect(
      bucketKeys(new Date('2025-12-31T19:00:00Z'), new Date('2026-12-31T19:00:00Z'), 'month'),
    ).toHaveLength(12);
  });
});

describe('здоровье клиента', () => {
  it('активный клиент — здоров', () => {
    expect(clientHealth({ ...base, activeProjects: 1, paidDeals: 2 })).toMatchObject({
      score: 100,
      level: 'HEALTHY',
    });
  });

  it('молчание и просрочки снижают оценку, причины видны', () => {
    const r = clientHealth({ ...base, daysSinceContact: 65, overduePayments: 1 });
    expect(r.score).toBe(35);
    expect(r.level).toBe('RISK');
    expect(r.reasons).toEqual(['Нет контакта 65 дн.', 'Просрочено оплат: 1']);
    expect(clientHealth({ ...base, daysSinceContact: 35 }).level).toBe('HEALTHY');
    expect(clientHealth({ ...base, daysSinceContact: 35, recentLostDeals: 1 })).toMatchObject({
      score: 70,
    });
    expect(clientHealth({ ...base, overduePayments: 3 }).score).toBe(50);
  });

  it('потерян — давно без контакта и ничего в работе; без контактов считается от создания', () => {
    expect(clientHealth({ ...base, daysSinceContact: 200 }).level).toBe('LOST');
    expect(clientHealth({ ...base, daysSinceContact: 200, openDeals: 1 }).level).toBe('RISK');
    expect(clientHealth({ ...base, daysSinceContact: null, ageDays: 10 }).level).toBe('HEALTHY');
  });
});

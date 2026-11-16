import { describe, expect, it } from 'vitest';
import { resolveCustomPeriod, resolvePeriod } from './period';

// 2026-10-06 02:00 по Ташкенту = 2026-10-05 21:00 UTC
const now = new Date('2026-10-05T21:00:00Z');

describe('resolvePeriod', () => {
  it('today — сутки по Ташкенту', () => {
    const r = resolvePeriod('today', now);
    expect(r.from.toISOString()).toBe('2026-10-05T19:00:00.000Z');
    expect(r.to.toISOString()).toBe('2026-10-06T19:00:00.000Z');
  });
  it('month', () => {
    const r = resolvePeriod('month', now);
    expect(r.from.toISOString()).toBe('2026-09-30T19:00:00.000Z');
    expect(r.to.toISOString()).toBe('2026-10-31T19:00:00.000Z');
  });
  it('quarter', () => {
    const r = resolvePeriod('quarter', now);
    expect(r.from.toISOString()).toBe('2026-09-30T19:00:00.000Z');
    expect(r.to.toISOString()).toBe('2026-12-31T19:00:00.000Z');
  });
  it('year переходит через границу года', () => {
    const r = resolvePeriod('year', new Date('2026-12-31T20:00:00Z'));
    expect(r.from.toISOString()).toBe('2026-12-31T19:00:00.000Z');
  });
});

describe('resolveCustomPeriod', () => {
  it('включает обе даты', () => {
    const r = resolveCustomPeriod('2026-10-01', '2026-10-06');
    expect(r?.from.toISOString()).toBe('2026-09-30T19:00:00.000Z');
    expect(r?.to.toISOString()).toBe('2026-10-06T19:00:00.000Z');
  });
  it('отклоняет некорректный диапазон', () => {
    expect(resolveCustomPeriod('2026-10-06', '2026-10-01')).toBeNull();
    expect(resolveCustomPeriod('bad', '2026-10-01')).toBeNull();
  });
});

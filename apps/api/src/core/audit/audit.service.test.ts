import { describe, expect, it } from 'vitest';
import { diffFields } from './audit.service';

describe('diffFields', () => {
  it('возвращает только изменившиеся поля', () => {
    const d = new Date('2026-10-06T10:00:00Z');
    expect(
      diffFields({ a: 1, b: 'x', c: d, e: null }, { a: 1, b: 'y', c: d, e: undefined }, ['a', 'b', 'c', 'e']),
    ).toEqual({ b: { old: 'x', new: 'y' } });
  });
  it('null если изменений нет', () => {
    expect(diffFields({ a: 1 }, { a: 1 }, ['a'])).toBeNull();
  });
  it('игнорирует поля, которых нет в обновлении', () => {
    expect(diffFields({ a: 1, b: 2 }, { a: 2 }, ['a', 'b'])).toEqual({ a: { old: 1, new: 2 } });
  });
});

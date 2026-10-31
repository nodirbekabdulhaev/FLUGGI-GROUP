import { describe, expect, it } from 'vitest';
import { amountInWords, numberInWords, plural } from './words';

describe('сумма прописью', () => {
  it('склоняет слова', () => {
    const f: [string, string, string] = ['сум', 'сума', 'сумов'];
    expect(plural(1, f)).toBe('сум');
    expect(plural(3, f)).toBe('сума');
    expect(plural(11, f)).toBe('сумов');
    expect(plural(21, f)).toBe('сум');
    expect(plural(112, f)).toBe('сумов');
  });

  it('пишет числа', () => {
    expect(numberInWords(0)).toBe('ноль');
    expect(numberInWords(2001)).toBe('две тысячи один');
    expect(numberInWords(1_000_000)).toBe('один миллион');
    expect(numberInWords(7_512_019)).toBe('семь миллионов пятьсот двенадцать тысяч девятнадцать');
    expect(numberInWords(21_000)).toBe('двадцать одна тысяча');
  });

  it('пишет суммы в валюте договора', () => {
    expect(amountInWords('7500000.00', 'UZS')).toBe(
      'Семь миллионов пятьсот тысяч сумов 00 тийинов',
    );
    expect(amountInWords(650, 'USD')).toBe('Шестьсот пятьдесят долларов США 00 центов');
    expect(amountInWords('1.21', 'USD')).toBe('Один доллар США 21 цент');
  });
});

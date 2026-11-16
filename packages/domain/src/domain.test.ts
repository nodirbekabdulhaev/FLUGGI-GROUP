import { describe, expect, it } from 'vitest';
import { computeLeadScore, conversion, forecast, percent, scoreLevel, toUzs } from './index';

describe('money', () => {
  it('конвертирует USD по курсу и не трогает UZS', () => {
    expect(toUzs('1000', 'USD', '12650.5').toString()).toBe('12650500');
    expect(toUzs('9000000', 'UZS', '12650').toString()).toBe('9000000');
  });
  it('percent безопасен к нулю', () => {
    expect(percent(4300000, 9000000)).toBe(47.78);
    expect(percent(1, 0)).toBe(0);
  });
});

describe('lead score', () => {
  it('уровни по ТЗ §10', () => {
    expect(scoreLevel(30)).toBe('LOW');
    expect(scoreLevel(31)).toBe('MEDIUM');
    expect(scoreLevel(61)).toBe('HIGH');
    expect(scoreLevel(81)).toBe('HOT');
  });
  it('горячий лид: бюджет, срочно, крупная компания, высокий интерес, поздний этап', () => {
    const r = computeLeadScore({
      budgetUzs: 100_000_000,
      hasService: true,
      priority: 'URGENT',
      daysToDesiredDate: 7,
      companySize: 'LARGE',
      interest: 5,
      stageIndex: 4,
      stageCount: 5,
    });
    expect(r.score).toBe(100);
    expect(r.level).toBe('HOT');
  });
  it('холодный лид без данных', () => {
    const r = computeLeadScore({
      hasService: false,
      priority: 'LOW',
      stageIndex: 0,
      stageCount: 5,
    });
    expect(r.level).toBe('LOW');
  });
  it('бюджет сравнивается с ценой услуги', () => {
    const base = { hasService: true, priority: 'MEDIUM' as const, stageIndex: 0, stageCount: 5 };
    const low = computeLeadScore({ ...base, budgetUzs: 1_000_000, serviceMinPriceUzs: 10_000_000 });
    const high = computeLeadScore({
      ...base,
      budgetUzs: 20_000_000,
      serviceMinPriceUzs: 10_000_000,
    });
    expect(high.score).toBeGreaterThan(low.score);
  });
});

describe('forecast', () => {
  it('пример ТЗ §40: 100 млн × 50% = 50 млн', () => {
    const r = forecast([{ amountUzs: 100_000_000, probability: 50 }]);
    expect(r.weighted.toNumber()).toBe(50_000_000);
    expect(r.pipeline.toNumber()).toBe(100_000_000);
  });
  it('conversion', () => {
    expect(conversion(17, 127)).toBe(13.39);
    expect(conversion(1, 0)).toBe(0);
  });
});

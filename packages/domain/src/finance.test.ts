import { describe, expect, it } from 'vitest';
import { companyFinance, marginPct, projectFinance } from './finance';

describe('финансы', () => {
  it('пример из ТЗ §25: 9 000 000 − 4 700 000 = 4 300 000, маржа 47.78%', () => {
    expect(projectFinance('9000000', '4700000')).toEqual({
      grossProfit: '4300000.00',
      marginPct: '47.78',
    });
  });

  it('без выручки маржа не считается; убыток — отрицательная маржа', () => {
    expect(marginPct(100, 0)).toBeNull();
    expect(projectFinance('1000000', '1500000')).toEqual({
      grossProfit: '-500000.00',
      marginPct: '-50.00',
    });
  });

  it('прибыль компании: валовая и операционная', () => {
    expect(
      companyFinance({
        collected: '10000000',
        refunds: '1000000',
        projectExpenses: '3000000',
        companyExpenses: '2000000',
        commissions: '900000',
      }),
    ).toEqual({ grossProfit: '6000000.00', operatingProfit: '3100000.00', marginPct: '66.67' });
  });
});

describe('накладные на проект', () => {
  it('делятся на число проектов месяца или на заданный делитель', async () => {
    const { overheadShare } = await import('./finance');
    expect(overheadShare(10_000_000, 4)).toBe('2500000.00');
    expect(overheadShare(10_000_000, 4, 5)).toBe('2000000.00');
    expect(overheadShare(10_000_000, 0)).toBe('10000000.00');
    expect(overheadShare(0, 3)).toBe('0.00');
  });
});

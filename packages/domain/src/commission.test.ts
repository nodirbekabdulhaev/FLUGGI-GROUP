import { describe, expect, it } from 'vitest';
import {
  calcCommission,
  evaluateCondition,
  pickRule,
  proposalTotals,
  type RuleInput,
} from './index';

describe('proposalTotals', () => {
  it('считает строки, скидку и итог', () => {
    const t = proposalTotals([
      { quantity: 1, unitPrice: '9000000' },
      { quantity: 2, unitPrice: '1000000', discountPct: 10 },
    ]);
    expect(t.subtotal.toString()).toBe('11000000');
    expect(t.discountAmount.toString()).toBe('200000');
    expect(t.total.toString()).toBe('10800000');
  });
});

describe('commission DSL (ТЗ §34)', () => {
  const cond = {
    any: [
      { metric: 'avg_check_usd' as const, op: '>' as const, value: 3000 },
      { metric: 'orders_count' as const, op: '>' as const, value: 15 },
    ],
  };
  it('IF average_check > 3000 USD OR orders_count > 15', () => {
    expect(evaluateCondition(cond, { avg_check_usd: 3500, orders_count: 2 })).toBe(true);
    expect(evaluateCondition(cond, { avg_check_usd: 1000, orders_count: 16 })).toBe(true);
    expect(evaluateCondition(cond, { avg_check_usd: 1000, orders_count: 15 })).toBe(false);
    expect(evaluateCondition(null, {})).toBe(true);
  });
  it('вложенные группы all/any', () => {
    const c = { all: [{ metric: 'orders_count' as const, op: '>=' as const, value: 5 }, cond] };
    expect(evaluateCondition(c, { orders_count: 5, avg_check_usd: 4000 })).toBe(true);
    expect(evaluateCondition(c, { orders_count: 4, avg_check_usd: 4000 })).toBe(false);
  });
});

describe('pickRule', () => {
  const rules: RuleInput[] = [
    {
      id: 'base',
      calcType: 'PERCENT_OF_PAYMENT',
      value: 10,
      conditions: null,
      priority: 0,
      userId: null,
    },
    {
      id: 'bonus',
      calcType: 'PERCENT_OF_PAYMENT',
      value: 15,
      priority: 10,
      userId: null,
      conditions: { any: [{ metric: 'orders_count', op: '>', value: 15 }] },
    },
    {
      id: 'personal',
      calcType: 'PERCENT_OF_PAYMENT',
      value: 12,
      conditions: null,
      priority: 0,
      userId: 'u2',
    },
  ];
  it('берёт подходящее правило с наивысшим приоритетом', () => {
    expect(pickRule(rules, { orders_count: 3 }, 'u1')?.id).toBe('base');
    expect(pickRule(rules, { orders_count: 20 }, 'u1')?.id).toBe('bonus');
  });
  it('персональное правило важнее общего при равном приоритете', () => {
    expect(pickRule(rules, { orders_count: 3 }, 'u2')?.id).toBe('personal');
  });
});

describe('calcCommission (ТЗ §33)', () => {
  it('10% от оплаты', () => {
    expect(
      calcCommission({ calcType: 'PERCENT_OF_PAYMENT', value: 10 }, '9000000', {
        firstPaymentOfDeal: true,
      }).amount.toString(),
    ).toBe('900000');
  });
  it('возврат даёт отрицательную комиссию', () => {
    expect(
      calcCommission({ calcType: 'PERCENT_OF_PAYMENT', value: 10 }, '-1000000', {
        firstPaymentOfDeal: false,
      }).amount.toString(),
    ).toBe('-100000');
  });
  it('фиксированная сумма — только с первого платежа сделки', () => {
    expect(
      calcCommission({ calcType: 'FIXED_PER_DEAL', value: 500000 }, '3000000', {
        firstPaymentOfDeal: true,
      }).amount.toString(),
    ).toBe('500000');
    expect(
      calcCommission({ calcType: 'FIXED_PER_DEAL', value: 500000 }, '3000000', {
        firstPaymentOfDeal: false,
      }).amount.toString(),
    ).toBe('0');
  });
  it('% от прибыли учитывает маржу', () => {
    expect(
      calcCommission({ calcType: 'PERCENT_OF_PROFIT', value: 10 }, '9000000', {
        firstPaymentOfDeal: true,
        marginPct: 50,
      }).amount.toString(),
    ).toBe('450000');
  });
});

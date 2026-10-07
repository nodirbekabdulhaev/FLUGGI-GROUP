import { z } from 'zod';
import {
  CURRENCIES,
  EXPENSE_CATEGORIES,
  EXPENSE_SCOPES,
  type Currency,
  type ExpenseCategory,
  type ExpenseScope,
} from '../enums';
import {
  PERIOD_PRESETS,
  resolveCustomPeriod,
  resolvePeriod,
  type DateRange,
  type PeriodPreset,
} from '../period';
import { paginationQuerySchema } from './common';
import { dateOnly, moneySchema } from './fields';
import type { NamedRef } from './references';
import type { NumberedRef } from './sales';

// ─────────────────────────── Период ───────────────────────────

/** ?period=today|week|month|quarter|year|custom&from&to — как в глобальном фильтре CEO. */
export const periodQuerySchema = z.object({
  period: z.enum(PERIOD_PRESETS).default('month'),
  from: dateOnly.optional(),
  to: dateOnly.optional(),
});
export type PeriodQuery = z.input<typeof periodQuerySchema>;

/** Период запроса → границы; свой период без дат — текущий месяц. */
export function resolvePeriodQuery(
  q: { period: PeriodPreset; from?: string; to?: string },
  now = new Date(),
): DateRange {
  if (q.period === 'custom') {
    const r = q.from && q.to ? resolveCustomPeriod(q.from, q.to) : null;
    return r ?? resolvePeriod('month', now);
  }
  return resolvePeriod(q.period, now);
}

// ─────────────────────────── Расходы (ТЗ §26) ───────────────────────────

const expenseFields = {
  category: z.enum(EXPENSE_CATEGORIES),
  amount: moneySchema.refine((v) => Number(v) > 0, 'Сумма должна быть больше нуля'),
  currency: z.enum(CURRENCIES).default('UZS'),
  expenseDate: dateOnly,
  payeeUserId: z.uuid().nullish(),
  description: z.string().trim().max(2000).nullish(),
};

export const createExpenseSchema = z
  .object({
    scope: z.enum(EXPENSE_SCOPES),
    projectId: z.uuid().nullish(),
    ...expenseFields,
  })
  .refine((v) => (v.scope === 'PROJECT') === Boolean(v.projectId), {
    message: 'Проектный расход привязывается к проекту, расход компании — без проекта',
    path: ['projectId'],
  });
export type CreateExpenseInput = z.input<typeof createExpenseSchema>;

export const updateExpenseSchema = z.object({
  category: expenseFields.category.optional(),
  amount: expenseFields.amount.optional(),
  currency: z.enum(CURRENCIES).optional(),
  expenseDate: dateOnly.optional(),
  payeeUserId: z.uuid().nullable().optional(),
  description: z.string().trim().max(2000).nullable().optional(),
});
export type UpdateExpenseInput = z.input<typeof updateExpenseSchema>;

export const expenseListQuerySchema = paginationQuerySchema.extend({
  scope: z.enum(EXPENSE_SCOPES).optional(),
  projectId: z.uuid().optional(),
  category: z.enum(EXPENSE_CATEGORIES).optional(),
  dateFrom: dateOnly.optional(),
  dateTo: dateOnly.optional(),
});
export type ExpenseListQuery = z.input<typeof expenseListQuerySchema>;

export interface ExpenseDto {
  id: string;
  number: string;
  scope: ExpenseScope;
  project: NumberedRef | null;
  category: ExpenseCategory;
  amount: string;
  currency: Currency;
  exchangeRate: string;
  amountUzs: string;
  expenseDate: string;
  payee: NamedRef | null;
  description: string | null;
  createdBy: NamedRef;
  createdAt: string;
  /** Может ли текущий пользователь изменить/удалить расход. */
  canEdit: boolean;
}

// ─────────────────────────── Финансы проекта (ТЗ §25) ───────────────────────────

export interface CategoryAmountDto {
  category: ExpenseCategory;
  amountUzs: string;
}

export interface ProjectFinanceDto {
  /** Стоимость проекта (выручка проекта), UZS. */
  revenueUzs: string;
  /** Получено по сделке проекта (оплаты − возвраты), UZS. */
  collectedUzs: string;
  /** Осталось получить, UZS. */
  receivableUzs: string;
  expensesUzs: string;
  byCategory: CategoryAmountDto[];
  /** Валовая прибыль = выручка − расходы. */
  grossProfitUzs: string;
  /** Маржинальность, % (null — нет выручки). */
  marginPct: string | null;
  commissionsUzs: string;
}

// ─────────────────────────── Финансовый дашборд (ТЗ §27) ───────────────────────────

export interface FinanceSummaryDto {
  from: string;
  to: string;
  /** Подписанные договоры за период. */
  revenueUzs: string;
  /** Оплаты за период (без возвратов). */
  collectedUzs: string;
  refundsUzs: string;
  /** Дебиторка на сегодня: подписано − получено. */
  receivablesUzs: string;
  projectExpensesUzs: string;
  /** null — нет права видеть расходы компании. */
  companyExpensesUzs: string | null;
  commissionsUzs: string;
  /** Получено − возвраты − проектные расходы. */
  grossProfitUzs: string;
  /** Валовая − расходы компании − комиссии; null — без права на финансы компании. */
  operatingProfitUzs: string | null;
  marginPct: string | null;
  expensesByCategory: CategoryAmountDto[];
}

export const projectProfitQuerySchema = paginationQuerySchema.extend({
  q: z.string().trim().max(100).optional(),
});

export interface ProjectProfitDto {
  project: NumberedRef;
  client: NamedRef;
  status: string;
  revenueUzs: string;
  collectedUzs: string;
  expensesUzs: string;
  grossProfitUzs: string;
  marginPct: string | null;
}

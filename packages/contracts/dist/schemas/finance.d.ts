import { z } from 'zod';
import { type Currency, type ExpenseCategory, type ExpenseScope } from '../enums';
import { type DateRange, type PeriodPreset } from '../period';
import type { NamedRef } from './references';
import type { NumberedRef } from './sales';
/** ?period=today|week|month|quarter|year|custom&from&to — как в глобальном фильтре CEO. */
export declare const periodQuerySchema: z.ZodObject<{
    period: z.ZodDefault<z.ZodEnum<{
        today: "today";
        week: "week";
        month: "month";
        quarter: "quarter";
        year: "year";
        custom: "custom";
    }>>;
    from: z.ZodOptional<z.ZodISODate>;
    to: z.ZodOptional<z.ZodISODate>;
}, z.core.$strip>;
export type PeriodQuery = z.input<typeof periodQuerySchema>;
/** Период запроса → границы; свой период без дат — текущий месяц. */
export declare function resolvePeriodQuery(q: {
    period: PeriodPreset;
    from?: string;
    to?: string;
}, now?: Date): DateRange;
export declare const createExpenseSchema: z.ZodObject<{
    category: z.ZodString;
    amount: z.ZodPipe<z.ZodPipe<z.ZodUnion<readonly [z.ZodString, z.ZodNumber]>, z.ZodTransform<string, string | number>>, z.ZodString>;
    currency: z.ZodDefault<z.ZodEnum<{
        UZS: "UZS";
        USD: "USD";
    }>>;
    expenseDate: z.ZodISODate;
    payeeUserId: z.ZodOptional<z.ZodNullable<z.ZodUUID>>;
    description: z.ZodOptional<z.ZodNullable<z.ZodString>>;
    scope: z.ZodEnum<{
        COMPANY: "COMPANY";
        PROJECT: "PROJECT";
    }>;
    projectId: z.ZodOptional<z.ZodNullable<z.ZodUUID>>;
}, z.core.$strip>;
export type CreateExpenseInput = z.input<typeof createExpenseSchema>;
export declare const updateExpenseSchema: z.ZodObject<{
    category: z.ZodOptional<z.ZodString>;
    amount: z.ZodOptional<z.ZodPipe<z.ZodPipe<z.ZodUnion<readonly [z.ZodString, z.ZodNumber]>, z.ZodTransform<string, string | number>>, z.ZodString>>;
    currency: z.ZodOptional<z.ZodEnum<{
        UZS: "UZS";
        USD: "USD";
    }>>;
    expenseDate: z.ZodOptional<z.ZodISODate>;
    payeeUserId: z.ZodOptional<z.ZodNullable<z.ZodUUID>>;
    description: z.ZodOptional<z.ZodNullable<z.ZodString>>;
}, z.core.$strip>;
export type UpdateExpenseInput = z.input<typeof updateExpenseSchema>;
export declare const expenseListQuerySchema: z.ZodObject<{
    page: z.ZodDefault<z.ZodCoercedNumber<unknown>>;
    pageSize: z.ZodDefault<z.ZodCoercedNumber<unknown>>;
    scope: z.ZodOptional<z.ZodEnum<{
        COMPANY: "COMPANY";
        PROJECT: "PROJECT";
    }>>;
    projectId: z.ZodOptional<z.ZodUUID>;
    category: z.ZodOptional<z.ZodString>;
    dateFrom: z.ZodOptional<z.ZodISODate>;
    dateTo: z.ZodOptional<z.ZodISODate>;
}, z.core.$strip>;
export type ExpenseListQuery = z.input<typeof expenseListQuerySchema>;
export interface ExpenseDto {
    id: string;
    number: string;
    scope: ExpenseScope;
    project: NumberedRef | null;
    category: ExpenseCategory;
    categoryName: string;
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
export interface CategoryAmountDto {
    category: ExpenseCategory;
    name: string;
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
    /** Доля накладных (аренда, офис) за месяцы работы проекта, UZS */
    overheadUzs: string;
    /** Прибыль после накладных = валовая − накладные */
    netProfitUzs: string;
    /** Плановые, ещё не начисленные расходы по тарифу, UZS */
    plannedCostUzs: string;
}
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
    /** Прочие поступления (не от клиентов) за период; null — без права на финансы компании. */
    otherIncomeUzs: string | null;
    /** Получено − возвраты − проектные расходы. */
    grossProfitUzs: string;
    /** Валовая − расходы компании − комиссии; null — без права на финансы компании. */
    operatingProfitUzs: string | null;
    marginPct: string | null;
    expensesByCategory: CategoryAmountDto[];
}
export declare const projectProfitQuerySchema: z.ZodObject<{
    page: z.ZodDefault<z.ZodCoercedNumber<unknown>>;
    pageSize: z.ZodDefault<z.ZodCoercedNumber<unknown>>;
    q: z.ZodOptional<z.ZodString>;
}, z.core.$strip>;
export interface ProjectProfitDto {
    project: NumberedRef;
    client: NamedRef;
    status: string;
    revenueUzs: string;
    collectedUzs: string;
    expensesUzs: string;
    grossProfitUzs: string;
    marginPct: string | null;
    overheadUzs: string;
    netProfitUzs: string;
}

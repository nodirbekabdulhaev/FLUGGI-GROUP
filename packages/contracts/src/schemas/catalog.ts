import { z } from 'zod';
import { CURRENCIES, EXECUTOR_SPECIALTIES, type Currency, type ExecutorSpecialty } from '../enums';
import { dateOnly, moneySchema } from './fields';
import type { NamedRef } from './references';
import type { NumberedRef } from './sales';

// ─────────────────────────── Категории доходов и расходов ───────────────────────────

export const FINANCE_CATEGORY_KINDS = ['EXPENSE', 'INCOME'] as const;
export type FinanceCategoryKind = (typeof FINANCE_CATEGORY_KINDS)[number];

export const financeCategorySchema = z.object({
  kind: z.enum(FINANCE_CATEGORY_KINDS),
  name: z.string().trim().min(1, 'Введите название').max(80),
  accountHint: z.string().trim().max(20).nullish(),
  isOverhead: z.boolean().default(false),
  isActive: z.boolean().default(true),
  sort: z.coerce.number().int().min(0).max(1000).default(100),
});
export type FinanceCategoryInput = z.input<typeof financeCategorySchema>;

export interface FinanceCategoryDto {
  id: string;
  code: string;
  kind: FinanceCategoryKind;
  name: string;
  accountHint: string | null;
  isOverhead: boolean;
  isActive: boolean;
  sort: number;
  /** Сколько записей с этой категорией (удалить можно только неиспользуемую) */
  usage: number;
}

// ─────────────────────────── Прочие поступления ───────────────────────────

export const otherIncomeSchema = z.object({
  category: z.string().trim().min(1, 'Выберите категорию').max(40),
  amount: moneySchema.refine((v) => Number(v) > 0, 'Сумма должна быть больше нуля'),
  currency: z.enum(CURRENCIES).default('UZS'),
  incomeDate: dateOnly,
  projectId: z.uuid().nullish(),
  clientId: z.uuid().nullish(),
  description: z.string().trim().max(2000).nullish(),
});
export type OtherIncomeInput = z.input<typeof otherIncomeSchema>;

export const otherIncomeListQuerySchema = z.object({
  category: z.string().max(40).optional(),
  dateFrom: dateOnly.optional(),
  dateTo: dateOnly.optional(),
  page: z.coerce.number().int().min(1).default(1),
  pageSize: z.coerce.number().int().min(1).max(100).default(25),
});
export type OtherIncomeListQuery = z.input<typeof otherIncomeListQuerySchema>;

export interface OtherIncomeDto {
  id: string;
  number: string;
  category: string;
  categoryName: string;
  amount: string;
  currency: Currency;
  exchangeRate: string;
  amountUzs: string;
  incomeDate: string;
  project: NumberedRef | null;
  client: NamedRef | null;
  description: string | null;
  createdBy: NamedRef;
  createdAt: string;
}

// ─────────────────────────── Тарифы и себестоимость ───────────────────────────

export const EXECUTOR_SPECIALTIES_ALL = EXECUTOR_SPECIALTIES;
export type Specialty = ExecutorSpecialty;

export const workItemSchema = z.object({
  name: z.string().trim().min(1, 'Введите название').max(120),
  unit: z.string().trim().min(1).max(20).default('шт'),
  specialty: z.enum(EXECUTOR_SPECIALTIES_ALL).nullish(),
  defaultRate: moneySchema,
  currency: z.enum(CURRENCIES).default('UZS'),
  isActive: z.boolean().default(true),
});
export type WorkItemInput = z.input<typeof workItemSchema>;

export interface WorkItemDto {
  id: string;
  code: string;
  name: string;
  unit: string;
  specialty: Specialty | null;
  defaultRate: string;
  currency: Currency;
  isActive: boolean;
}

/** Личные ставки сотрудника («договор сдельный» в карточке сотрудника). Пустая ставка — удалить. */
export const employeeRatesSchema = z.object({
  rates: z
    .array(
      z.object({
        workItemId: z.uuid(),
        rate: moneySchema.nullable(),
        currency: z.enum(CURRENCIES).default('UZS'),
      }),
    )
    .max(200),
});
export type EmployeeRatesInput = z.input<typeof employeeRatesSchema>;

export interface EmployeeRateDto {
  workItem: WorkItemDto;
  /** null — действует базовая ставка */
  rate: string | null;
  currency: Currency | null;
}

export const TARIFF_ITEM_KINDS = ['PIECE', 'FIXED'] as const;
export type TariffItemKind = (typeof TARIFF_ITEM_KINDS)[number];

export const tariffItemSchema = z
  .object({
    kind: z.enum(TARIFF_ITEM_KINDS),
    workItemId: z.uuid().nullish(),
    quantity: z.coerce.number().positive().max(100000).default(1),
    specialty: z.enum(EXECUTOR_SPECIALTIES_ALL).nullish(),
    amount: moneySchema.nullish(),
    currency: z.enum(CURRENCIES).default('UZS'),
    label: z.string().trim().max(120).nullish(),
  })
  .refine((v) => (v.kind === 'PIECE' ? Boolean(v.workItemId) : Boolean(v.specialty && v.amount)), {
    message: 'Сдельно — выберите работу; фиксированно — исполнителя и сумму',
    path: ['workItemId'],
  });

export const tariffSchema = z.object({
  serviceId: z.uuid(),
  name: z.string().trim().min(1, 'Введите название').max(60),
  description: z.string().trim().max(2000).nullish(),
  price: moneySchema.refine((v) => Number(v) > 0, 'Цена должна быть больше нуля'),
  currency: z.enum(CURRENCIES).default('UZS'),
  isActive: z.boolean().default(true),
  sort: z.coerce.number().int().min(0).max(1000).default(0),
  items: z.array(tariffItemSchema).max(50).default([]),
});
export type TariffInput = z.input<typeof tariffSchema>;

export interface TariffItemDto {
  id: string;
  kind: TariffItemKind;
  workItem: WorkItemDto | null;
  quantity: string;
  specialty: Specialty | null;
  amount: string | null;
  currency: Currency;
  label: string | null;
  /** Стоимость позиции по базовой ставке, UZS */
  costUzs: string;
}

export interface TariffDto {
  id: string;
  service: NamedRef;
  name: string;
  description: string | null;
  price: string;
  currency: Currency;
  isActive: boolean;
  sort: number;
  items: TariffItemDto[];
  /** Экономика тарифа по базовым ставкам и текущему курсу; null — нет права на финансы компании */
  economics: {
    priceUzs: string;
    executorsUzs: string;
    /** Доля накладных на проект за месяц (последний полный месяц) */
    overheadUzs: string;
    marginUzs: string;
    marginPct: string | null;
  } | null;
}

/** Финансовые настройки: делитель накладных (null — по числу проектов в месяце). */
export const financeSettingsSchema = z.object({
  overheadDivisor: z.coerce.number().int().min(1).max(1000).nullable(),
});
export type FinanceSettings = z.output<typeof financeSettingsSchema>;

export const COST_LINE_STATUSES = ['PLANNED', 'ACCRUED', 'CANCELLED'] as const;
export type CostLineStatus = (typeof COST_LINE_STATUSES)[number];

export interface ProjectCostLineDto {
  id: string;
  kind: TariffItemKind;
  label: string;
  specialty: Specialty | null;
  quantity: string;
  rate: string;
  currency: Currency;
  amount: string;
  /** Ставка — личная ставка исполнителя (иначе базовая) */
  personalRate: boolean;
  assignee: NamedRef | null;
  status: CostLineStatus;
  expense: { id: string; number: string } | null;
}

export const updateCostLineSchema = z.object({
  quantity: z.coerce.number().positive().max(100000).optional(),
  rate: moneySchema.optional(),
  assigneeId: z.uuid().nullable().optional(),
});
export type UpdateCostLineInput = z.input<typeof updateCostLineSchema>;

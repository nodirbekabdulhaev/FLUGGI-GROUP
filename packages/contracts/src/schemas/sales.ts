import { z } from 'zod';
import {
  COMMISSION_CALC_TYPES,
  CONTRACT_STATUSES,
  CURRENCIES,
  PAYMENT_METHODS,
  PAYMENT_STATUSES,
  PAYMENT_TYPES,
  PROPOSAL_STATUSES,
  type CommissionCalcType,
  type CommissionStatus,
  type ContractStatus,
  type Currency,
  type FileCategory,
  type PaymentMethod,
  type PaymentStatus,
  type PaymentType,
  type ProjectStatus,
  type ProposalStatus,
} from '../enums';
import { paginationQuerySchema } from './common';
import { dateOnly, moneySchema, optText } from './fields';
import type { NamedRef } from './references';

const numRef = (n: NamedRef & { number: string }) => n;
export type NumberedRef = ReturnType<typeof numRef>;

// ─────────────────────────── Файлы ───────────────────────────

export interface FileDto {
  id: string;
  originalName: string;
  mimeType: string;
  sizeBytes: number;
  category: FileCategory;
  uploadedBy: NamedRef;
  createdAt: string;
}

// ─────────────────────────── КП ───────────────────────────

export const proposalItemSchema = z.object({
  serviceId: z.uuid().nullish(),
  description: z.string().trim().min(1, 'Опишите позицию').max(500),
  quantity: z.coerce.number().positive('Количество > 0').max(1_000_000),
  unitPrice: moneySchema,
  discountPct: z.coerce.number().min(0).max(100).default(0),
});
export type ProposalItemInput = z.input<typeof proposalItemSchema>;

export const upsertProposalSchema = z.object({
  title: z.string().trim().min(1, 'Укажите название').max(200),
  description: z.string().trim().max(5000).nullish(),
  currency: z.enum(CURRENCIES).default('UZS'),
  items: z.array(proposalItemSchema).min(1, 'Добавьте хотя бы одну позицию').max(100),
  implementationTerm: z.string().trim().max(200).nullish(),
  paymentTerms: z.string().trim().max(1000).nullish(),
  validUntil: dateOnly.nullish(),
  /** Комментарий к версии (что изменилось). */
  versionComment: optText(500),
});
export type UpsertProposalInput = z.input<typeof upsertProposalSchema>;

export const createProposalSchema = upsertProposalSchema.extend({ dealId: z.uuid() });
export type CreateProposalInput = z.input<typeof createProposalSchema>;

export const proposalListQuerySchema = paginationQuerySchema.extend({
  q: z.string().trim().max(100).optional(),
  status: z.enum(PROPOSAL_STATUSES).optional(),
  dealId: z.uuid().optional(),
  managerId: z.uuid().optional(),
});
export type ProposalListQuery = Partial<z.output<typeof proposalListQuerySchema>>;

export interface ProposalItemDto {
  id: string;
  service: NamedRef | null;
  description: string;
  quantity: string;
  unitPrice: string;
  discountPct: string;
  total: string;
}

export interface ProposalVersionDto {
  version: number;
  total: string;
  currency: Currency;
  author: NamedRef;
  comment: string | null;
  createdAt: string;
}

export interface ProposalDto {
  id: string;
  number: string;
  title: string;
  description: string | null;
  deal: NumberedRef;
  client: NamedRef;
  manager: NamedRef;
  status: ProposalStatus;
  currency: Currency;
  subtotal: string;
  discountAmount: string;
  total: string;
  totalUzs: string;
  implementationTerm: string | null;
  paymentTerms: string | null;
  validUntil: string | null;
  currentVersion: number;
  approvedBy: NamedRef | null;
  approvedAt: string | null;
  sentAt: string | null;
  viewedAt: string | null;
  acceptedAt: string | null;
  rejectedAt: string | null;
  createdAt: string;
  updatedAt: string;
  items: ProposalItemDto[];
}

// ─────────────────────────── Договоры ───────────────────────────

export const createContractSchema = z.object({
  dealId: z.uuid(),
  proposalId: z.uuid().optional(),
  contractDate: dateOnly,
  amount: moneySchema,
  currency: z.enum(CURRENCIES).default('UZS'),
  comment: optText(2000),
});
export type CreateContractInput = z.input<typeof createContractSchema>;

export const updateContractSchema = z
  .object({
    contractDate: dateOnly,
    amount: moneySchema,
    currency: z.enum(CURRENCIES),
    comment: z.string().trim().max(2000).nullable(),
  })
  .partial();
export type UpdateContractInput = z.input<typeof updateContractSchema>;

export const contractListQuerySchema = paginationQuerySchema.extend({
  q: z.string().trim().max(100).optional(),
  status: z.enum(CONTRACT_STATUSES).optional(),
  dealId: z.uuid().optional(),
});
export type ContractListQuery = Partial<z.output<typeof contractListQuerySchema>>;

export interface ContractDto {
  id: string;
  number: string;
  deal: NumberedRef;
  client: NamedRef;
  proposal: NumberedRef | null;
  contractDate: string;
  amount: string;
  currency: Currency;
  amountUzs: string;
  status: ContractStatus;
  signedAt: string | null;
  comment: string | null;
  paidUzs: string;
  files: FileDto[];
  createdBy: NamedRef;
  createdAt: string;
}

// ─────────────────────────── Оплаты ───────────────────────────

export const createPaymentSchema = z.object({
  dealId: z.uuid(),
  contractId: z.uuid().optional(),
  amount: moneySchema.refine((v) => Number(v) > 0, 'Сумма должна быть больше нуля'),
  currency: z.enum(CURRENCIES).default('UZS'),
  type: z.enum(PAYMENT_TYPES.filter((t) => t !== 'REFUND') as [PaymentType, ...PaymentType[]]),
  method: z.enum(PAYMENT_METHODS),
  dueDate: dateOnly.optional(),
  comment: optText(2000),
});
export type CreatePaymentInput = z.input<typeof createPaymentSchema>;

export const confirmPaymentSchema = z.object({
  /** Фактическая дата оплаты; по умолчанию — сейчас. */
  paidAt: z.iso.datetime({ offset: true }).optional(),
});

export const refundPaymentSchema = z.object({
  amount: moneySchema.refine((v) => Number(v) > 0, 'Сумма должна быть больше нуля'),
  method: z.enum(PAYMENT_METHODS),
  comment: z.string().trim().min(1, 'Укажите причину возврата').max(2000),
});
export type RefundPaymentInput = z.input<typeof refundPaymentSchema>;

export const paymentListQuerySchema = paginationQuerySchema.extend({
  status: z.enum(PAYMENT_STATUSES).optional(),
  type: z.enum(PAYMENT_TYPES).optional(),
  dealId: z.uuid().optional(),
  clientId: z.uuid().optional(),
  dateFrom: dateOnly.optional(),
  dateTo: dateOnly.optional(),
});
export type PaymentListQuery = Partial<z.output<typeof paymentListQuerySchema>>;

export interface PaymentDto {
  id: string;
  number: string;
  deal: NumberedRef;
  client: NamedRef;
  contract: NumberedRef | null;
  project: NumberedRef | null;
  refundOf: NumberedRef | null;
  amount: string;
  currency: Currency;
  exchangeRate: string;
  amountUzs: string;
  type: PaymentType;
  method: PaymentMethod;
  status: PaymentStatus;
  dueDate: string | null;
  paidAt: string | null;
  comment: string | null;
  confirmedBy: NamedRef | null;
  createdBy: NamedRef;
  createdAt: string;
  /** Можно ли вернуть ещё (остаток после прошлых возвратов), UZS. */
  refundableUzs: string;
}

export interface ConfirmPaymentResult {
  payment: PaymentDto;
  project: NumberedRef | null;
  projectCreated: boolean;
  commissions: CommissionDto[];
}

/** Сводка денег по сделке для карточки. */
export interface DealMoneyDto {
  contractUzs: string;
  paidUzs: string;
  refundedUzs: string;
  receivableUzs: string;
  project: (NumberedRef & { status: ProjectStatus }) | null;
}

// ─────────────────────────── Комиссии ───────────────────────────

export interface CommissionDto {
  id: string;
  user: NamedRef;
  role: 'MANAGER' | 'ROP';
  payment: NumberedRef;
  deal: NumberedRef;
  rule: NamedRef;
  period: string;
  baseAmountUzs: string;
  rate: string;
  amountUzs: string;
  status: CommissionStatus;
  createdAt: string;
}

export const commissionListQuerySchema = paginationQuerySchema.extend({
  period: z
    .string()
    .regex(/^\d{4}-\d{2}$/)
    .optional(),
  userId: z.uuid().optional(),
});
export type CommissionListQuery = Partial<z.output<typeof commissionListQuerySchema>>;

const conditionLeaf = z.object({
  metric: z.enum(['avg_check_usd', 'avg_check_uzs', 'orders_count', 'revenue_uzs', 'revenue_usd']),
  op: z.enum(['>', '>=', '<', '<=', '=']),
  value: z.number(),
});
type ConditionShape =
  z.infer<typeof conditionLeaf> | { all?: ConditionShape[]; any?: ConditionShape[] };
export const conditionSchema: z.ZodType<ConditionShape> = z.lazy(() =>
  z.union([
    conditionLeaf,
    z.object({
      all: z.array(conditionSchema).optional(),
      any: z.array(conditionSchema).optional(),
    }),
  ]),
);

export const upsertCommissionRuleSchema = z.object({
  name: z.string().trim().min(1).max(120),
  appliesTo: z.enum(['MANAGER', 'ROP']),
  userId: z.uuid().nullish(),
  calcType: z.enum(COMMISSION_CALC_TYPES),
  value: z.coerce.number().min(0).max(1_000_000_000),
  conditions: conditionSchema.nullish(),
  priority: z.coerce.number().int().min(0).max(1000).default(0),
  isActive: z.boolean().default(true),
});
export type UpsertCommissionRuleInput = z.input<typeof upsertCommissionRuleSchema>;

export interface CommissionRuleDto {
  id: string;
  name: string;
  appliesTo: 'MANAGER' | 'ROP';
  user: NamedRef | null;
  calcType: CommissionCalcType;
  value: string;
  conditions: ConditionShape | null;
  priority: number;
  isActive: boolean;
}

import { z } from 'zod';
import { type CommissionCalcType, type CommissionStatus, type ContractStatus, type Currency, type FileCategory, type PaymentMethod, type PaymentStatus, type PaymentType, type ProjectStatus, type ProposalStatus } from '../enums';
import type { NamedRef } from './references';
declare const numRef: (n: NamedRef & {
    number: string;
}) => NamedRef & {
    number: string;
};
export type NumberedRef = ReturnType<typeof numRef>;
export interface FileDto {
    id: string;
    originalName: string;
    mimeType: string;
    sizeBytes: number;
    category: FileCategory;
    uploadedBy: NamedRef;
    createdAt: string;
}
export declare const proposalItemSchema: z.ZodObject<{
    serviceId: z.ZodOptional<z.ZodNullable<z.ZodUUID>>;
    tariffId: z.ZodOptional<z.ZodNullable<z.ZodUUID>>;
    description: z.ZodString;
    quantity: z.ZodCoercedNumber<unknown>;
    unitPrice: z.ZodPipe<z.ZodPipe<z.ZodUnion<readonly [z.ZodString, z.ZodNumber]>, z.ZodTransform<string, string | number>>, z.ZodString>;
    discountPct: z.ZodDefault<z.ZodCoercedNumber<unknown>>;
}, z.core.$strip>;
export type ProposalItemInput = z.input<typeof proposalItemSchema>;
export declare const upsertProposalSchema: z.ZodObject<{
    title: z.ZodString;
    description: z.ZodOptional<z.ZodNullable<z.ZodString>>;
    currency: z.ZodDefault<z.ZodEnum<{
        UZS: "UZS";
        USD: "USD";
    }>>;
    items: z.ZodArray<z.ZodObject<{
        serviceId: z.ZodOptional<z.ZodNullable<z.ZodUUID>>;
        tariffId: z.ZodOptional<z.ZodNullable<z.ZodUUID>>;
        description: z.ZodString;
        quantity: z.ZodCoercedNumber<unknown>;
        unitPrice: z.ZodPipe<z.ZodPipe<z.ZodUnion<readonly [z.ZodString, z.ZodNumber]>, z.ZodTransform<string, string | number>>, z.ZodString>;
        discountPct: z.ZodDefault<z.ZodCoercedNumber<unknown>>;
    }, z.core.$strip>>;
    implementationTerm: z.ZodOptional<z.ZodNullable<z.ZodString>>;
    paymentTerms: z.ZodOptional<z.ZodNullable<z.ZodString>>;
    validUntil: z.ZodOptional<z.ZodNullable<z.ZodISODate>>;
    versionComment: z.ZodPipe<z.ZodOptional<z.ZodString>, z.ZodTransform<string | undefined, string | undefined>>;
}, z.core.$strip>;
export type UpsertProposalInput = z.input<typeof upsertProposalSchema>;
export declare const createProposalSchema: z.ZodObject<{
    title: z.ZodString;
    description: z.ZodOptional<z.ZodNullable<z.ZodString>>;
    currency: z.ZodDefault<z.ZodEnum<{
        UZS: "UZS";
        USD: "USD";
    }>>;
    items: z.ZodArray<z.ZodObject<{
        serviceId: z.ZodOptional<z.ZodNullable<z.ZodUUID>>;
        tariffId: z.ZodOptional<z.ZodNullable<z.ZodUUID>>;
        description: z.ZodString;
        quantity: z.ZodCoercedNumber<unknown>;
        unitPrice: z.ZodPipe<z.ZodPipe<z.ZodUnion<readonly [z.ZodString, z.ZodNumber]>, z.ZodTransform<string, string | number>>, z.ZodString>;
        discountPct: z.ZodDefault<z.ZodCoercedNumber<unknown>>;
    }, z.core.$strip>>;
    implementationTerm: z.ZodOptional<z.ZodNullable<z.ZodString>>;
    paymentTerms: z.ZodOptional<z.ZodNullable<z.ZodString>>;
    validUntil: z.ZodOptional<z.ZodNullable<z.ZodISODate>>;
    versionComment: z.ZodPipe<z.ZodOptional<z.ZodString>, z.ZodTransform<string | undefined, string | undefined>>;
    dealId: z.ZodUUID;
}, z.core.$strip>;
export type CreateProposalInput = z.input<typeof createProposalSchema>;
export declare const proposalListQuerySchema: z.ZodObject<{
    page: z.ZodDefault<z.ZodCoercedNumber<unknown>>;
    pageSize: z.ZodDefault<z.ZodCoercedNumber<unknown>>;
    q: z.ZodOptional<z.ZodString>;
    status: z.ZodOptional<z.ZodEnum<{
        REJECTED: "REJECTED";
        DRAFT: "DRAFT";
        SENT: "SENT";
        VIEWED: "VIEWED";
        IN_APPROVAL: "IN_APPROVAL";
        ACCEPTED: "ACCEPTED";
        EXPIRED: "EXPIRED";
    }>>;
    dealId: z.ZodOptional<z.ZodUUID>;
    managerId: z.ZodOptional<z.ZodUUID>;
}, z.core.$strip>;
export type ProposalListQuery = Partial<z.output<typeof proposalListQuerySchema>>;
export interface ProposalItemDto {
    id: string;
    service: NamedRef | null;
    tariff: NamedRef | null;
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
export declare const createContractSchema: z.ZodObject<{
    dealId: z.ZodUUID;
    proposalId: z.ZodOptional<z.ZodUUID>;
    contractDate: z.ZodISODate;
    amount: z.ZodPipe<z.ZodPipe<z.ZodUnion<readonly [z.ZodString, z.ZodNumber]>, z.ZodTransform<string, string | number>>, z.ZodString>;
    currency: z.ZodDefault<z.ZodEnum<{
        UZS: "UZS";
        USD: "USD";
    }>>;
    comment: z.ZodPipe<z.ZodOptional<z.ZodString>, z.ZodTransform<string | undefined, string | undefined>>;
}, z.core.$strip>;
export type CreateContractInput = z.input<typeof createContractSchema>;
export declare const updateContractSchema: z.ZodObject<{
    contractDate: z.ZodOptional<z.ZodISODate>;
    amount: z.ZodOptional<z.ZodPipe<z.ZodPipe<z.ZodUnion<readonly [z.ZodString, z.ZodNumber]>, z.ZodTransform<string, string | number>>, z.ZodString>>;
    currency: z.ZodOptional<z.ZodEnum<{
        UZS: "UZS";
        USD: "USD";
    }>>;
    comment: z.ZodOptional<z.ZodNullable<z.ZodString>>;
}, z.core.$strip>;
export type UpdateContractInput = z.input<typeof updateContractSchema>;
export declare const contractListQuerySchema: z.ZodObject<{
    page: z.ZodDefault<z.ZodCoercedNumber<unknown>>;
    pageSize: z.ZodDefault<z.ZodCoercedNumber<unknown>>;
    q: z.ZodOptional<z.ZodString>;
    status: z.ZodOptional<z.ZodEnum<{
        CANCELLED: "CANCELLED";
        DRAFT: "DRAFT";
        SENT: "SENT";
        IN_APPROVAL: "IN_APPROVAL";
        SIGNED: "SIGNED";
    }>>;
    dealId: z.ZodOptional<z.ZodUUID>;
}, z.core.$strip>;
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
export declare const createPaymentSchema: z.ZodObject<{
    dealId: z.ZodUUID;
    contractId: z.ZodOptional<z.ZodUUID>;
    amount: z.ZodPipe<z.ZodPipe<z.ZodUnion<readonly [z.ZodString, z.ZodNumber]>, z.ZodTransform<string, string | number>>, z.ZodString>;
    currency: z.ZodDefault<z.ZodEnum<{
        UZS: "UZS";
        USD: "USD";
    }>>;
    type: z.ZodEnum<{
        PREPAYMENT: "PREPAYMENT";
        PARTIAL: "PARTIAL";
        FULL: "FULL";
        FINAL: "FINAL";
        REFUND: "REFUND";
    }>;
    method: z.ZodEnum<{
        CASH: "CASH";
        BANK: "BANK";
        CARD: "CARD";
        TRANSFER: "TRANSFER";
        OTHER: "OTHER";
    }>;
    dueDate: z.ZodOptional<z.ZodISODate>;
    comment: z.ZodPipe<z.ZodOptional<z.ZodString>, z.ZodTransform<string | undefined, string | undefined>>;
}, z.core.$strip>;
export type CreatePaymentInput = z.input<typeof createPaymentSchema>;
/** Исправление неподтверждённой оплаты (ошиблись в сумме, типе, способе). */
export declare const updatePaymentSchema: z.ZodObject<{
    contractId: z.ZodOptional<z.ZodNullable<z.ZodUUID>>;
    amount: z.ZodOptional<z.ZodPipe<z.ZodPipe<z.ZodUnion<readonly [z.ZodString, z.ZodNumber]>, z.ZodTransform<string, string | number>>, z.ZodString>>;
    currency: z.ZodOptional<z.ZodEnum<{
        UZS: "UZS";
        USD: "USD";
    }>>;
    type: z.ZodOptional<z.ZodEnum<{
        PREPAYMENT: "PREPAYMENT";
        PARTIAL: "PARTIAL";
        FULL: "FULL";
        FINAL: "FINAL";
        REFUND: "REFUND";
    }>>;
    method: z.ZodOptional<z.ZodEnum<{
        CASH: "CASH";
        BANK: "BANK";
        CARD: "CARD";
        TRANSFER: "TRANSFER";
        OTHER: "OTHER";
    }>>;
    dueDate: z.ZodOptional<z.ZodNullable<z.ZodISODate>>;
    comment: z.ZodOptional<z.ZodNullable<z.ZodString>>;
}, z.core.$strip>;
export type UpdatePaymentInput = z.input<typeof updatePaymentSchema>;
export declare const confirmPaymentSchema: z.ZodObject<{
    paidAt: z.ZodOptional<z.ZodISODateTime>;
}, z.core.$strip>;
export declare const refundPaymentSchema: z.ZodObject<{
    amount: z.ZodPipe<z.ZodPipe<z.ZodUnion<readonly [z.ZodString, z.ZodNumber]>, z.ZodTransform<string, string | number>>, z.ZodString>;
    method: z.ZodEnum<{
        CASH: "CASH";
        BANK: "BANK";
        CARD: "CARD";
        TRANSFER: "TRANSFER";
        OTHER: "OTHER";
    }>;
    comment: z.ZodString;
}, z.core.$strip>;
export type RefundPaymentInput = z.input<typeof refundPaymentSchema>;
export declare const paymentListQuerySchema: z.ZodObject<{
    page: z.ZodDefault<z.ZodCoercedNumber<unknown>>;
    pageSize: z.ZodDefault<z.ZodCoercedNumber<unknown>>;
    status: z.ZodOptional<z.ZodEnum<{
        PAID: "PAID";
        CANCELLED: "CANCELLED";
        PENDING: "PENDING";
    }>>;
    type: z.ZodOptional<z.ZodEnum<{
        PREPAYMENT: "PREPAYMENT";
        PARTIAL: "PARTIAL";
        FULL: "FULL";
        FINAL: "FINAL";
        REFUND: "REFUND";
    }>>;
    dealId: z.ZodOptional<z.ZodUUID>;
    clientId: z.ZodOptional<z.ZodUUID>;
    dateFrom: z.ZodOptional<z.ZodISODate>;
    dateTo: z.ZodOptional<z.ZodISODate>;
}, z.core.$strip>;
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
    project: (NumberedRef & {
        status: ProjectStatus;
    }) | null;
}
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
    approvedBy: NamedRef | null;
    approvedAt: string | null;
    paidAt: string | null;
    createdAt: string;
}
export declare const commissionListQuerySchema: z.ZodObject<{
    page: z.ZodDefault<z.ZodCoercedNumber<unknown>>;
    pageSize: z.ZodDefault<z.ZodCoercedNumber<unknown>>;
    status: z.ZodOptional<z.ZodEnum<{
        PAID: "PAID";
        CANCELLED: "CANCELLED";
        APPROVED: "APPROVED";
        ACCRUED: "ACCRUED";
    }>>;
    period: z.ZodOptional<z.ZodString>;
    userId: z.ZodOptional<z.ZodUUID>;
}, z.core.$strip>;
export type CommissionListQuery = Partial<z.output<typeof commissionListQuerySchema>>;
export declare const commissionIdsSchema: z.ZodObject<{
    ids: z.ZodArray<z.ZodUUID>;
}, z.core.$strip>;
declare const conditionLeaf: z.ZodObject<{
    metric: z.ZodEnum<{
        avg_check_usd: "avg_check_usd";
        avg_check_uzs: "avg_check_uzs";
        orders_count: "orders_count";
        revenue_uzs: "revenue_uzs";
        revenue_usd: "revenue_usd";
    }>;
    op: z.ZodEnum<{
        ">": ">";
        ">=": ">=";
        "<": "<";
        "<=": "<=";
        "=": "=";
    }>;
    value: z.ZodNumber;
}, z.core.$strip>;
type ConditionShape = z.infer<typeof conditionLeaf> | {
    all?: ConditionShape[];
    any?: ConditionShape[];
};
export declare const conditionSchema: z.ZodType<ConditionShape>;
export declare const upsertCommissionRuleSchema: z.ZodObject<{
    name: z.ZodString;
    appliesTo: z.ZodEnum<{
        ROP: "ROP";
        MANAGER: "MANAGER";
    }>;
    userId: z.ZodOptional<z.ZodNullable<z.ZodUUID>>;
    calcType: z.ZodEnum<{
        PERCENT_OF_PAYMENT: "PERCENT_OF_PAYMENT";
        PERCENT_OF_PROFIT: "PERCENT_OF_PROFIT";
        FIXED_PER_DEAL: "FIXED_PER_DEAL";
    }>;
    value: z.ZodCoercedNumber<unknown>;
    conditions: z.ZodOptional<z.ZodNullable<z.ZodType<ConditionShape, unknown, z.core.$ZodTypeInternals<ConditionShape, unknown>>>>;
    priority: z.ZodDefault<z.ZodCoercedNumber<unknown>>;
    isActive: z.ZodDefault<z.ZodBoolean>;
}, z.core.$strip>;
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
export {};

import { z } from 'zod';
import { type Currency, type ExecutorSpecialty } from '../enums';
import type { NamedRef } from './references';
import type { NumberedRef } from './sales';
export declare const FINANCE_CATEGORY_KINDS: readonly ["EXPENSE", "INCOME"];
export type FinanceCategoryKind = (typeof FINANCE_CATEGORY_KINDS)[number];
export declare const financeCategorySchema: z.ZodObject<{
    kind: z.ZodEnum<{
        EXPENSE: "EXPENSE";
        INCOME: "INCOME";
    }>;
    name: z.ZodString;
    accountHint: z.ZodOptional<z.ZodNullable<z.ZodString>>;
    isOverhead: z.ZodDefault<z.ZodBoolean>;
    isActive: z.ZodDefault<z.ZodBoolean>;
    sort: z.ZodDefault<z.ZodCoercedNumber<unknown>>;
}, z.core.$strip>;
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
export declare const otherIncomeSchema: z.ZodObject<{
    category: z.ZodString;
    amount: z.ZodPipe<z.ZodPipe<z.ZodUnion<readonly [z.ZodString, z.ZodNumber]>, z.ZodTransform<string, string | number>>, z.ZodString>;
    currency: z.ZodDefault<z.ZodEnum<{
        UZS: "UZS";
        USD: "USD";
    }>>;
    incomeDate: z.ZodISODate;
    projectId: z.ZodOptional<z.ZodNullable<z.ZodUUID>>;
    clientId: z.ZodOptional<z.ZodNullable<z.ZodUUID>>;
    description: z.ZodOptional<z.ZodNullable<z.ZodString>>;
}, z.core.$strip>;
export type OtherIncomeInput = z.input<typeof otherIncomeSchema>;
export declare const otherIncomeListQuerySchema: z.ZodObject<{
    category: z.ZodOptional<z.ZodString>;
    dateFrom: z.ZodOptional<z.ZodISODate>;
    dateTo: z.ZodOptional<z.ZodISODate>;
    page: z.ZodDefault<z.ZodCoercedNumber<unknown>>;
    pageSize: z.ZodDefault<z.ZodCoercedNumber<unknown>>;
}, z.core.$strip>;
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
export declare const EXECUTOR_SPECIALTIES_ALL: readonly ["SMM", "DESIGNER", "VIDEOGRAPHER", "EDITOR", "TARGETOLOGIST", "DEVELOPER", "PHOTOGRAPHER", "COPYWRITER", "MOBILOGRAPHER", "BRANDFACE"];
export type Specialty = ExecutorSpecialty;
export declare const workItemSchema: z.ZodObject<{
    name: z.ZodString;
    unit: z.ZodDefault<z.ZodString>;
    specialty: z.ZodOptional<z.ZodNullable<z.ZodEnum<{
        SMM: "SMM";
        DESIGNER: "DESIGNER";
        VIDEOGRAPHER: "VIDEOGRAPHER";
        EDITOR: "EDITOR";
        TARGETOLOGIST: "TARGETOLOGIST";
        DEVELOPER: "DEVELOPER";
        PHOTOGRAPHER: "PHOTOGRAPHER";
        COPYWRITER: "COPYWRITER";
        MOBILOGRAPHER: "MOBILOGRAPHER";
        BRANDFACE: "BRANDFACE";
    }>>>;
    defaultRate: z.ZodPipe<z.ZodPipe<z.ZodUnion<readonly [z.ZodString, z.ZodNumber]>, z.ZodTransform<string, string | number>>, z.ZodString>;
    currency: z.ZodDefault<z.ZodEnum<{
        UZS: "UZS";
        USD: "USD";
    }>>;
    isActive: z.ZodDefault<z.ZodBoolean>;
}, z.core.$strip>;
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
export declare const employeeRatesSchema: z.ZodObject<{
    rates: z.ZodArray<z.ZodObject<{
        workItemId: z.ZodUUID;
        rate: z.ZodNullable<z.ZodPipe<z.ZodPipe<z.ZodUnion<readonly [z.ZodString, z.ZodNumber]>, z.ZodTransform<string, string | number>>, z.ZodString>>;
        currency: z.ZodDefault<z.ZodEnum<{
            UZS: "UZS";
            USD: "USD";
        }>>;
    }, z.core.$strip>>;
}, z.core.$strip>;
export type EmployeeRatesInput = z.input<typeof employeeRatesSchema>;
export interface EmployeeRateDto {
    workItem: WorkItemDto;
    /** null — действует базовая ставка */
    rate: string | null;
    currency: Currency | null;
}
export declare const TARIFF_ITEM_KINDS: readonly ["PIECE", "FIXED"];
export type TariffItemKind = (typeof TARIFF_ITEM_KINDS)[number];
export declare const tariffItemSchema: z.ZodObject<{
    kind: z.ZodEnum<{
        FIXED: "FIXED";
        PIECE: "PIECE";
    }>;
    workItemId: z.ZodOptional<z.ZodNullable<z.ZodUUID>>;
    quantity: z.ZodDefault<z.ZodCoercedNumber<unknown>>;
    specialty: z.ZodOptional<z.ZodNullable<z.ZodEnum<{
        SMM: "SMM";
        DESIGNER: "DESIGNER";
        VIDEOGRAPHER: "VIDEOGRAPHER";
        EDITOR: "EDITOR";
        TARGETOLOGIST: "TARGETOLOGIST";
        DEVELOPER: "DEVELOPER";
        PHOTOGRAPHER: "PHOTOGRAPHER";
        COPYWRITER: "COPYWRITER";
        MOBILOGRAPHER: "MOBILOGRAPHER";
        BRANDFACE: "BRANDFACE";
    }>>>;
    amount: z.ZodOptional<z.ZodNullable<z.ZodPipe<z.ZodPipe<z.ZodUnion<readonly [z.ZodString, z.ZodNumber]>, z.ZodTransform<string, string | number>>, z.ZodString>>>;
    currency: z.ZodDefault<z.ZodEnum<{
        UZS: "UZS";
        USD: "USD";
    }>>;
    label: z.ZodOptional<z.ZodNullable<z.ZodString>>;
}, z.core.$strip>;
export declare const tariffSchema: z.ZodObject<{
    serviceId: z.ZodUUID;
    name: z.ZodString;
    description: z.ZodOptional<z.ZodNullable<z.ZodString>>;
    price: z.ZodPipe<z.ZodPipe<z.ZodUnion<readonly [z.ZodString, z.ZodNumber]>, z.ZodTransform<string, string | number>>, z.ZodString>;
    currency: z.ZodDefault<z.ZodEnum<{
        UZS: "UZS";
        USD: "USD";
    }>>;
    isActive: z.ZodDefault<z.ZodBoolean>;
    sort: z.ZodDefault<z.ZodCoercedNumber<unknown>>;
    items: z.ZodDefault<z.ZodArray<z.ZodObject<{
        kind: z.ZodEnum<{
            FIXED: "FIXED";
            PIECE: "PIECE";
        }>;
        workItemId: z.ZodOptional<z.ZodNullable<z.ZodUUID>>;
        quantity: z.ZodDefault<z.ZodCoercedNumber<unknown>>;
        specialty: z.ZodOptional<z.ZodNullable<z.ZodEnum<{
            SMM: "SMM";
            DESIGNER: "DESIGNER";
            VIDEOGRAPHER: "VIDEOGRAPHER";
            EDITOR: "EDITOR";
            TARGETOLOGIST: "TARGETOLOGIST";
            DEVELOPER: "DEVELOPER";
            PHOTOGRAPHER: "PHOTOGRAPHER";
            COPYWRITER: "COPYWRITER";
            MOBILOGRAPHER: "MOBILOGRAPHER";
            BRANDFACE: "BRANDFACE";
        }>>>;
        amount: z.ZodOptional<z.ZodNullable<z.ZodPipe<z.ZodPipe<z.ZodUnion<readonly [z.ZodString, z.ZodNumber]>, z.ZodTransform<string, string | number>>, z.ZodString>>>;
        currency: z.ZodDefault<z.ZodEnum<{
            UZS: "UZS";
            USD: "USD";
        }>>;
        label: z.ZodOptional<z.ZodNullable<z.ZodString>>;
    }, z.core.$strip>>>;
}, z.core.$strip>;
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
export declare const financeSettingsSchema: z.ZodObject<{
    overheadDivisor: z.ZodNullable<z.ZodCoercedNumber<unknown>>;
}, z.core.$strip>;
export type FinanceSettings = z.output<typeof financeSettingsSchema>;
export declare const COST_LINE_STATUSES: readonly ["PLANNED", "ACCRUED", "CANCELLED"];
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
    expense: {
        id: string;
        number: string;
    } | null;
}
export declare const updateCostLineSchema: z.ZodObject<{
    quantity: z.ZodOptional<z.ZodCoercedNumber<unknown>>;
    rate: z.ZodOptional<z.ZodPipe<z.ZodPipe<z.ZodUnion<readonly [z.ZodString, z.ZodNumber]>, z.ZodTransform<string, string | number>>, z.ZodString>>;
    assigneeId: z.ZodOptional<z.ZodNullable<z.ZodUUID>>;
}, z.core.$strip>;
export type UpdateCostLineInput = z.input<typeof updateCostLineSchema>;

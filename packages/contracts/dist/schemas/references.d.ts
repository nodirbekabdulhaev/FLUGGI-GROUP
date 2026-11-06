import { z } from 'zod';
import { type Currency, type PricingType } from '../enums';
export interface NamedRef {
    id: string;
    name: string;
}
export interface ServiceDto {
    id: string;
    code: string;
    name: string;
    nameUz: string | null;
    nameEn: string | null;
    description: string | null;
    basePrice: string | null;
    minPrice: string | null;
    currency: Currency;
    pricingType: PricingType;
    /** Направление бизнеса (IT, Медиа, Маркетинг) */
    directionId: string | null;
    isActive: boolean;
    sort: number;
}
/** Направление бизнеса группы: проекты направления видит проект-менеджер направления. */
export interface DirectionDto {
    id: string;
    code: string;
    name: string;
    sort: number;
    isActive: boolean;
}
export declare const directionSchema: z.ZodObject<{
    name: z.ZodString;
    sort: z.ZodDefault<z.ZodCoercedNumber<unknown>>;
    isActive: z.ZodDefault<z.ZodBoolean>;
}, z.core.$strip>;
export type DirectionInput = z.input<typeof directionSchema>;
export interface ReferenceItemDto {
    id: string;
    code: string;
    name: string;
    nameUz: string | null;
    nameEn: string | null;
    isActive: boolean;
    sort: number;
    requiresComment?: boolean;
}
export interface StageDto {
    id: string;
    code: string;
    entity: 'LEAD' | 'DEAL';
    name: string;
    sort: number;
    probability: number;
    color: string;
}
export interface ExchangeRateDto {
    id: string;
    date: string;
    currency: Currency;
    rateToUzs: string;
    setBy: NamedRef | null;
}
export interface ReferencesDto {
    services: ServiceDto[];
    directions: DirectionDto[];
    sources: ReferenceItemDto[];
    lossReasons: ReferenceItemDto[];
    stages: StageDto[];
    usdRate: ExchangeRateDto | null;
}
export declare const upsertReferenceItemSchema: z.ZodObject<{
    code: z.ZodString;
    name: z.ZodString;
    nameUz: z.ZodOptional<z.ZodNullable<z.ZodString>>;
    nameEn: z.ZodOptional<z.ZodNullable<z.ZodString>>;
    isActive: z.ZodDefault<z.ZodBoolean>;
    sort: z.ZodDefault<z.ZodCoercedNumber<unknown>>;
    requiresComment: z.ZodOptional<z.ZodBoolean>;
}, z.core.$strip>;
export type UpsertReferenceItemInput = z.input<typeof upsertReferenceItemSchema>;
export declare const upsertServiceSchema: z.ZodObject<{
    name: z.ZodString;
    sort: z.ZodDefault<z.ZodCoercedNumber<unknown>>;
    isActive: z.ZodDefault<z.ZodBoolean>;
    code: z.ZodString;
    nameUz: z.ZodOptional<z.ZodNullable<z.ZodString>>;
    nameEn: z.ZodOptional<z.ZodNullable<z.ZodString>>;
    description: z.ZodOptional<z.ZodNullable<z.ZodString>>;
    basePrice: z.ZodPipe<z.ZodPipe<z.ZodOptional<z.ZodUnion<readonly [z.ZodString, z.ZodNumber]>>, z.ZodTransform<string | number | undefined, string | number | undefined>>, z.ZodOptional<z.ZodPipe<z.ZodPipe<z.ZodUnion<readonly [z.ZodString, z.ZodNumber]>, z.ZodTransform<string, string | number>>, z.ZodString>>>;
    minPrice: z.ZodPipe<z.ZodPipe<z.ZodOptional<z.ZodUnion<readonly [z.ZodString, z.ZodNumber]>>, z.ZodTransform<string | number | undefined, string | number | undefined>>, z.ZodOptional<z.ZodPipe<z.ZodPipe<z.ZodUnion<readonly [z.ZodString, z.ZodNumber]>, z.ZodTransform<string, string | number>>, z.ZodString>>>;
    currency: z.ZodDefault<z.ZodEnum<{
        UZS: "UZS";
        USD: "USD";
    }>>;
    pricingType: z.ZodDefault<z.ZodEnum<{
        FIXED: "FIXED";
        MONTHLY: "MONTHLY";
        HOURLY: "HOURLY";
        CUSTOM: "CUSTOM";
    }>>;
    directionId: z.ZodOptional<z.ZodNullable<z.ZodUUID>>;
}, z.core.$strip>;
export type UpsertServiceInput = z.input<typeof upsertServiceSchema>;
export declare const updateStageSchema: z.ZodObject<{
    name: z.ZodOptional<z.ZodString>;
    probability: z.ZodOptional<z.ZodCoercedNumber<unknown>>;
    color: z.ZodOptional<z.ZodString>;
}, z.core.$strip>;
export type UpdateStageInput = z.input<typeof updateStageSchema>;
export declare const setExchangeRateSchema: z.ZodObject<{
    currency: z.ZodLiteral<"USD">;
    rateToUzs: z.ZodCoercedNumber<unknown>;
    date: z.ZodOptional<z.ZodISODate>;
}, z.core.$strip>;
export type SetExchangeRateInput = z.input<typeof setExchangeRateSchema>;

import { z } from 'zod';
import { CURRENCIES, PRICING_TYPES, type Currency, type PricingType } from '../enums';
import { optMoney } from './fields';

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

export const directionSchema = z.object({
  name: z.string().trim().min(1, 'Введите название').max(80),
  sort: z.coerce.number().int().min(0).max(1000).default(100),
  isActive: z.boolean().default(true),
});
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

const code = z
  .string()
  .trim()
  .min(2)
  .max(40)
  .regex(/^[A-Z0-9_]+$/, 'Только латинские заглавные буквы, цифры и _');

export const upsertReferenceItemSchema = z.object({
  code,
  name: z.string().trim().min(1, 'Укажите название').max(120),
  nameUz: z.string().trim().max(120).nullish(),
  nameEn: z.string().trim().max(120).nullish(),
  isActive: z.boolean().default(true),
  sort: z.coerce.number().int().min(0).max(10000).default(0),
  requiresComment: z.boolean().optional(),
});
export type UpsertReferenceItemInput = z.input<typeof upsertReferenceItemSchema>;

export const upsertServiceSchema = upsertReferenceItemSchema
  .omit({ requiresComment: true })
  .extend({
    description: z.string().trim().max(2000).nullish(),
    basePrice: optMoney,
    minPrice: optMoney,
    currency: z.enum(CURRENCIES).default('UZS'),
    pricingType: z.enum(PRICING_TYPES).default('FIXED'),
    directionId: z.uuid().nullish(),
  });
export type UpsertServiceInput = z.input<typeof upsertServiceSchema>;

export const updateStageSchema = z.object({
  name: z.string().trim().min(1).max(80).optional(),
  probability: z.coerce.number().int().min(0).max(100).optional(),
  color: z
    .string()
    .regex(/^#[0-9a-fA-F]{6}$/)
    .optional(),
});
export type UpdateStageInput = z.input<typeof updateStageSchema>;

export const setExchangeRateSchema = z.object({
  currency: z.literal('USD'),
  rateToUzs: z.coerce.number().positive('Курс должен быть больше нуля').max(1_000_000),
  date: z.iso.date().optional(),
});
export type SetExchangeRateInput = z.input<typeof setExchangeRateSchema>;

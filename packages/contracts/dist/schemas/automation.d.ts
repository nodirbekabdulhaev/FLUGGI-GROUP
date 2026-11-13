import { z } from 'zod';
import type { RoleCode } from '../enums';
import type { NamedRef } from './references';
import type { NumberedRef } from './sales';
export declare const NOTIFICATION_CHANNELS: readonly ["IN_APP", "TELEGRAM"];
export type NotificationChannel = (typeof NOTIFICATION_CHANNELS)[number];
/** Типы уведомлений и роли, которым они приходят. Используется в настройках пользователя. */
export declare const NOTIFICATION_EVENTS: {
    type: string;
    label: string;
    roles: RoleCode[];
}[];
/** Близкие типы уведомлений настраиваются одним переключателем. */
export declare const NOTIFICATION_TYPE_GROUP: Record<string, string>;
export declare const notificationSettingsSchema: z.ZodObject<{
    settings: z.ZodArray<z.ZodObject<{
        eventType: z.ZodString;
        channel: z.ZodEnum<{
            TELEGRAM: "TELEGRAM";
            IN_APP: "IN_APP";
        }>;
        enabled: z.ZodBoolean;
    }, z.core.$strip>>;
}, z.core.$strip>;
export type NotificationSettingsInput = z.input<typeof notificationSettingsSchema>;
export interface NotificationSettingDto {
    type: string;
    label: string;
    inApp: boolean;
    telegram: boolean;
}
export interface TelegramStatusDto {
    /** Бот настроен на сервере (есть токен). */
    botConfigured: boolean;
    botUsername: string | null;
    linked: boolean;
    username: string | null;
    /** Бот настроен, но не работает: причина для пользователя (неверный токен, нет связи и т.п.). */
    problem: string | null;
}
export interface TelegramLinkDto {
    /** Ссылка https://t.me/<bot>?start=<код> */
    deepLink: string;
    /** Команда, которую можно отправить боту вручную */
    command: string;
    expiresAt: string;
}
export declare const FOLLOW_UP_KINDS: readonly ["CONTACT", "NEW_PROJECT", "REPEAT_SALE"];
export type FollowUpKind = (typeof FOLLOW_UP_KINDS)[number];
export declare const FOLLOW_UP_STATUSES: readonly ["PENDING", "DONE", "SKIPPED"];
export type FollowUpStatus = (typeof FOLLOW_UP_STATUSES)[number];
export declare const followUpListQuerySchema: z.ZodObject<{
    status: z.ZodOptional<z.ZodEnum<{
        DONE: "DONE";
        PENDING: "PENDING";
        SKIPPED: "SKIPPED";
    }>>;
    due: z.ZodPipe<z.ZodOptional<z.ZodEnum<{
        true: "true";
        false: "false";
    }>>, z.ZodTransform<boolean, "true" | "false" | undefined>>;
    clientId: z.ZodOptional<z.ZodUUID>;
    page: z.ZodDefault<z.ZodCoercedNumber<unknown>>;
    pageSize: z.ZodDefault<z.ZodCoercedNumber<unknown>>;
}, z.core.$strip>;
export type FollowUpListQuery = z.input<typeof followUpListQuerySchema>;
export declare const completeFollowUpSchema: z.ZodObject<{
    status: z.ZodEnum<{
        DONE: "DONE";
        SKIPPED: "SKIPPED";
    }>;
    result: z.ZodOptional<z.ZodString>;
    createDeal: z.ZodDefault<z.ZodBoolean>;
}, z.core.$strip>;
export type CompleteFollowUpInput = z.input<typeof completeFollowUpSchema>;
export interface FollowUpDto {
    id: string;
    client: NamedRef;
    project: NumberedRef | null;
    owner: NamedRef;
    kind: FollowUpKind;
    dueDate: string;
    overdueDays: number;
    status: FollowUpStatus;
    result: string | null;
    resultDeal: NumberedRef | null;
    completedAt: string | null;
}
export declare const automationSettingsSchema: z.ZodObject<{
    largeAmountUzs: z.ZodCoercedNumber<unknown>;
    followUps: z.ZodArray<z.ZodObject<{
        days: z.ZodCoercedNumber<unknown>;
        kind: z.ZodEnum<{
            CONTACT: "CONTACT";
            NEW_PROJECT: "NEW_PROJECT";
            REPEAT_SALE: "REPEAT_SALE";
        }>;
    }, z.core.$strip>>;
    dailyReport: z.ZodBoolean;
    weeklyReport: z.ZodBoolean;
}, z.core.$strip>;
export type AutomationSettings = z.output<typeof automationSettingsSchema>;
export declare const DEFAULT_AUTOMATION_SETTINGS: AutomationSettings;
export interface JobRunDto {
    job: string;
    slot: string;
    startedAt: string;
    finishedAt: string | null;
    error: string | null;
}
export interface ReportPreviewDto {
    text: string;
}
export declare const TAX_REGIMES: readonly ["IT_PARK", "TURNOVER", "GENERAL", "OTHER"];
export type TaxRegime = (typeof TAX_REGIMES)[number];
export declare const TAX_REGIME_LABELS: Record<TaxRegime, string>;
export declare const companySettingsSchema: z.ZodObject<{
    name: z.ZodString;
    inn: z.ZodString;
    director: z.ZodString;
    accountant: z.ZodString;
    taxRegime: z.ZodEnum<{
        OTHER: "OTHER";
        IT_PARK: "IT_PARK";
        TURNOVER: "TURNOVER";
        GENERAL: "GENERAL";
    }>;
    legalName: z.ZodDefault<z.ZodString>;
    directorPosition: z.ZodDefault<z.ZodString>;
    signerGenitive: z.ZodDefault<z.ZodString>;
    basis: z.ZodDefault<z.ZodString>;
    address: z.ZodDefault<z.ZodString>;
    phone: z.ZodDefault<z.ZodString>;
    email: z.ZodDefault<z.ZodString>;
    website: z.ZodDefault<z.ZodString>;
    bank: z.ZodDefault<z.ZodString>;
    mfo: z.ZodDefault<z.ZodString>;
    account: z.ZodDefault<z.ZodString>;
    oked: z.ZodDefault<z.ZodString>;
    vatCode: z.ZodDefault<z.ZodString>;
}, z.core.$strip>;
export type CompanySettings = z.output<typeof companySettingsSchema>;
export declare const DEFAULT_COMPANY_SETTINGS: CompanySettings;

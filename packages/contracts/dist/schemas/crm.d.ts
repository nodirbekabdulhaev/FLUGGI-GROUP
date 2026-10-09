import { z } from 'zod';
import type { ClientRequisites } from './documents';
import { type ClientHealth, type ClientType, type CloseStatus, type CompanySize, type Currency, type DealStatus, type LeadStatus, type MeetingStatus, type MeetingType, type Priority, type ScoreLevelCode } from '../enums';
import type { NamedRef, StageDto } from './references';
/**
 * Создание лида — минимум полей (ТЗ §78): имя или компания, телефон или Telegram,
 * источник, услуга. Остальное заполняется позже.
 */
export declare const createLeadSchema: z.ZodObject<{
    ownerId: z.ZodOptional<z.ZodUUID>;
    title: z.ZodPipe<z.ZodOptional<z.ZodString>, z.ZodTransform<string | undefined, string | undefined>>;
    sourceId: z.ZodUUID;
    serviceId: z.ZodUUID;
    budget: z.ZodPipe<z.ZodPipe<z.ZodOptional<z.ZodUnion<readonly [z.ZodString, z.ZodNumber]>>, z.ZodTransform<string | number | undefined, string | number | undefined>>, z.ZodOptional<z.ZodPipe<z.ZodPipe<z.ZodUnion<readonly [z.ZodString, z.ZodNumber]>, z.ZodTransform<string, string | number>>, z.ZodString>>>;
    currency: z.ZodDefault<z.ZodEnum<{
        UZS: "UZS";
        USD: "USD";
    }>>;
    desiredDate: z.ZodOptional<z.ZodISODate>;
    priority: z.ZodDefault<z.ZodEnum<{
        LOW: "LOW";
        MEDIUM: "MEDIUM";
        HIGH: "HIGH";
        URGENT: "URGENT";
    }>>;
    companySize: z.ZodOptional<z.ZodEnum<{
        MEDIUM: "MEDIUM";
        SOLO: "SOLO";
        SMALL: "SMALL";
        LARGE: "LARGE";
    }>>;
    interest: z.ZodOptional<z.ZodCoercedNumber<unknown>>;
    nextContactAt: z.ZodOptional<z.ZodISODateTime>;
    comment: z.ZodPipe<z.ZodOptional<z.ZodString>, z.ZodTransform<string | undefined, string | undefined>>;
    contactName: z.ZodPipe<z.ZodOptional<z.ZodString>, z.ZodTransform<string | undefined, string | undefined>>;
    companyName: z.ZodPipe<z.ZodOptional<z.ZodString>, z.ZodTransform<string | undefined, string | undefined>>;
    phone: z.ZodPipe<z.ZodOptional<z.ZodString>, z.ZodTransform<string | undefined, string | undefined>>;
    telegram: z.ZodPipe<z.ZodOptional<z.ZodString>, z.ZodTransform<string | undefined, string | undefined>>;
    whatsapp: z.ZodPipe<z.ZodOptional<z.ZodString>, z.ZodTransform<string | undefined, string | undefined>>;
    instagram: z.ZodPipe<z.ZodOptional<z.ZodString>, z.ZodTransform<string | undefined, string | undefined>>;
    email: z.ZodPipe<z.ZodPipe<z.ZodOptional<z.ZodString>, z.ZodTransform<string | undefined, string | undefined>>, z.ZodOptional<z.ZodEmail>>;
    website: z.ZodPipe<z.ZodOptional<z.ZodString>, z.ZodTransform<string | undefined, string | undefined>>;
    city: z.ZodPipe<z.ZodOptional<z.ZodString>, z.ZodTransform<string | undefined, string | undefined>>;
    country: z.ZodPipe<z.ZodOptional<z.ZodString>, z.ZodTransform<string | undefined, string | undefined>>;
}, z.core.$strip>;
export type CreateLeadInput = z.input<typeof createLeadSchema>;
export declare const updateLeadSchema: z.ZodPipe<z.ZodObject<{
    title: z.ZodOptional<z.ZodString>;
    contactName: z.ZodOptional<z.ZodNullable<z.ZodString>>;
    companyName: z.ZodOptional<z.ZodNullable<z.ZodString>>;
    phone: z.ZodOptional<z.ZodNullable<z.ZodString>>;
    telegram: z.ZodOptional<z.ZodNullable<z.ZodString>>;
    whatsapp: z.ZodOptional<z.ZodNullable<z.ZodString>>;
    instagram: z.ZodOptional<z.ZodNullable<z.ZodString>>;
    email: z.ZodOptional<z.ZodNullable<z.ZodString>>;
    website: z.ZodOptional<z.ZodNullable<z.ZodString>>;
    city: z.ZodOptional<z.ZodNullable<z.ZodString>>;
    country: z.ZodOptional<z.ZodNullable<z.ZodString>>;
    sourceId: z.ZodOptional<z.ZodUUID>;
    serviceId: z.ZodOptional<z.ZodNullable<z.ZodUUID>>;
    budget: z.ZodOptional<z.ZodNullable<z.ZodPipe<z.ZodPipe<z.ZodUnion<readonly [z.ZodString, z.ZodNumber]>, z.ZodTransform<string, string | number>>, z.ZodString>>>;
    currency: z.ZodOptional<z.ZodEnum<{
        UZS: "UZS";
        USD: "USD";
    }>>;
    desiredDate: z.ZodOptional<z.ZodNullable<z.ZodISODate>>;
    priority: z.ZodOptional<z.ZodEnum<{
        LOW: "LOW";
        MEDIUM: "MEDIUM";
        HIGH: "HIGH";
        URGENT: "URGENT";
    }>>;
    companySize: z.ZodOptional<z.ZodNullable<z.ZodEnum<{
        MEDIUM: "MEDIUM";
        SOLO: "SOLO";
        SMALL: "SMALL";
        LARGE: "LARGE";
    }>>>;
    interest: z.ZodOptional<z.ZodNullable<z.ZodCoercedNumber<unknown>>>;
    nextContactAt: z.ZodOptional<z.ZodNullable<z.ZodISODateTime>>;
    comment: z.ZodOptional<z.ZodNullable<z.ZodString>>;
}, z.core.$strip>, z.ZodTransform<{
    title?: string | undefined;
    contactName?: string | null | undefined;
    companyName?: string | null | undefined;
    phone?: string | null | undefined;
    telegram?: string | null | undefined;
    whatsapp?: string | null | undefined;
    instagram?: string | null | undefined;
    email?: string | null | undefined;
    website?: string | null | undefined;
    city?: string | null | undefined;
    country?: string | null | undefined;
    sourceId?: string | undefined;
    serviceId?: string | null | undefined;
    budget?: string | null | undefined;
    currency?: "UZS" | "USD" | undefined;
    desiredDate?: string | null | undefined;
    priority?: "LOW" | "MEDIUM" | "HIGH" | "URGENT" | undefined;
    companySize?: "MEDIUM" | "SOLO" | "SMALL" | "LARGE" | null | undefined;
    interest?: number | null | undefined;
    nextContactAt?: string | null | undefined;
    comment?: string | null | undefined;
}, {
    title?: string | undefined;
    contactName?: string | null | undefined;
    companyName?: string | null | undefined;
    phone?: string | null | undefined;
    telegram?: string | null | undefined;
    whatsapp?: string | null | undefined;
    instagram?: string | null | undefined;
    email?: string | null | undefined;
    website?: string | null | undefined;
    city?: string | null | undefined;
    country?: string | null | undefined;
    sourceId?: string | undefined;
    serviceId?: string | null | undefined;
    budget?: string | null | undefined;
    currency?: "UZS" | "USD" | undefined;
    desiredDate?: string | null | undefined;
    priority?: "LOW" | "MEDIUM" | "HIGH" | "URGENT" | undefined;
    companySize?: "MEDIUM" | "SOLO" | "SMALL" | "LARGE" | null | undefined;
    interest?: number | null | undefined;
    nextContactAt?: string | null | undefined;
    comment?: string | null | undefined;
}>>;
export type UpdateLeadInput = z.input<typeof updateLeadSchema>;
export declare const leadListQuerySchema: z.ZodObject<{
    page: z.ZodDefault<z.ZodCoercedNumber<unknown>>;
    pageSize: z.ZodDefault<z.ZodCoercedNumber<unknown>>;
    q: z.ZodOptional<z.ZodString>;
    stageCode: z.ZodOptional<z.ZodEnum<{
        NEW: "NEW";
        CONTACTED: "CONTACTED";
        QUALIFICATION: "QUALIFICATION";
        MEETING_SCHEDULED: "MEETING_SCHEDULED";
        MEETING_DONE: "MEETING_DONE";
    }>>;
    status: z.ZodOptional<z.ZodEnum<{
        OPEN: "OPEN";
        CONVERTED: "CONVERTED";
        LOST: "LOST";
        REJECTED: "REJECTED";
        PAUSED: "PAUSED";
        NO_RESPONSE: "NO_RESPONSE";
    }>>;
    ownerId: z.ZodOptional<z.ZodUUID>;
    teamId: z.ZodOptional<z.ZodUUID>;
    sourceId: z.ZodOptional<z.ZodUUID>;
    serviceId: z.ZodOptional<z.ZodUUID>;
    scoreLevel: z.ZodOptional<z.ZodEnum<{
        LOW: "LOW";
        MEDIUM: "MEDIUM";
        HIGH: "HIGH";
        HOT: "HOT";
    }>>;
    dateFrom: z.ZodOptional<z.ZodISODate>;
    dateTo: z.ZodOptional<z.ZodISODate>;
}, z.core.$strip>;
export type LeadListQuery = Partial<z.output<typeof leadListQuerySchema>>;
export declare const changeLeadStageSchema: z.ZodObject<{
    stageCode: z.ZodEnum<{
        NEW: "NEW";
        CONTACTED: "CONTACTED";
        QUALIFICATION: "QUALIFICATION";
        MEETING_SCHEDULED: "MEETING_SCHEDULED";
        MEETING_DONE: "MEETING_DONE";
    }>;
}, z.core.$strip>;
export declare const changeDealStageSchema: z.ZodObject<{
    stageCode: z.ZodEnum<{
        NEED_DEFINED: "NEED_DEFINED";
        PROPOSAL_SENT: "PROPOSAL_SENT";
        NEGOTIATION: "NEGOTIATION";
        CONTRACT: "CONTRACT";
        AWAITING_PAYMENT: "AWAITING_PAYMENT";
        PAID: "PAID";
    }>;
}, z.core.$strip>;
export declare const assignSchema: z.ZodObject<{
    ownerId: z.ZodUUID;
}, z.core.$strip>;
/** Закрытие лида/сделки (ТЗ §7, §39): для «Потеряно» и «Отказ» причина обязательна. */
export declare const closeSchema: z.ZodObject<{
    status: z.ZodEnum<{
        LOST: "LOST";
        REJECTED: "REJECTED";
        PAUSED: "PAUSED";
        NO_RESPONSE: "NO_RESPONSE";
    }>;
    lossReasonId: z.ZodOptional<z.ZodUUID>;
    comment: z.ZodOptional<z.ZodString>;
}, z.core.$strip>;
export type CloseInput = z.input<typeof closeSchema>;
/** Квалификация лида → клиент + сделка (BUSINESS_RULES §2). */
export declare const convertLeadSchema: z.ZodObject<{
    clientId: z.ZodOptional<z.ZodUUID>;
    clientName: z.ZodPipe<z.ZodOptional<z.ZodString>, z.ZodTransform<string | undefined, string | undefined>>;
    clientType: z.ZodDefault<z.ZodEnum<{
        COMPANY: "COMPANY";
        PERSON: "PERSON";
    }>>;
    title: z.ZodPipe<z.ZodOptional<z.ZodString>, z.ZodTransform<string | undefined, string | undefined>>;
    amount: z.ZodPipe<z.ZodPipe<z.ZodUnion<readonly [z.ZodString, z.ZodNumber]>, z.ZodTransform<string, string | number>>, z.ZodString>;
    currency: z.ZodDefault<z.ZodEnum<{
        UZS: "UZS";
        USD: "USD";
    }>>;
    expectedCloseDate: z.ZodOptional<z.ZodISODate>;
}, z.core.$strip>;
export type ConvertLeadInput = z.input<typeof convertLeadSchema>;
export interface StageRef {
    id: string;
    code: string;
    name: string;
    color: string;
}
export interface LeadDto {
    id: string;
    number: string;
    title: string;
    contactName: string | null;
    companyName: string | null;
    phone: string | null;
    telegram: string | null;
    whatsapp: string | null;
    instagram: string | null;
    email: string | null;
    website: string | null;
    city: string | null;
    country: string | null;
    source: NamedRef;
    owner: NamedRef;
    team: NamedRef | null;
    service: NamedRef | null;
    budget: string | null;
    currency: Currency;
    budgetUzs: string | null;
    desiredDate: string | null;
    priority: Priority;
    companySize: CompanySize | null;
    interest: number | null;
    stage: StageRef;
    status: LeadStatus;
    score: number;
    scoreLevel: ScoreLevelCode;
    nextContactAt: string | null;
    lastContactAt: string | null;
    comment: string | null;
    clientId: string | null;
    dealId: string | null;
    lossReason: NamedRef | null;
    lossComment: string | null;
    createdAt: string;
    updatedAt: string;
}
export declare const contactSchema: z.ZodObject<{
    fullName: z.ZodString;
    position: z.ZodPipe<z.ZodOptional<z.ZodString>, z.ZodTransform<string | undefined, string | undefined>>;
    phone: z.ZodPipe<z.ZodOptional<z.ZodString>, z.ZodTransform<string | undefined, string | undefined>>;
    telegram: z.ZodPipe<z.ZodOptional<z.ZodString>, z.ZodTransform<string | undefined, string | undefined>>;
    whatsapp: z.ZodPipe<z.ZodOptional<z.ZodString>, z.ZodTransform<string | undefined, string | undefined>>;
    instagram: z.ZodPipe<z.ZodOptional<z.ZodString>, z.ZodTransform<string | undefined, string | undefined>>;
    email: z.ZodPipe<z.ZodPipe<z.ZodOptional<z.ZodString>, z.ZodTransform<string | undefined, string | undefined>>, z.ZodOptional<z.ZodEmail>>;
    isPrimary: z.ZodDefault<z.ZodBoolean>;
}, z.core.$strip>;
export type ContactInput = z.input<typeof contactSchema>;
export declare const createClientSchema: z.ZodObject<{
    name: z.ZodString;
    type: z.ZodDefault<z.ZodEnum<{
        COMPANY: "COMPANY";
        PERSON: "PERSON";
    }>>;
    industry: z.ZodPipe<z.ZodOptional<z.ZodString>, z.ZodTransform<string | undefined, string | undefined>>;
    phone: z.ZodPipe<z.ZodOptional<z.ZodString>, z.ZodTransform<string | undefined, string | undefined>>;
    email: z.ZodPipe<z.ZodPipe<z.ZodOptional<z.ZodString>, z.ZodTransform<string | undefined, string | undefined>>, z.ZodOptional<z.ZodEmail>>;
    telegram: z.ZodPipe<z.ZodOptional<z.ZodString>, z.ZodTransform<string | undefined, string | undefined>>;
    website: z.ZodPipe<z.ZodOptional<z.ZodString>, z.ZodTransform<string | undefined, string | undefined>>;
    city: z.ZodPipe<z.ZodOptional<z.ZodString>, z.ZodTransform<string | undefined, string | undefined>>;
    country: z.ZodPipe<z.ZodOptional<z.ZodString>, z.ZodTransform<string | undefined, string | undefined>>;
    sourceId: z.ZodOptional<z.ZodUUID>;
    ownerId: z.ZodOptional<z.ZodUUID>;
    comment: z.ZodPipe<z.ZodOptional<z.ZodString>, z.ZodTransform<string | undefined, string | undefined>>;
    contact: z.ZodOptional<z.ZodObject<{
        fullName: z.ZodString;
        position: z.ZodPipe<z.ZodOptional<z.ZodString>, z.ZodTransform<string | undefined, string | undefined>>;
        phone: z.ZodPipe<z.ZodOptional<z.ZodString>, z.ZodTransform<string | undefined, string | undefined>>;
        telegram: z.ZodPipe<z.ZodOptional<z.ZodString>, z.ZodTransform<string | undefined, string | undefined>>;
        whatsapp: z.ZodPipe<z.ZodOptional<z.ZodString>, z.ZodTransform<string | undefined, string | undefined>>;
        instagram: z.ZodPipe<z.ZodOptional<z.ZodString>, z.ZodTransform<string | undefined, string | undefined>>;
        email: z.ZodPipe<z.ZodPipe<z.ZodOptional<z.ZodString>, z.ZodTransform<string | undefined, string | undefined>>, z.ZodOptional<z.ZodEmail>>;
        isPrimary: z.ZodDefault<z.ZodBoolean>;
    }, z.core.$strip>>;
}, z.core.$strip>;
export type CreateClientInput = z.input<typeof createClientSchema>;
export declare const updateClientSchema: z.ZodObject<{
    type: z.ZodOptional<z.ZodDefault<z.ZodEnum<{
        COMPANY: "COMPANY";
        PERSON: "PERSON";
    }>>>;
    email: z.ZodOptional<z.ZodPipe<z.ZodPipe<z.ZodOptional<z.ZodString>, z.ZodTransform<string | undefined, string | undefined>>, z.ZodOptional<z.ZodEmail>>>;
    phone: z.ZodOptional<z.ZodPipe<z.ZodOptional<z.ZodString>, z.ZodTransform<string | undefined, string | undefined>>>;
    name: z.ZodOptional<z.ZodString>;
    city: z.ZodOptional<z.ZodPipe<z.ZodOptional<z.ZodString>, z.ZodTransform<string | undefined, string | undefined>>>;
    sourceId: z.ZodOptional<z.ZodOptional<z.ZodUUID>>;
    comment: z.ZodOptional<z.ZodPipe<z.ZodOptional<z.ZodString>, z.ZodTransform<string | undefined, string | undefined>>>;
    telegram: z.ZodOptional<z.ZodPipe<z.ZodOptional<z.ZodString>, z.ZodTransform<string | undefined, string | undefined>>>;
    website: z.ZodOptional<z.ZodPipe<z.ZodOptional<z.ZodString>, z.ZodTransform<string | undefined, string | undefined>>>;
    country: z.ZodOptional<z.ZodPipe<z.ZodOptional<z.ZodString>, z.ZodTransform<string | undefined, string | undefined>>>;
    industry: z.ZodOptional<z.ZodPipe<z.ZodOptional<z.ZodString>, z.ZodTransform<string | undefined, string | undefined>>>;
}, z.core.$strip>;
export type UpdateClientInput = z.input<typeof updateClientSchema>;
export declare const clientListQuerySchema: z.ZodObject<{
    page: z.ZodDefault<z.ZodCoercedNumber<unknown>>;
    pageSize: z.ZodDefault<z.ZodCoercedNumber<unknown>>;
    q: z.ZodOptional<z.ZodString>;
    ownerId: z.ZodOptional<z.ZodUUID>;
    teamId: z.ZodOptional<z.ZodUUID>;
    type: z.ZodOptional<z.ZodEnum<{
        COMPANY: "COMPANY";
        PERSON: "PERSON";
    }>>;
}, z.core.$strip>;
export type ClientListQuery = Partial<z.output<typeof clientListQuerySchema>>;
export interface ContactDto {
    id: string;
    fullName: string;
    position: string | null;
    phone: string | null;
    telegram: string | null;
    whatsapp: string | null;
    instagram: string | null;
    email: string | null;
    isPrimary: boolean;
}
export interface ClientDto {
    id: string;
    number: string;
    name: string;
    type: ClientType;
    industry: string | null;
    phone: string | null;
    email: string | null;
    telegram: string | null;
    website: string | null;
    city: string | null;
    country: string | null;
    owner: NamedRef;
    team: NamedRef | null;
    source: NamedRef | null;
    health: ClientHealth;
    comment: string | null;
    dealsCount: number;
    openDealsCount: number;
    createdAt: string;
}
export interface ClientDetailDto extends ClientDto {
    contacts: ContactDto[];
    deals: DealDto[];
    /** Реквизиты для договора (null — ещё не заполнены) */
    requisites: ClientRequisites | null;
}
export declare const createDealSchema: z.ZodObject<{
    clientId: z.ZodUUID;
    contactId: z.ZodOptional<z.ZodUUID>;
    title: z.ZodString;
    serviceId: z.ZodOptional<z.ZodUUID>;
    amount: z.ZodPipe<z.ZodPipe<z.ZodUnion<readonly [z.ZodString, z.ZodNumber]>, z.ZodTransform<string, string | number>>, z.ZodString>;
    currency: z.ZodDefault<z.ZodEnum<{
        UZS: "UZS";
        USD: "USD";
    }>>;
    ownerId: z.ZodOptional<z.ZodUUID>;
    expectedCloseDate: z.ZodOptional<z.ZodISODate>;
    isRepeat: z.ZodOptional<z.ZodBoolean>;
}, z.core.$strip>;
export type CreateDealInput = z.input<typeof createDealSchema>;
export declare const updateDealSchema: z.ZodObject<{
    title: z.ZodOptional<z.ZodString>;
    contactId: z.ZodOptional<z.ZodNullable<z.ZodUUID>>;
    serviceId: z.ZodOptional<z.ZodNullable<z.ZodUUID>>;
    amount: z.ZodOptional<z.ZodPipe<z.ZodPipe<z.ZodUnion<readonly [z.ZodString, z.ZodNumber]>, z.ZodTransform<string, string | number>>, z.ZodString>>;
    currency: z.ZodOptional<z.ZodEnum<{
        UZS: "UZS";
        USD: "USD";
    }>>;
    expectedCloseDate: z.ZodOptional<z.ZodNullable<z.ZodISODate>>;
    probabilityOverride: z.ZodOptional<z.ZodNullable<z.ZodCoercedNumber<unknown>>>;
}, z.core.$strip>;
export type UpdateDealInput = z.input<typeof updateDealSchema>;
export declare const dealListQuerySchema: z.ZodObject<{
    page: z.ZodDefault<z.ZodCoercedNumber<unknown>>;
    pageSize: z.ZodDefault<z.ZodCoercedNumber<unknown>>;
    q: z.ZodOptional<z.ZodString>;
    stageCode: z.ZodOptional<z.ZodEnum<{
        NEED_DEFINED: "NEED_DEFINED";
        PROPOSAL_SENT: "PROPOSAL_SENT";
        NEGOTIATION: "NEGOTIATION";
        CONTRACT: "CONTRACT";
        AWAITING_PAYMENT: "AWAITING_PAYMENT";
        PAID: "PAID";
    }>>;
    status: z.ZodOptional<z.ZodEnum<{
        OPEN: "OPEN";
        LOST: "LOST";
        REJECTED: "REJECTED";
        PAUSED: "PAUSED";
        NO_RESPONSE: "NO_RESPONSE";
        WON: "WON";
    }>>;
    ownerId: z.ZodOptional<z.ZodUUID>;
    teamId: z.ZodOptional<z.ZodUUID>;
    clientId: z.ZodOptional<z.ZodUUID>;
    serviceId: z.ZodOptional<z.ZodUUID>;
    amountMin: z.ZodOptional<z.ZodCoercedNumber<unknown>>;
    amountMax: z.ZodOptional<z.ZodCoercedNumber<unknown>>;
    dateFrom: z.ZodOptional<z.ZodISODate>;
    dateTo: z.ZodOptional<z.ZodISODate>;
}, z.core.$strip>;
export type DealListQuery = Partial<z.output<typeof dealListQuerySchema>>;
export interface DealDto {
    id: string;
    number: string;
    title: string;
    client: NamedRef;
    contact: NamedRef | null;
    owner: NamedRef;
    team: NamedRef | null;
    service: NamedRef | null;
    amount: string;
    currency: Currency;
    exchangeRate: string;
    amountUzs: string;
    stage: StageRef;
    status: DealStatus;
    probability: number;
    probabilityOverride: number | null;
    expectedCloseDate: string | null;
    isRepeat: boolean;
    leadId: string | null;
    lossReason: NamedRef | null;
    lossComment: string | null;
    wonAt: string | null;
    closedAt: string | null;
    createdAt: string;
    updatedAt: string;
}
export declare const pipelineQuerySchema: z.ZodObject<{
    ownerId: z.ZodOptional<z.ZodUUID>;
    teamId: z.ZodOptional<z.ZodUUID>;
    serviceId: z.ZodOptional<z.ZodUUID>;
    sourceId: z.ZodOptional<z.ZodUUID>;
}, z.core.$strip>;
export type PipelineQuery = z.output<typeof pipelineQuerySchema>;
export interface PipelineCard {
    id: string;
    kind: 'lead' | 'deal';
    number: string;
    title: string;
    subtitle: string | null;
    amount: string | null;
    currency: Currency;
    amountUzs: string | null;
    owner: NamedRef;
    scoreLevel: ScoreLevelCode | null;
    nextContactAt: string | null;
    updatedAt: string;
}
export interface PipelineColumn {
    stage: StageDto;
    items: PipelineCard[];
    count: number;
    totalUzs: string;
}
export interface PipelineDto {
    columns: PipelineColumn[];
    pipelineUzs: string;
    weightedUzs: string;
}
export declare const createMeetingSchema: z.ZodObject<{
    leadId: z.ZodOptional<z.ZodUUID>;
    dealId: z.ZodOptional<z.ZodUUID>;
    startsAt: z.ZodISODateTime;
    durationMin: z.ZodDefault<z.ZodCoercedNumber<unknown>>;
    type: z.ZodEnum<{
        ONLINE: "ONLINE";
        OFFLINE: "OFFLINE";
        PHONE: "PHONE";
        TELEGRAM: "TELEGRAM";
        GOOGLE_MEET: "GOOGLE_MEET";
        ZOOM: "ZOOM";
    }>;
    link: z.ZodPipe<z.ZodOptional<z.ZodString>, z.ZodTransform<string | undefined, string | undefined>>;
    comment: z.ZodPipe<z.ZodOptional<z.ZodString>, z.ZodTransform<string | undefined, string | undefined>>;
}, z.core.$strip>;
export type CreateMeetingInput = z.input<typeof createMeetingSchema>;
export declare const updateMeetingSchema: z.ZodObject<{
    startsAt: z.ZodOptional<z.ZodISODateTime>;
    durationMin: z.ZodOptional<z.ZodCoercedNumber<unknown>>;
    type: z.ZodOptional<z.ZodEnum<{
        ONLINE: "ONLINE";
        OFFLINE: "OFFLINE";
        PHONE: "PHONE";
        TELEGRAM: "TELEGRAM";
        GOOGLE_MEET: "GOOGLE_MEET";
        ZOOM: "ZOOM";
    }>>;
    link: z.ZodOptional<z.ZodNullable<z.ZodString>>;
    comment: z.ZodOptional<z.ZodNullable<z.ZodString>>;
    status: z.ZodOptional<z.ZodEnum<{
        SCHEDULED: "SCHEDULED";
        CONFIRMED: "CONFIRMED";
        RESCHEDULED: "RESCHEDULED";
        CANCELLED: "CANCELLED";
        NO_SHOW: "NO_SHOW";
    }>>;
}, z.core.$strip>;
export type UpdateMeetingInput = z.input<typeof updateMeetingSchema>;
export declare const completeMeetingSchema: z.ZodObject<{
    result: z.ZodString;
}, z.core.$strip>;
export type CompleteMeetingInput = z.input<typeof completeMeetingSchema>;
export declare const meetingListQuerySchema: z.ZodObject<{
    page: z.ZodDefault<z.ZodCoercedNumber<unknown>>;
    pageSize: z.ZodDefault<z.ZodCoercedNumber<unknown>>;
    status: z.ZodOptional<z.ZodEnum<{
        SCHEDULED: "SCHEDULED";
        CONFIRMED: "CONFIRMED";
        DONE: "DONE";
        RESCHEDULED: "RESCHEDULED";
        CANCELLED: "CANCELLED";
        NO_SHOW: "NO_SHOW";
    }>>;
    managerId: z.ZodOptional<z.ZodUUID>;
    leadId: z.ZodOptional<z.ZodUUID>;
    dealId: z.ZodOptional<z.ZodUUID>;
    dateFrom: z.ZodOptional<z.ZodISODate>;
    dateTo: z.ZodOptional<z.ZodISODate>;
}, z.core.$strip>;
export type MeetingListQuery = Partial<z.output<typeof meetingListQuerySchema>>;
export interface MeetingDto {
    id: string;
    lead: (NamedRef & {
        number: string;
    }) | null;
    deal: (NamedRef & {
        number: string;
    }) | null;
    client: NamedRef | null;
    manager: NamedRef;
    rop: NamedRef | null;
    startsAt: string;
    durationMin: number;
    type: MeetingType;
    link: string | null;
    status: MeetingStatus;
    comment: string | null;
    result: string | null;
    createdAt: string;
}
export declare const timelineQuerySchema: z.ZodObject<{
    leadId: z.ZodOptional<z.ZodUUID>;
    dealId: z.ZodOptional<z.ZodUUID>;
    clientId: z.ZodOptional<z.ZodUUID>;
}, z.core.$strip>;
export type TimelineQuery = z.output<typeof timelineQuerySchema>;
export interface ActivityDto {
    id: string;
    type: string;
    actor: NamedRef | null;
    payload: Record<string, unknown> | null;
    createdAt: string;
}
export interface StageHistoryDto {
    id: string;
    from: StageRef | null;
    to: StageRef;
    changedBy: NamedRef;
    durationSec: number | null;
    createdAt: string;
}
export declare const createCommentSchema: z.ZodObject<{
    leadId: z.ZodOptional<z.ZodUUID>;
    dealId: z.ZodOptional<z.ZodUUID>;
    clientId: z.ZodOptional<z.ZodUUID>;
    body: z.ZodString;
}, z.core.$strip>;
export type CreateCommentInput = z.input<typeof createCommentSchema>;
export interface CommentDto {
    id: string;
    author: NamedRef;
    body: string;
    createdAt: string;
    canDelete: boolean;
}
export interface NotificationDto {
    id: string;
    type: string;
    title: string;
    body: string | null;
    link: string | null;
    readAt: string | null;
    createdAt: string;
}
export type { CloseStatus };

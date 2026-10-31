import { z } from 'zod';
import type { NamedRef } from './references';
export declare const FORM_FIELD_TYPES: readonly ["text", "phone", "email", "textarea", "select"];
export type FormFieldType = (typeof FORM_FIELD_TYPES)[number];
/**
 * Поле формы. Ключи name/phone/email/company/message/service попадают в одноимённые поля лида,
 * остальные — в комментарий лида.
 */
export declare const formFieldSchema: z.ZodObject<{
    key: z.ZodString;
    label: z.ZodString;
    type: z.ZodEnum<{
        email: "email";
        phone: "phone";
        text: "text";
        textarea: "textarea";
        select: "select";
    }>;
    required: z.ZodDefault<z.ZodBoolean>;
    options: z.ZodOptional<z.ZodArray<z.ZodString>>;
}, z.core.$strip>;
export type FormField = z.output<typeof formFieldSchema>;
export declare const leadFormSchema: z.ZodObject<{
    name: z.ZodString;
    title: z.ZodString;
    description: z.ZodOptional<z.ZodNullable<z.ZodString>>;
    buttonText: z.ZodDefault<z.ZodString>;
    successMessage: z.ZodDefault<z.ZodString>;
    fields: z.ZodArray<z.ZodObject<{
        key: z.ZodString;
        label: z.ZodString;
        type: z.ZodEnum<{
            email: "email";
            phone: "phone";
            text: "text";
            textarea: "textarea";
            select: "select";
        }>;
        required: z.ZodDefault<z.ZodBoolean>;
        options: z.ZodOptional<z.ZodArray<z.ZodString>>;
    }, z.core.$strip>>;
    serviceId: z.ZodOptional<z.ZodNullable<z.ZodUUID>>;
    sourceId: z.ZodOptional<z.ZodNullable<z.ZodUUID>>;
    ownerId: z.ZodOptional<z.ZodNullable<z.ZodUUID>>;
    teamId: z.ZodOptional<z.ZodNullable<z.ZodUUID>>;
    isActive: z.ZodDefault<z.ZodBoolean>;
}, z.core.$strip>;
export type LeadFormInput = z.input<typeof leadFormSchema>;
/** Поля новой формы по умолчанию */
export declare const DEFAULT_FORM_FIELDS: FormField[];
export interface LeadFormDto {
    id: string;
    key: string;
    name: string;
    title: string;
    description: string | null;
    buttonText: string;
    successMessage: string;
    fields: FormField[];
    serviceId: string | null;
    sourceId: string | null;
    owner: NamedRef | null;
    teamId: string | null;
    isActive: boolean;
    submissions: number;
    leads: number;
    lastSubmissionAt: string | null;
    createdAt: string;
}
/** То, что видит посетитель сайта (без служебных полей). */
export interface PublicFormDto {
    key: string;
    title: string;
    description: string | null;
    buttonText: string;
    successMessage: string;
    fields: FormField[];
}
export declare const formSubmitSchema: z.ZodObject<{
    data: z.ZodRecord<z.ZodString, z.ZodString>;
    utm: z.ZodOptional<z.ZodRecord<z.ZodString, z.ZodString>>;
    page: z.ZodOptional<z.ZodString>;
    website: z.ZodOptional<z.ZodString>;
    renderedAt: z.ZodOptional<z.ZodCoercedNumber<unknown>>;
}, z.core.$strip>;
export type FormSubmitInput = z.input<typeof formSubmitSchema>;
export declare const INTAKE_RESULTS: readonly ["LEAD_CREATED", "DUPLICATE", "SPAM"];
export type IntakeResult = (typeof INTAKE_RESULTS)[number];
export interface FormSubmissionDto {
    id: string;
    data: Record<string, string>;
    utm: Record<string, string> | null;
    page: string | null;
    result: IntakeResult;
    lead: {
        id: string;
        number: string;
        name: string;
    } | null;
    createdAt: string;
}
export declare const SOCIAL_CHANNELS: readonly ["INSTAGRAM_DM", "INSTAGRAM_COMMENT", "FACEBOOK_COMMENT"];
export type SocialChannel = (typeof SOCIAL_CHANNELS)[number];
export interface SocialThreadDto {
    id: string;
    channel: SocialChannel;
    peerId: string;
    peerName: string | null;
    peerUsername: string | null;
    lead: {
        id: string;
        number: string;
        name: string;
    } | null;
    owner: NamedRef | null;
    unread: number;
    lastMessage: {
        text: string;
        direction: 'IN' | 'OUT';
        createdAt: string;
    } | null;
    lastMessageAt: string;
}
export interface SocialMessageDto {
    id: string;
    direction: 'IN' | 'OUT';
    text: string;
    /** Комментарий: id комментария в Meta (для ответа) и публикация */
    externalId: string | null;
    mediaId: string | null;
    author: NamedRef | null;
    createdAt: string;
}
export declare const inboxListQuerySchema: z.ZodObject<{
    channel: z.ZodOptional<z.ZodEnum<{
        INSTAGRAM_DM: "INSTAGRAM_DM";
        INSTAGRAM_COMMENT: "INSTAGRAM_COMMENT";
        FACEBOOK_COMMENT: "FACEBOOK_COMMENT";
    }>>;
    unread: z.ZodOptional<z.ZodEnum<{
        true: "true";
        false: "false";
    }>>;
    page: z.ZodDefault<z.ZodCoercedNumber<unknown>>;
    pageSize: z.ZodDefault<z.ZodCoercedNumber<unknown>>;
}, z.core.$strip>;
export type InboxListQuery = z.input<typeof inboxListQuerySchema>;
export declare const socialReplySchema: z.ZodObject<{
    text: z.ZodString;
    commentId: z.ZodOptional<z.ZodString>;
    mode: z.ZodDefault<z.ZodEnum<{
        public: "public";
        private: "private";
    }>>;
}, z.core.$strip>;
export type SocialReplyInput = z.input<typeof socialReplySchema>;
/** Настройки интеграций (секреты Meta — только в .env). */
export declare const integrationSettingsSchema: z.ZodObject<{
    ownerId: z.ZodDefault<z.ZodNullable<z.ZodUUID>>;
    teamId: z.ZodDefault<z.ZodNullable<z.ZodUUID>>;
    autoLeadFromDirect: z.ZodDefault<z.ZodBoolean>;
    autoLeadFromComments: z.ZodDefault<z.ZodEnum<{
        all: "all";
        off: "off";
        keywords: "keywords";
    }>>;
    commentKeywords: z.ZodDefault<z.ZodArray<z.ZodString>>;
    serviceId: z.ZodDefault<z.ZodNullable<z.ZodUUID>>;
}, z.core.$strip>;
export type IntegrationSettings = z.output<typeof integrationSettingsSchema>;
export interface MetaStatusDto {
    /** Заданы META_APP_SECRET, META_VERIFY_TOKEN и META_PAGE_ACCESS_TOKEN */
    configured: boolean;
    missing: string[];
    webhookUrl: string;
    lastEventAt: string | null;
    lastError: string | null;
    threads: number;
    adsLeads: number;
}

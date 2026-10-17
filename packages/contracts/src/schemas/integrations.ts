import { z } from 'zod';
import type { NamedRef } from './references';

// ─────────────────────────── Формы для сайта ───────────────────────────

export const FORM_FIELD_TYPES = ['text', 'phone', 'email', 'textarea', 'select'] as const;
export type FormFieldType = (typeof FORM_FIELD_TYPES)[number];

/**
 * Поле формы. Ключи name/phone/email/company/message/service попадают в одноимённые поля лида,
 * остальные — в комментарий лида.
 */
export const formFieldSchema = z.object({
  key: z
    .string()
    .trim()
    .min(1)
    .max(40)
    .regex(/^[a-z][a-z0-9_]*$/, 'Ключ — латиница, цифры и _'),
  label: z.string().trim().min(1, 'Подпись поля').max(120),
  type: z.enum(FORM_FIELD_TYPES),
  required: z.boolean().default(false),
  options: z.array(z.string().trim().min(1).max(120)).max(30).optional(),
});
export type FormField = z.output<typeof formFieldSchema>;

export const leadFormSchema = z.object({
  name: z.string().trim().min(1, 'Название для списка').max(120),
  title: z.string().trim().min(1, 'Заголовок формы').max(200),
  description: z.string().trim().max(1000).nullish(),
  buttonText: z.string().trim().min(1).max(60).default('Отправить'),
  successMessage: z
    .string()
    .trim()
    .min(1)
    .max(500)
    .default('Спасибо! Мы свяжемся с вами в ближайшее время.'),
  fields: z
    .array(formFieldSchema)
    .min(1, 'Добавьте поля')
    .max(20)
    .refine((f) => new Set(f.map((x) => x.key)).size === f.length, 'Ключи полей повторяются')
    .refine(
      (f) => f.some((x) => x.type === 'phone' || x.type === 'email'),
      'Нужно поле «Телефон» или «Email», чтобы связаться с клиентом',
    ),
  serviceId: z.uuid().nullish(),
  sourceId: z.uuid().nullish(),
  ownerId: z.uuid().nullish(),
  teamId: z.uuid().nullish(),
  isActive: z.boolean().default(true),
});
export type LeadFormInput = z.input<typeof leadFormSchema>;

/** Поля новой формы по умолчанию */
export const DEFAULT_FORM_FIELDS: FormField[] = [
  { key: 'name', label: 'Ваше имя', type: 'text', required: true },
  { key: 'phone', label: 'Телефон', type: 'phone', required: true },
  { key: 'company', label: 'Компания', type: 'text', required: false },
  { key: 'message', label: 'Что вас интересует?', type: 'textarea', required: false },
];

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

export const formSubmitSchema = z.object({
  data: z.record(z.string().max(40), z.string().max(4000)),
  /** utm_source, utm_medium, utm_campaign, utm_content, utm_term */
  utm: z.record(z.string().max(40), z.string().max(300)).optional(),
  page: z.string().max(1000).optional(),
  /** Ловушка для ботов: поле скрыто, человек его не заполняет */
  website: z.string().max(200).optional(),
  /** Время показа формы (мс); слишком быстрая отправка — бот */
  renderedAt: z.coerce.number().optional(),
});
export type FormSubmitInput = z.input<typeof formSubmitSchema>;

export const INTAKE_RESULTS = ['LEAD_CREATED', 'DUPLICATE', 'SPAM'] as const;
export type IntakeResult = (typeof INTAKE_RESULTS)[number];

export interface FormSubmissionDto {
  id: string;
  data: Record<string, string>;
  utm: Record<string, string> | null;
  page: string | null;
  result: IntakeResult;
  lead: { id: string; number: string; name: string } | null;
  createdAt: string;
}

// ─────────────────────────── Instagram / Facebook ───────────────────────────

export const SOCIAL_CHANNELS = ['INSTAGRAM_DM', 'INSTAGRAM_COMMENT', 'FACEBOOK_COMMENT'] as const;
export type SocialChannel = (typeof SOCIAL_CHANNELS)[number];

export interface SocialThreadDto {
  id: string;
  channel: SocialChannel;
  peerId: string;
  peerName: string | null;
  peerUsername: string | null;
  lead: { id: string; number: string; name: string } | null;
  owner: NamedRef | null;
  unread: number;
  lastMessage: { text: string; direction: 'IN' | 'OUT'; createdAt: string } | null;
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

export const inboxListQuerySchema = z.object({
  channel: z.enum(SOCIAL_CHANNELS).optional(),
  unread: z.enum(['true', 'false']).optional(),
  page: z.coerce.number().int().min(1).default(1),
  pageSize: z.coerce.number().int().min(1).max(100).default(30),
});
export type InboxListQuery = z.input<typeof inboxListQuerySchema>;

export const socialReplySchema = z.object({
  text: z.string().trim().min(1, 'Введите ответ').max(1000),
  /** Комментарии: ответить на этот комментарий публично или в Директ (private reply) */
  commentId: z.string().max(100).optional(),
  mode: z.enum(['public', 'private']).default('public'),
});
export type SocialReplyInput = z.input<typeof socialReplySchema>;

/** Настройки интеграций (секреты Meta — только в .env). */
export const integrationSettingsSchema = z.object({
  /** Ответственный за лиды из соцсетей и таргета; иначе — по очереди в отделе */
  ownerId: z.uuid().nullable().default(null),
  teamId: z.uuid().nullable().default(null),
  /** Лид из Директа: сразу при первом сообщении */
  autoLeadFromDirect: z.boolean().default(true),
  /** Лид из комментария: off — вручную, keywords — если есть слова из списка, all — всегда */
  autoLeadFromComments: z.enum(['off', 'keywords', 'all']).default('keywords'),
  commentKeywords: z
    .array(z.string().trim().min(2).max(40))
    .max(50)
    .default(['цена', 'стоимость', 'сколько', 'прайс', 'нарх', 'qancha', 'price', 'директ']),
  serviceId: z.uuid().nullable().default(null),
});
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

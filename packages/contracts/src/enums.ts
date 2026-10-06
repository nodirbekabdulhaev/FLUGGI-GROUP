export const ROLE_CODES = ['CEO', 'ROP', 'MANAGER', 'EXECUTOR', 'HR_ADMIN'] as const;
export type RoleCode = (typeof ROLE_CODES)[number];

/** Область видимости права: свои записи, записи своего отдела, все записи. */
export const SCOPES = ['OWN', 'TEAM', 'ALL'] as const;
export type Scope = (typeof SCOPES)[number];

export const USER_STATUSES = ['ACTIVE', 'BLOCKED'] as const;
export type UserStatus = (typeof USER_STATUSES)[number];

export const EXECUTOR_SPECIALTIES = [
  'SMM',
  'DESIGNER',
  'VIDEOGRAPHER',
  'EDITOR',
  'TARGETOLOGIST',
  'DEVELOPER',
  'PHOTOGRAPHER',
  'COPYWRITER',
] as const;
export type ExecutorSpecialty = (typeof EXECUTOR_SPECIALTIES)[number];

export const LOCALES = ['ru', 'uz', 'en'] as const;
export type Locale = (typeof LOCALES)[number];
export const DEFAULT_LOCALE: Locale = 'ru';

export const CURRENCIES = ['UZS', 'USD'] as const;
export type Currency = (typeof CURRENCIES)[number];
export const BASE_CURRENCY: Currency = 'UZS';

/** Компания работает в Ташкенте (UTC+5, без перехода на летнее время). */
export const COMPANY_TIMEZONE = 'Asia/Tashkent';

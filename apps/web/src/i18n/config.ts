import { DEFAULT_LOCALE, LOCALES, type Locale } from '@fluggi/contracts';

export const LOCALE_COOKIE = 'fluggi_locale';

export function resolveLocale(value: string | undefined): Locale {
  return (LOCALES as readonly string[]).includes(value ?? '') ? (value as Locale) : DEFAULT_LOCALE;
}

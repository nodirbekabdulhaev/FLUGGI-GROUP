import { cookies } from 'next/headers';
import { getRequestConfig } from 'next-intl/server';
import ru from '../../messages/ru.json';
import { LOCALE_COOKIE, resolveLocale } from './config';

type Messages = { [key: string]: string | Messages };

/** Недостающие переводы берутся из русского (основной язык). */
function merge(base: Messages, override: Messages): Messages {
  const out: Messages = { ...base };
  for (const [k, v] of Object.entries(override)) {
    const b = base[k];
    out[k] = typeof v === 'object' && typeof b === 'object' ? merge(b, v) : v;
  }
  return out;
}

export default getRequestConfig(async () => {
  const locale = resolveLocale((await cookies()).get(LOCALE_COOKIE)?.value);
  const messages =
    locale === 'ru'
      ? ru
      : merge(ru as Messages, (await import(`../../messages/${locale}.json`)).default as Messages);
  return { locale, messages, timeZone: 'Asia/Tashkent' };
});

import { SESSION_COOKIE, type MeResponse } from '@fluggi/contracts';
import { cookies } from 'next/headers';
import { cache } from 'react';

const API_URL = process.env.API_INTERNAL_URL ?? 'http://localhost:4000';

/**
 * Текущий пользователь для серверных компонентов. Проверка выполняется API —
 * Next.js не принимает решений о доступе сам.
 */
export const getMe = cache(async (): Promise<MeResponse | null> => {
  const session = (await cookies()).get(SESSION_COOKIE);
  if (!session) return null;
  let res: Response;
  try {
    res = await fetch(`${API_URL}/api/v1/auth/me`, {
      headers: { cookie: `${SESSION_COOKIE}=${session.value}` },
      cache: 'no-store',
    });
  } catch {
    // Сообщение показывается на странице ошибки (app/error.tsx).
    throw new Error(`API_UNAVAILABLE: сервер API не отвечает по адресу ${API_URL}`);
  }
  if (res.status === 401) return null;
  if (!res.ok) throw new Error(`API /auth/me responded ${res.status}`);
  return (await res.json()) as MeResponse;
});

import { SESSION_COOKIE, type MeResponse } from '@fluggi/contracts';
import { request } from 'node:http';
import { cookies } from 'next/headers';
import { cache } from 'react';

const API_URL = process.env.API_INTERNAL_URL ?? 'http://localhost:4000';
/** Unix-сокет API в том же процессе (виртуальный хостинг: deploy/beget/server.js). */
const API_SOCKET = process.env.API_SOCKET;

type ApiReply = { status: number; json: () => Promise<unknown> };

function viaSocket(path: string, cookie: string): Promise<ApiReply> {
  return new Promise((resolve, reject) => {
    const req = request({ socketPath: API_SOCKET, path, headers: { cookie } }, (res) => {
      let body = '';
      res.setEncoding('utf8');
      res.on('data', (chunk: string) => (body += chunk));
      res.on('end', () =>
        resolve({ status: res.statusCode ?? 500, json: async () => JSON.parse(body) as unknown }),
      );
    });
    req.on('error', reject);
    req.end();
  });
}

/**
 * Текущий пользователь для серверных компонентов. Проверка выполняется API —
 * Next.js не принимает решений о доступе сам.
 */
export const getMe = cache(async (): Promise<MeResponse | null> => {
  const session = (await cookies()).get(SESSION_COOKIE);
  if (!session) return null;
  const cookie = `${SESSION_COOKIE}=${session.value}`;
  let res: ApiReply;
  try {
    res = API_SOCKET
      ? await viaSocket('/api/v1/auth/me', cookie)
      : await fetch(`${API_URL}/api/v1/auth/me`, { headers: { cookie }, cache: 'no-store' });
  } catch {
    // Сообщение показывается на странице ошибки (app/error.tsx).
    throw new Error(`API_UNAVAILABLE: сервер API не отвечает по адресу ${API_SOCKET ?? API_URL}`);
  }
  if (res.status === 401) return null;
  if (res.status < 200 || res.status >= 300)
    throw new Error(`API /auth/me responded ${res.status}`);
  return (await res.json()) as MeResponse;
});

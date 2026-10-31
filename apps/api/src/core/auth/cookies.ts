import { randomBytes } from 'node:crypto';
import { CSRF_COOKIE, SESSION_COOKIE } from '@fluggi/contracts';
import type { CookieOptions, Response } from 'express';
import { loadEnv } from '../../config/env';

function base(): CookieOptions {
  return { path: '/', sameSite: 'lax', secure: loadEnv().NODE_ENV === 'production' };
}

export function setSessionCookie(res: Response, token: string, expiresAt: Date) {
  res.cookie(SESSION_COOKIE, token, { ...base(), httpOnly: true, expires: expiresAt });
}

/** CSRF-токен (double submit): читается фронтендом и возвращается в заголовке. */
export function issueCsrfCookie(res: Response): string {
  const token = randomBytes(24).toString('base64url');
  res.cookie(CSRF_COOKIE, token, { ...base(), httpOnly: false });
  return token;
}

export function clearAuthCookies(res: Response) {
  res.clearCookie(SESSION_COOKIE, base());
  res.clearCookie(CSRF_COOKIE, base());
}

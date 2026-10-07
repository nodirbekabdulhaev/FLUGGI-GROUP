import { SESSION_COOKIE } from '@fluggi/contracts/dist/constants';
import { NextResponse, type NextRequest } from 'next/server';

/**
 * Быстрый редирект на /login, если cookie сессии нет вовсе.
 * Это не проверка доступа — её делает API на каждом запросе.
 */
export function middleware(req: NextRequest) {
  if (!req.cookies.has(SESSION_COOKIE)) {
    const url = req.nextUrl.clone();
    url.pathname = '/login';
    url.search =
      req.nextUrl.pathname === '/' ? '' : `?next=${encodeURIComponent(req.nextUrl.pathname)}`;
    return NextResponse.redirect(url);
  }
  return NextResponse.next();
}

export const config = {
  // /f/* — публичная форма заявки для сайта (без входа)
  matcher: ['/((?!api|login|f/|_next|favicon.ico|.*\\..*).*)'],
};

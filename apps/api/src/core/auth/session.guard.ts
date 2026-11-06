import { CanActivate, ExecutionContext, Injectable } from '@nestjs/common';
import { Reflector } from '@nestjs/core';
import { CSRF_COOKIE, SESSION_COOKIE } from '@fluggi/contracts';
import type { Response } from 'express';
import { AppException } from '../http/app.exception';
import type { AppRequest } from './auth-context';
import { issueCsrfCookie, setSessionCookie } from './cookies';
import { IS_PUBLIC } from './decorators';
import { SessionService } from './session.service';

/** Глобальный guard: все endpoint'ы требуют сессию, кроме помеченных @Public(). */
@Injectable()
export class SessionGuard implements CanActivate {
  constructor(
    private readonly reflector: Reflector,
    private readonly sessions: SessionService,
  ) {}

  async canActivate(context: ExecutionContext): Promise<boolean> {
    const req = context.switchToHttp().getRequest<AppRequest>();
    const isPublic = this.reflector.getAllAndOverride<boolean>(IS_PUBLIC, [
      context.getHandler(),
      context.getClass(),
    ]);

    const res = context.switchToHttp().getResponse<Response>();
    const token: unknown = req.cookies?.[SESSION_COOKIE];
    if (typeof token === 'string' && token.length > 0 && token.length < 200) {
      const resolved = await this.sessions.resolve(token);
      if (resolved) {
        req.auth = resolved.auth;
        // Скользящая сессия: продлеваем и cookie, иначе браузер удалит её по старому сроку.
        if (resolved.renewedUntil) setSessionCookie(res, token, resolved.renewedUntil);
      }
    }

    if (isPublic) return true;
    if (!req.auth) throw new AppException('UNAUTHENTICATED', 'Сессия истекла. Войдите снова');

    // Гарантируем наличие CSRF-cookie у вошедшего пользователя.
    if (!req.cookies?.[CSRF_COOKIE]) {
      req.cookies[CSRF_COOKIE] = issueCsrfCookie(res);
    }
    return true;
  }
}

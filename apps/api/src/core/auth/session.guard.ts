import { CanActivate, ExecutionContext, Injectable } from '@nestjs/common';
import { Reflector } from '@nestjs/core';
import { CSRF_COOKIE, SESSION_COOKIE } from '@fluggi/contracts';
import type { Response } from 'express';
import { AppException } from '../http/app.exception';
import type { AppRequest } from './auth-context';
import { issueCsrfCookie } from './cookies';
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

    const token: unknown = req.cookies?.[SESSION_COOKIE];
    if (typeof token === 'string' && token.length > 0 && token.length < 200) {
      const auth = await this.sessions.resolve(token);
      if (auth) req.auth = auth;
    }

    if (isPublic) return true;
    if (!req.auth) throw new AppException('UNAUTHENTICATED', 'Сессия истекла. Войдите снова');

    // Гарантируем наличие CSRF-cookie у вошедшего пользователя.
    if (!req.cookies?.[CSRF_COOKIE]) {
      const token = issueCsrfCookie(context.switchToHttp().getResponse<Response>());
      req.cookies[CSRF_COOKIE] = token;
    }
    return true;
  }
}

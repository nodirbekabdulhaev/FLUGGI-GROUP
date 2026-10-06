import { timingSafeEqual } from 'node:crypto';
import { CanActivate, ExecutionContext, Injectable } from '@nestjs/common';
import { CSRF_COOKIE, CSRF_HEADER } from '@fluggi/contracts';
import { loadEnv } from '../../config/env';
import { AppException } from '../http/app.exception';
import type { AppRequest } from './auth-context';

const SAFE_METHODS = new Set(['GET', 'HEAD', 'OPTIONS']);

function safeEqual(a: string, b: string) {
  const ab = Buffer.from(a);
  const bb = Buffer.from(b);
  return ab.length === bb.length && timingSafeEqual(ab, bb);
}

/**
 * CSRF-защита изменяющих запросов:
 *  1. Origin (если браузер его прислал) должен совпадать с APP_URL;
 *  2. для вошедшего пользователя — double submit: заголовок X-CSRF-Token = cookie.
 * Плюс SameSite=Lax у сессионной cookie.
 */
@Injectable()
export class CsrfGuard implements CanActivate {
  canActivate(context: ExecutionContext): boolean {
    const req = context.switchToHttp().getRequest<AppRequest>();
    if (SAFE_METHODS.has(req.method)) return true;

    const origin = req.get('origin');
    if (origin && origin !== new URL(loadEnv().APP_URL).origin) {
      throw new AppException('CSRF_INVALID', 'Запрос отклонён: недопустимый источник');
    }

    if (req.auth) {
      const cookie: unknown = req.cookies?.[CSRF_COOKIE];
      const header = req.get(CSRF_HEADER);
      if (typeof cookie !== 'string' || !header || !safeEqual(cookie, header)) {
        throw new AppException('CSRF_INVALID', 'Сессия устарела. Обновите страницу');
      }
    }
    return true;
  }
}

import { createParamDecorator, ExecutionContext, SetMetadata } from '@nestjs/common';
import type { PermissionCode, Scope } from '@fluggi/contracts';
import { requestMeta, type AppRequest, type AuthContext, type RequestMeta } from './auth-context';

export const IS_PUBLIC = 'fluggi:isPublic';
export const REQUIRED_PERMISSION = 'fluggi:requiredPermission';
export const AUTHENTICATED_ONLY = 'fluggi:authenticatedOnly';

/** Endpoint доступен без входа (логин, health, webhook). */
export const Public = () => SetMetadata(IS_PUBLIC, true);

export const CROSS_ORIGIN = 'fluggi:crossOrigin';
/**
 * Публичный приём данных с других сайтов (форма на WordPress, webhook Meta): без проверки Origin.
 * Сессия здесь не используется — cookie на межсайтовый POST не уходят (SameSite=Lax).
 */
export const CrossOrigin = () => SetMetadata(CROSS_ORIGIN, true);

export interface RequiredPermission {
  code: PermissionCode;
  minScope: Scope;
}

/** Endpoint требует право (с минимальной областью). */
export const RequirePermission = (code: PermissionCode, minScope: Scope = 'OWN') =>
  SetMetadata(REQUIRED_PERMISSION, { code, minScope } satisfies RequiredPermission);

/**
 * Endpoint доступен любому вошедшему пользователю (профиль, справочники).
 * Без этого декоратора или RequirePermission доступ запрещён — default deny.
 */
export const AuthenticatedOnly = () => SetMetadata(AUTHENTICATED_ONLY, true);

export const CurrentUser = createParamDecorator(
  (_: unknown, ctx: ExecutionContext): AuthContext => {
    const req = ctx.switchToHttp().getRequest<AppRequest>();
    return req.auth!;
  },
);

export const ReqMeta = createParamDecorator((_: unknown, ctx: ExecutionContext): RequestMeta =>
  requestMeta(ctx.switchToHttp().getRequest<AppRequest>()),
);

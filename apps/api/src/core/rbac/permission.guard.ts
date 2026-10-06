import { CanActivate, ExecutionContext, Injectable, Logger } from '@nestjs/common';
import { Reflector } from '@nestjs/core';
import { hasPermission } from '@fluggi/contracts';
import { forbidden } from '../http/app.exception';
import type { AppRequest } from '../auth/auth-context';
import {
  AUTHENTICATED_ONLY,
  IS_PUBLIC,
  REQUIRED_PERMISSION,
  type RequiredPermission,
} from '../auth/decorators';

/**
 * Default deny: endpoint без @Public / @AuthenticatedOnly / @RequirePermission
 * недоступен никому. Так забытая проверка прав не превращается в дыру.
 */
@Injectable()
export class PermissionGuard implements CanActivate {
  private readonly logger = new Logger(PermissionGuard.name);

  constructor(private readonly reflector: Reflector) {}

  canActivate(context: ExecutionContext): boolean {
    const targets = [context.getHandler(), context.getClass()];
    if (this.reflector.getAllAndOverride<boolean>(IS_PUBLIC, targets)) return true;

    const req = context.switchToHttp().getRequest<AppRequest>();
    const auth = req.auth!;

    const required = this.reflector.getAllAndOverride<RequiredPermission | undefined>(
      REQUIRED_PERMISSION,
      targets,
    );
    if (required) {
      if (hasPermission(auth.permissions, required.code, required.minScope)) return true;
      throw forbidden();
    }

    if (this.reflector.getAllAndOverride<boolean>(AUTHENTICATED_ONLY, targets)) return true;

    this.logger.error(`Endpoint ${req.method} ${req.path} не объявил требования к доступу`);
    throw forbidden();
  }
}

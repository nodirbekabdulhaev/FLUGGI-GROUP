"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.ReqMeta = exports.CurrentUser = exports.AuthenticatedOnly = exports.RequirePermission = exports.CrossOrigin = exports.CROSS_ORIGIN = exports.Public = exports.AUTHENTICATED_ONLY = exports.REQUIRED_PERMISSION = exports.IS_PUBLIC = void 0;
const common_1 = require("@nestjs/common");
const auth_context_1 = require("./auth-context");
exports.IS_PUBLIC = 'fluggi:isPublic';
exports.REQUIRED_PERMISSION = 'fluggi:requiredPermission';
exports.AUTHENTICATED_ONLY = 'fluggi:authenticatedOnly';
/** Endpoint доступен без входа (логин, health, webhook). */
const Public = () => (0, common_1.SetMetadata)(exports.IS_PUBLIC, true);
exports.Public = Public;
exports.CROSS_ORIGIN = 'fluggi:crossOrigin';
/**
 * Публичный приём данных с других сайтов (форма на WordPress, webhook Meta): без проверки Origin.
 * Сессия здесь не используется — cookie на межсайтовый POST не уходят (SameSite=Lax).
 */
const CrossOrigin = () => (0, common_1.SetMetadata)(exports.CROSS_ORIGIN, true);
exports.CrossOrigin = CrossOrigin;
/** Endpoint требует право (с минимальной областью). */
const RequirePermission = (code, minScope = 'OWN') => (0, common_1.SetMetadata)(exports.REQUIRED_PERMISSION, { code, minScope });
exports.RequirePermission = RequirePermission;
/**
 * Endpoint доступен любому вошедшему пользователю (профиль, справочники).
 * Без этого декоратора или RequirePermission доступ запрещён — default deny.
 */
const AuthenticatedOnly = () => (0, common_1.SetMetadata)(exports.AUTHENTICATED_ONLY, true);
exports.AuthenticatedOnly = AuthenticatedOnly;
exports.CurrentUser = (0, common_1.createParamDecorator)((_, ctx) => {
    const req = ctx.switchToHttp().getRequest();
    return req.auth;
});
exports.ReqMeta = (0, common_1.createParamDecorator)((_, ctx) => (0, auth_context_1.requestMeta)(ctx.switchToHttp().getRequest()));
//# sourceMappingURL=decorators.js.map
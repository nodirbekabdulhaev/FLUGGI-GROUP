"use strict";
var __decorate = (this && this.__decorate) || function (decorators, target, key, desc) {
    var c = arguments.length, r = c < 3 ? target : desc === null ? desc = Object.getOwnPropertyDescriptor(target, key) : desc, d;
    if (typeof Reflect === "object" && typeof Reflect.decorate === "function") r = Reflect.decorate(decorators, target, key, desc);
    else for (var i = decorators.length - 1; i >= 0; i--) if (d = decorators[i]) r = (c < 3 ? d(r) : c > 3 ? d(target, key, r) : d(target, key)) || r;
    return c > 3 && r && Object.defineProperty(target, key, r), r;
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.AllExceptionsFilter = void 0;
const common_1 = require("@nestjs/common");
const db_1 = require("@fluggi/db");
const app_exception_1 = require("./app.exception");
const CODE_BY_STATUS = {
    400: 'VALIDATION_ERROR',
    401: 'UNAUTHENTICATED',
    403: 'FORBIDDEN',
    404: 'NOT_FOUND',
    409: 'CONFLICT',
    413: 'VALIDATION_ERROR',
    422: 'VALIDATION_ERROR',
    429: 'RATE_LIMITED',
};
const DEFAULT_MESSAGES = {
    UNAUTHENTICATED: 'Требуется вход в систему',
    FORBIDDEN: 'Недостаточно прав',
    NOT_FOUND: 'Не найдено',
    RATE_LIMITED: 'Слишком много запросов. Попробуйте позже',
    VALIDATION_ERROR: 'Некорректный запрос',
    INTERNAL: 'Внутренняя ошибка сервера. Мы уже разбираемся',
};
/**
 * Единый формат ошибок API. Stack trace и детали серверных ошибок
 * пишутся только в лог и никогда не отдаются клиенту.
 */
let AllExceptionsFilter = class AllExceptionsFilter {
    logger = new common_1.Logger('HTTP');
    catch(exception, host) {
        const ctx = host.switchToHttp();
        const req = ctx.getRequest();
        const res = ctx.getResponse();
        const requestId = typeof req.id === 'string' ? req.id : undefined;
        const { status, body } = this.toResponse(exception, requestId, req);
        res.status(status).json(body);
    }
    toResponse(exception, requestId, req) {
        if (exception instanceof app_exception_1.AppException) {
            return {
                status: exception.getStatus(),
                body: {
                    error: {
                        code: exception.code,
                        message: exception.message,
                        details: exception.details,
                        requestId,
                    },
                },
            };
        }
        if (exception instanceof db_1.Prisma.PrismaClientKnownRequestError) {
            if (exception.code === 'P2002') {
                return this.simple(409, 'CONFLICT', 'Запись с такими данными уже существует', requestId);
            }
            if (exception.code === 'P2025') {
                return this.simple(404, 'NOT_FOUND', 'Запись не найдена', requestId);
            }
        }
        if (exception instanceof common_1.HttpException) {
            const status = exception.getStatus();
            const code = CODE_BY_STATUS[status] ?? (status >= 500 ? 'INTERNAL' : 'VALIDATION_ERROR');
            if (status >= 500)
                this.logger.error(exception, `${req.method} ${req.url}`);
            return this.simple(status, code, DEFAULT_MESSAGES[code] ?? exception.message, requestId);
        }
        this.logger.error({ err: exception, requestId }, `Unhandled error on ${req.method} ${req.url}`);
        return this.simple(500, 'INTERNAL', DEFAULT_MESSAGES.INTERNAL, requestId);
    }
    simple(status, code, message, requestId) {
        return { status, body: { error: { code, message, requestId } } };
    }
};
exports.AllExceptionsFilter = AllExceptionsFilter;
exports.AllExceptionsFilter = AllExceptionsFilter = __decorate([
    (0, common_1.Catch)()
], AllExceptionsFilter);
//# sourceMappingURL=all-exceptions.filter.js.map
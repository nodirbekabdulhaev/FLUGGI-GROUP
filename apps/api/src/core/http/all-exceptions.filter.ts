import { ArgumentsHost, Catch, ExceptionFilter, HttpException, Logger } from '@nestjs/common';
import { Prisma } from '@fluggi/db';
import type { ApiErrorBody, ErrorCode } from '@fluggi/contracts';
import type { Request, Response } from 'express';
import { AppException } from './app.exception';

const CODE_BY_STATUS: Record<number, ErrorCode> = {
  400: 'VALIDATION_ERROR',
  401: 'UNAUTHENTICATED',
  403: 'FORBIDDEN',
  404: 'NOT_FOUND',
  409: 'CONFLICT',
  413: 'VALIDATION_ERROR',
  422: 'VALIDATION_ERROR',
  429: 'RATE_LIMITED',
};

const DEFAULT_MESSAGES: Partial<Record<ErrorCode, string>> = {
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
@Catch()
export class AllExceptionsFilter implements ExceptionFilter {
  private readonly logger = new Logger('HTTP');

  catch(exception: unknown, host: ArgumentsHost) {
    const ctx = host.switchToHttp();
    const req = ctx.getRequest<Request & { id?: unknown }>();
    const res = ctx.getResponse<Response>();
    const requestId = typeof req.id === 'string' ? req.id : undefined;

    const { status, body } = this.toResponse(exception, requestId, req);
    res.status(status).json(body);
  }

  private toResponse(
    exception: unknown,
    requestId: string | undefined,
    req: Request,
  ): { status: number; body: ApiErrorBody } {
    if (exception instanceof AppException) {
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

    if (exception instanceof Prisma.PrismaClientKnownRequestError) {
      if (exception.code === 'P2002') {
        return this.simple(409, 'CONFLICT', 'Запись с такими данными уже существует', requestId);
      }
      if (exception.code === 'P2025') {
        return this.simple(404, 'NOT_FOUND', 'Запись не найдена', requestId);
      }
    }

    if (exception instanceof HttpException) {
      const status = exception.getStatus();
      const code = CODE_BY_STATUS[status] ?? (status >= 500 ? 'INTERNAL' : 'VALIDATION_ERROR');
      if (status >= 500) this.logger.error(exception, `${req.method} ${req.url}`);
      return this.simple(status, code, DEFAULT_MESSAGES[code] ?? exception.message, requestId);
    }

    this.logger.error(
      { err: exception, requestId },
      `Unhandled error on ${req.method} ${req.url}`,
    );
    return this.simple(500, 'INTERNAL', DEFAULT_MESSAGES.INTERNAL!, requestId);
  }

  private simple(status: number, code: ErrorCode, message: string, requestId?: string) {
    return { status, body: { error: { code, message, requestId } } };
  }
}

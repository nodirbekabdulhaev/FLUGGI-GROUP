import { HttpException, HttpStatus } from '@nestjs/common';
import type { ApiErrorDetail, ErrorCode } from '@fluggi/contracts';

const STATUS_BY_CODE: Record<ErrorCode, number> = {
  UNAUTHENTICATED: HttpStatus.UNAUTHORIZED,
  FORBIDDEN: HttpStatus.FORBIDDEN,
  NOT_FOUND: HttpStatus.NOT_FOUND,
  CONFLICT: HttpStatus.CONFLICT,
  VALIDATION_ERROR: HttpStatus.UNPROCESSABLE_ENTITY,
  BUSINESS_RULE_VIOLATION: HttpStatus.UNPROCESSABLE_ENTITY,
  RATE_LIMITED: HttpStatus.TOO_MANY_REQUESTS,
  CSRF_INVALID: HttpStatus.FORBIDDEN,
  INTERNAL: HttpStatus.INTERNAL_SERVER_ERROR,
};

/** Ошибка с понятным пользователю сообщением и машинным кодом. */
export class AppException extends HttpException {
  constructor(
    readonly code: ErrorCode,
    message: string,
    readonly details?: ApiErrorDetail[],
  ) {
    super(message, STATUS_BY_CODE[code]);
  }
}

export const notFound = (what = 'Запись') => new AppException('NOT_FOUND', `${what} не найдена`);
export const forbidden = (message = 'Недостаточно прав для этого действия') =>
  new AppException('FORBIDDEN', message);
export const conflict = (message: string) => new AppException('CONFLICT', message);
export const businessRule = (message: string, details?: ApiErrorDetail[]) =>
  new AppException('BUSINESS_RULE_VIOLATION', message, details);

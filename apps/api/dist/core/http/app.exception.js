"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.businessRule = exports.conflict = exports.forbidden = exports.notFound = exports.AppException = void 0;
const common_1 = require("@nestjs/common");
const STATUS_BY_CODE = {
    UNAUTHENTICATED: common_1.HttpStatus.UNAUTHORIZED,
    FORBIDDEN: common_1.HttpStatus.FORBIDDEN,
    NOT_FOUND: common_1.HttpStatus.NOT_FOUND,
    CONFLICT: common_1.HttpStatus.CONFLICT,
    VALIDATION_ERROR: common_1.HttpStatus.UNPROCESSABLE_ENTITY,
    BUSINESS_RULE_VIOLATION: common_1.HttpStatus.UNPROCESSABLE_ENTITY,
    RATE_LIMITED: common_1.HttpStatus.TOO_MANY_REQUESTS,
    CSRF_INVALID: common_1.HttpStatus.FORBIDDEN,
    INTERNAL: common_1.HttpStatus.INTERNAL_SERVER_ERROR,
};
/** Ошибка с понятным пользователю сообщением и машинным кодом. */
class AppException extends common_1.HttpException {
    code;
    details;
    constructor(code, message, details) {
        super(message, STATUS_BY_CODE[code]);
        this.code = code;
        this.details = details;
    }
}
exports.AppException = AppException;
const notFound = (what = 'Запись') => new AppException('NOT_FOUND', `${what} не найдена`);
exports.notFound = notFound;
const forbidden = (message = 'Недостаточно прав для этого действия') => new AppException('FORBIDDEN', message);
exports.forbidden = forbidden;
const conflict = (message) => new AppException('CONFLICT', message);
exports.conflict = conflict;
const businessRule = (message, details) => new AppException('BUSINESS_RULE_VIOLATION', message, details);
exports.businessRule = businessRule;
//# sourceMappingURL=app.exception.js.map
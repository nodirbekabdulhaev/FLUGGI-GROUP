"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.CSRF_HEADER = exports.CSRF_COOKIE = exports.SESSION_COOKIE = void 0;
/**
 * Имена cookie/заголовков, общие для web и api. Отдельный файл без зависимостей —
 * его можно импортировать в Next.js middleware, не затягивая zod в edge-бандл.
 */
exports.SESSION_COOKIE = 'fluggi_session';
exports.CSRF_COOKIE = 'fluggi_csrf';
exports.CSRF_HEADER = 'x-csrf-token';
//# sourceMappingURL=constants.js.map
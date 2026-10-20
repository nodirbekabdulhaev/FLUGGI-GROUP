/**
 * Имена cookie/заголовков, общие для web и api. Отдельный файл без зависимостей —
 * его можно импортировать в Next.js middleware, не затягивая zod в edge-бандл.
 */
export declare const SESSION_COOKIE = "fluggi_session";
export declare const CSRF_COOKIE = "fluggi_csrf";
export declare const CSRF_HEADER = "x-csrf-token";

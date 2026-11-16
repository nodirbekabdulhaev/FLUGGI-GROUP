"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.detectMime = detectMime;
exports.signatureMatches = signatureMatches;
/**
 * Проверка типа файла по сигнатуре (magic bytes), а не только по заголовку браузера.
 * Возвращает mime по содержимому или null.
 */
function detectMime(buf) {
    const hex = buf.subarray(0, 12).toString('hex');
    if (buf.subarray(0, 5).toString('latin1') === '%PDF-')
        return 'application/pdf';
    if (hex.startsWith('89504e470d0a1a0a'))
        return 'image/png';
    if (hex.startsWith('ffd8ff'))
        return 'image/jpeg';
    if (buf.subarray(0, 4).toString('latin1') === 'RIFF' &&
        buf.subarray(8, 12).toString('latin1') === 'WEBP')
        return 'image/webp';
    if (hex.startsWith('504b0304'))
        return 'application/zip';
    return null;
}
/** docx/xlsx — это zip-архивы: допускаем zip-сигнатуру для них. */
function signatureMatches(declared, detected) {
    if (!detected)
        return false;
    if (declared === detected)
        return true;
    return (detected === 'application/zip' &&
        declared.startsWith('application/vnd.openxmlformats-officedocument'));
}
//# sourceMappingURL=file-signature.js.map
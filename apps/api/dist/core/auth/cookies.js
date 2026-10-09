"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.setSessionCookie = setSessionCookie;
exports.issueCsrfCookie = issueCsrfCookie;
exports.clearAuthCookies = clearAuthCookies;
const node_crypto_1 = require("node:crypto");
const contracts_1 = require("@fluggi/contracts");
const env_1 = require("../../config/env");
function base() {
    return { path: '/', sameSite: 'lax', secure: (0, env_1.loadEnv)().NODE_ENV === 'production' };
}
function setSessionCookie(res, token, expiresAt) {
    res.cookie(contracts_1.SESSION_COOKIE, token, { ...base(), httpOnly: true, expires: expiresAt });
}
/** CSRF-токен (double submit): читается фронтендом и возвращается в заголовке. */
function issueCsrfCookie(res) {
    const token = (0, node_crypto_1.randomBytes)(24).toString('base64url');
    res.cookie(contracts_1.CSRF_COOKIE, token, { ...base(), httpOnly: false });
    return token;
}
function clearAuthCookies(res) {
    res.clearCookie(contracts_1.SESSION_COOKIE, base());
    res.clearCookie(contracts_1.CSRF_COOKIE, base());
}
//# sourceMappingURL=cookies.js.map
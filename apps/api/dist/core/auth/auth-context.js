"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.requestMeta = requestMeta;
function requestMeta(req) {
    return {
        ip: req.ip ?? null,
        userAgent: req.get('user-agent')?.slice(0, 500) ?? null,
        sessionId: req.auth?.sessionId ?? null,
    };
}
//# sourceMappingURL=auth-context.js.map
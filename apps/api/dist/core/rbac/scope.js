"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.scopeOf = scopeOf;
exports.scopeWhere = scopeWhere;
const app_exception_1 = require("../http/app.exception");
/** Область видимости пользователя для права; без права — 403. */
function scopeOf(auth, code) {
    const scope = auth.permissions[code];
    if (!scope)
        throw (0, app_exception_1.forbidden)();
    return scope;
}
/**
 * Prisma-where для области видимости. Используется в каждом репозитории,
 * поэтому ограничение данных не зависит от фронтенда.
 *  ALL  → без ограничений
 *  TEAM → отделы, которыми руководит пользователь (+ его собственный отдел)
 *  OWN  → только свои записи
 */
function scopeWhere(auth, code, fields) {
    const scope = scopeOf(auth, code);
    if (scope === 'ALL')
        return {};
    if (scope === 'TEAM') {
        const teamIds = [...new Set([...auth.headedTeamIds, ...(auth.teamId ? [auth.teamId] : [])])];
        return fields.team(teamIds, auth.userId);
    }
    return fields.own(auth.userId);
}
//# sourceMappingURL=scope.js.map
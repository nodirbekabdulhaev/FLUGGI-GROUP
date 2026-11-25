"use strict";
var __decorate = (this && this.__decorate) || function (decorators, target, key, desc) {
    var c = arguments.length, r = c < 3 ? target : desc === null ? desc = Object.getOwnPropertyDescriptor(target, key) : desc, d;
    if (typeof Reflect === "object" && typeof Reflect.decorate === "function") r = Reflect.decorate(decorators, target, key, desc);
    else for (var i = decorators.length - 1; i >= 0; i--) if (d = decorators[i]) r = (c < 3 ? d(r) : c > 3 ? d(target, key, r) : d(target, key)) || r;
    return c > 3 && r && Object.defineProperty(target, key, r), r;
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.AuditService = void 0;
exports.diffFields = diffFields;
const common_1 = require("@nestjs/common");
const db_1 = require("@fluggi/db");
/** Сравнивает значения выбранных полей и возвращает только изменившиеся. */
function diffFields(before, after, fields) {
    const changes = {};
    for (const field of fields) {
        if (!(field in after))
            continue;
        const oldValue = normalize(before[field]);
        const newValue = normalize(after[field]);
        if (JSON.stringify(oldValue) !== JSON.stringify(newValue)) {
            changes[String(field)] = { old: oldValue, new: newValue };
        }
    }
    return Object.keys(changes).length > 0 ? changes : null;
}
function normalize(v) {
    if (v === undefined)
        return null;
    if (v instanceof Date)
        return v.toISOString();
    if (v instanceof db_1.Prisma.Decimal)
        return v.toString();
    return v;
}
/**
 * Журнал аудита (ТЗ §45). Пишется в той же транзакции, что и изменение,
 * поэтому изменение без записи в журнал невозможно. Таблица append-only.
 */
let AuditService = class AuditService {
    async log(tx, entry) {
        await tx.auditLog.create({
            data: {
                actorId: entry.actorId,
                action: entry.action,
                entityType: entry.entityType,
                entityId: entry.entityId ?? null,
                changes: (entry.changes ?? undefined),
                ip: entry.meta?.ip ?? null,
                userAgent: entry.meta?.userAgent ?? null,
                sessionId: entry.meta?.sessionId ?? null,
            },
        });
    }
};
exports.AuditService = AuditService;
exports.AuditService = AuditService = __decorate([
    (0, common_1.Injectable)()
], AuditService);
//# sourceMappingURL=audit.service.js.map
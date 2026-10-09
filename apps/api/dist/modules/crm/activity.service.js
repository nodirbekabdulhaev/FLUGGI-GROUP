"use strict";
var __decorate = (this && this.__decorate) || function (decorators, target, key, desc) {
    var c = arguments.length, r = c < 3 ? target : desc === null ? desc = Object.getOwnPropertyDescriptor(target, key) : desc, d;
    if (typeof Reflect === "object" && typeof Reflect.decorate === "function") r = Reflect.decorate(decorators, target, key, desc);
    else for (var i = decorators.length - 1; i >= 0; i--) if (d = decorators[i]) r = (c < 3 ? d(r) : c > 3 ? d(target, key, r) : d(target, key)) || r;
    return c > 3 && r && Object.defineProperty(target, key, r), r;
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.ActivityService = void 0;
const common_1 = require("@nestjs/common");
/** Таймлайн (ТЗ §12): пишется в транзакции изменения, удалить нельзя (триггер). */
let ActivityService = class ActivityService {
    async log(tx, e) {
        await tx.activity.create({
            data: {
                type: e.type,
                actorId: e.actorId,
                leadId: e.leadId ?? null,
                dealId: e.dealId ?? null,
                clientId: e.clientId ?? null,
                meetingId: e.meetingId ?? null,
                projectId: e.projectId ?? null,
                taskId: e.taskId ?? null,
                payload: (e.payload ?? undefined),
            },
        });
    }
    /** Запись перехода по воронке + длительность пребывания на прошлом этапе. */
    async stageChange(tx, target, fromStageId, toStageId, changedById) {
        const last = await tx.stageHistory.findFirst({
            where: target.leadId ? { leadId: target.leadId } : { dealId: target.dealId },
            orderBy: { createdAt: 'desc' },
        });
        const durationSec = last ? Math.round((Date.now() - last.createdAt.getTime()) / 1000) : null;
        await tx.stageHistory.create({
            data: {
                leadId: target.leadId,
                dealId: target.dealId,
                fromStageId,
                toStageId,
                changedById,
                durationSec,
            },
        });
    }
};
exports.ActivityService = ActivityService;
exports.ActivityService = ActivityService = __decorate([
    (0, common_1.Injectable)()
], ActivityService);
//# sourceMappingURL=activity.service.js.map
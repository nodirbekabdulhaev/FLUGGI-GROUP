"use strict";
var __decorate = (this && this.__decorate) || function (decorators, target, key, desc) {
    var c = arguments.length, r = c < 3 ? target : desc === null ? desc = Object.getOwnPropertyDescriptor(target, key) : desc, d;
    if (typeof Reflect === "object" && typeof Reflect.decorate === "function") r = Reflect.decorate(decorators, target, key, desc);
    else for (var i = decorators.length - 1; i >= 0; i--) if (d = decorators[i]) r = (c < 3 ? d(r) : c > 3 ? d(target, key, r) : d(target, key)) || r;
    return c > 3 && r && Object.defineProperty(target, key, r), r;
};
var __metadata = (this && this.__metadata) || function (k, v) {
    if (typeof Reflect === "object" && typeof Reflect.metadata === "function") return Reflect.metadata(k, v);
};
var OverdueScanner_1;
Object.defineProperty(exports, "__esModule", { value: true });
exports.OverdueScanner = exports.OVERDUE_STEPS = void 0;
const common_1 = require("@nestjs/common");
const contracts_1 = require("@fluggi/contracts");
const domain_1 = require("@fluggi/domain");
const outbox_service_1 = require("../../core/outbox/outbox.service");
const prisma_service_1 = require("../../core/prisma/prisma.service");
/** Пороги напоминаний о просрочке, дней (ТЗ §24: «Просрочено 1 / 3 / 7 дней»). */
exports.OVERDUE_STEPS = [1, 3, 7];
const step = (days) => exports.OVERDUE_STEPS.filter((s) => days >= s).length;
/**
 * Поиск просроченных задач и постановка уведомлений в outbox.
 * Уведомление отправляется при переходе порога 1, 3 и 7 дней — не чаще.
 * Запускается планировщиком (automation) раз в час; несколько экземпляров безопасны:
 * строка задачи блокируется FOR UPDATE SKIP LOCKED.
 */
let OverdueScanner = OverdueScanner_1 = class OverdueScanner {
    prisma;
    outbox;
    logger = new common_1.Logger(OverdueScanner_1.name);
    constructor(prisma, outbox) {
        this.prisma = prisma;
        this.outbox = outbox;
    }
    /** Пора ли напоминать: с прошлого уведомления пройден новый порог 1/3/7 дней. */
    due(deadline, notifiedAt, now) {
        const days = (0, domain_1.taskOverdueDays)(deadline, true, now);
        const notified = notifiedAt ? (0, domain_1.taskOverdueDays)(deadline, true, notifiedAt) : 0;
        return step(days) > step(notified);
    }
    async scan(now = new Date()) {
        let sent = 0;
        const candidates = await this.prisma.task.findMany({
            where: {
                deletedAt: null,
                status: { in: [...contracts_1.OPEN_TASK_STATUSES] },
                deadline: { lt: now },
                project: { deletedAt: null, status: { notIn: ['COMPLETED', 'CANCELLED'] } },
            },
            select: { id: true, deadline: true, overdueNotifiedAt: true },
            take: 500,
        });
        for (const c of candidates) {
            if (!this.due(c.deadline, c.overdueNotifiedAt, now))
                continue;
            const done = await this.prisma.$transaction(async (tx) => {
                const locked = await tx.$queryRaw `
          SELECT id FROM tasks WHERE id = ${c.id} ${this.prisma.lockRows}`;
                if (locked.length === 0)
                    return false;
                // Перечитываем под блокировкой: другой экземпляр мог уже отправить уведомление.
                const fresh = await tx.task.findUniqueOrThrow({ where: { id: c.id } });
                if (!this.due(fresh.deadline, fresh.overdueNotifiedAt, now))
                    return false;
                await tx.task.update({ where: { id: c.id }, data: { overdueNotifiedAt: now } });
                await this.outbox.publish(tx, 'task.overdue', { taskId: c.id, overdueDays: (0, domain_1.taskOverdueDays)(fresh.deadline, true, now) }, null);
                return true;
            });
            if (done)
                sent += 1;
        }
        if (sent > 0)
            this.logger.log(`Overdue notifications queued: ${sent}`);
        return sent;
    }
};
exports.OverdueScanner = OverdueScanner;
exports.OverdueScanner = OverdueScanner = OverdueScanner_1 = __decorate([
    (0, common_1.Injectable)(),
    __metadata("design:paramtypes", [prisma_service_1.PrismaService,
        outbox_service_1.OutboxService])
], OverdueScanner);
//# sourceMappingURL=overdue.runner.js.map
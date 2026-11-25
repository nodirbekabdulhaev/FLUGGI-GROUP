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
var OutboxDispatcher_1;
Object.defineProperty(exports, "__esModule", { value: true });
exports.OutboxDispatcher = void 0;
const common_1 = require("@nestjs/common");
const prisma_service_1 = require("../prisma/prisma.service");
const BATCH_SIZE = 20;
const MAX_ATTEMPTS = 10;
/**
 * Доставка событий из outbox подписчикам. Работает в worker-процессе.
 * Несколько worker'ов безопасны: строки блокируются через FOR UPDATE SKIP LOCKED.
 * Ошибка подписчика → повтор с экспоненциальной задержкой (до MAX_ATTEMPTS).
 */
let OutboxDispatcher = OutboxDispatcher_1 = class OutboxDispatcher {
    prisma;
    logger = new common_1.Logger(OutboxDispatcher_1.name);
    handlers = new Map();
    constructor(prisma) {
        this.prisma = prisma;
    }
    on(type, handler) {
        const list = this.handlers.get(type) ?? [];
        list.push(handler);
        this.handlers.set(type, list);
    }
    /** Обрабатывает одну пачку событий. Возвращает количество обработанных. */
    async processBatch() {
        return this.prisma.$transaction(async (tx) => {
            const rows = await tx.$queryRaw `
          SELECT id, type, payload, actor_id, created_at, attempts
          FROM outbox_events
          WHERE processed_at IS NULL AND available_at <= UTC_TIMESTAMP(3) AND attempts < ${MAX_ATTEMPTS}
          ORDER BY created_at
          LIMIT ${BATCH_SIZE}
          ${this.prisma.lockRows}`;
            for (const row of rows) {
                const handlers = this.handlers.get(row.type) ?? [];
                try {
                    for (const handler of handlers) {
                        await handler(row.payload, {
                            eventId: row.id,
                            actorId: row.actor_id,
                            createdAt: row.created_at,
                        });
                    }
                    await tx.outboxEvent.update({
                        where: { id: row.id },
                        data: { processedAt: new Date(), attempts: row.attempts + 1, lastError: null },
                    });
                }
                catch (err) {
                    const attempts = row.attempts + 1;
                    const delayMs = Math.min(2 ** attempts * 1000, 60 * 60 * 1000);
                    this.logger.warn({ err, eventId: row.id, type: row.type, attempts }, 'Outbox handler failed');
                    await tx.outboxEvent.update({
                        where: { id: row.id },
                        data: {
                            attempts,
                            availableAt: new Date(Date.now() + delayMs),
                            lastError: err instanceof Error ? err.message.slice(0, 1000) : String(err),
                        },
                    });
                }
            }
            return rows.length;
        }, { timeout: 60_000 });
    }
};
exports.OutboxDispatcher = OutboxDispatcher;
exports.OutboxDispatcher = OutboxDispatcher = OutboxDispatcher_1 = __decorate([
    (0, common_1.Injectable)(),
    __metadata("design:paramtypes", [prisma_service_1.PrismaService])
], OutboxDispatcher);
//# sourceMappingURL=outbox.dispatcher.js.map
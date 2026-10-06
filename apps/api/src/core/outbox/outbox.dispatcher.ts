import { Injectable, Logger } from '@nestjs/common';
import { PrismaService } from '../prisma/prisma.service';
import type { DomainEvents, DomainEventType } from './events';

export type EventHandler<T extends DomainEventType = DomainEventType> = (
  payload: DomainEvents[T],
  meta: { eventId: string; actorId: string | null; createdAt: Date },
) => Promise<void>;

const BATCH_SIZE = 20;
const MAX_ATTEMPTS = 10;

/**
 * Доставка событий из outbox подписчикам. Работает в worker-процессе.
 * Несколько worker'ов безопасны: строки блокируются через FOR UPDATE SKIP LOCKED.
 * Ошибка подписчика → повтор с экспоненциальной задержкой (до MAX_ATTEMPTS).
 */
@Injectable()
export class OutboxDispatcher {
  private readonly logger = new Logger(OutboxDispatcher.name);
  private readonly handlers = new Map<string, EventHandler[]>();

  constructor(private readonly prisma: PrismaService) {}

  on<T extends DomainEventType>(type: T, handler: EventHandler<T>) {
    const list = this.handlers.get(type) ?? [];
    list.push(handler as EventHandler);
    this.handlers.set(type, list);
  }

  /** Обрабатывает одну пачку событий. Возвращает количество обработанных. */
  async processBatch(): Promise<number> {
    return this.prisma.$transaction(
      async (tx) => {
        const rows = await tx.$queryRaw<
          {
            id: string;
            type: string;
            payload: unknown;
            actor_id: string | null;
            created_at: Date;
            attempts: number;
          }[]
        >`
          SELECT id, type, payload, actor_id, created_at, attempts
          FROM outbox_events
          WHERE processed_at IS NULL AND available_at <= now() AND attempts < ${MAX_ATTEMPTS}
          ORDER BY created_at
          LIMIT ${BATCH_SIZE}
          FOR UPDATE SKIP LOCKED`;

        for (const row of rows) {
          const handlers = this.handlers.get(row.type) ?? [];
          try {
            for (const handler of handlers) {
              await handler(row.payload as never, {
                eventId: row.id,
                actorId: row.actor_id,
                createdAt: row.created_at,
              });
            }
            await tx.outboxEvent.update({
              where: { id: row.id },
              data: { processedAt: new Date(), attempts: row.attempts + 1, lastError: null },
            });
          } catch (err) {
            const attempts = row.attempts + 1;
            const delayMs = Math.min(2 ** attempts * 1000, 60 * 60 * 1000);
            this.logger.warn(
              { err, eventId: row.id, type: row.type, attempts },
              'Outbox handler failed',
            );
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
      },
      { timeout: 60_000 },
    );
  }
}

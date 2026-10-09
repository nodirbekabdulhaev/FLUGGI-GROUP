import { Injectable } from '@nestjs/common';
import type { Prisma } from '@fluggi/db';
import type { Tx } from '../prisma/prisma.service';
import type { DomainEvents, DomainEventType } from './events';

@Injectable()
export class OutboxService {
  /** Публикует событие внутри текущей транзакции (Transactional Outbox). */
  async publish<T extends DomainEventType>(
    tx: Tx,
    type: T,
    payload: DomainEvents[T],
    actorId: string | null,
  ): Promise<void> {
    await tx.outboxEvent.create({
      data: { type, payload: payload as unknown as Prisma.InputJsonValue, actorId },
    });
  }
}

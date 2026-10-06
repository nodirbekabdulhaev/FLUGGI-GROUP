import { Injectable } from '@nestjs/common';
import type { Prisma } from '@fluggi/db';
import type { Tx } from '../../core/prisma/prisma.service';

export interface ActivityEntry {
  type: string;
  actorId: string | null;
  leadId?: string | null;
  dealId?: string | null;
  clientId?: string | null;
  meetingId?: string | null;
  payload?: Record<string, unknown>;
}

/** Таймлайн (ТЗ §12): пишется в транзакции изменения, удалить нельзя (триггер). */
@Injectable()
export class ActivityService {
  async log(tx: Tx, e: ActivityEntry): Promise<void> {
    await tx.activity.create({
      data: {
        type: e.type,
        actorId: e.actorId,
        leadId: e.leadId ?? null,
        dealId: e.dealId ?? null,
        clientId: e.clientId ?? null,
        meetingId: e.meetingId ?? null,
        payload: (e.payload ?? undefined) as Prisma.InputJsonValue | undefined,
      },
    });
  }

  /** Запись перехода по воронке + длительность пребывания на прошлом этапе. */
  async stageChange(
    tx: Tx,
    target: { leadId?: string; dealId?: string },
    fromStageId: string | null,
    toStageId: string,
    changedById: string,
  ): Promise<void> {
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
}

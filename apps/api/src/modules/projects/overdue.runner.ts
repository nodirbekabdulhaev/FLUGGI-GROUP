import { Injectable, Logger } from '@nestjs/common';
import { OPEN_TASK_STATUSES } from '@fluggi/contracts';
import { taskOverdueDays } from '@fluggi/domain';
import { OutboxService } from '../../core/outbox/outbox.service';
import { PrismaService } from '../../core/prisma/prisma.service';

/** Пороги напоминаний о просрочке, дней (ТЗ §24: «Просрочено 1 / 3 / 7 дней»). */
export const OVERDUE_STEPS = [1, 3, 7] as const;

const step = (days: number) => OVERDUE_STEPS.filter((s) => days >= s).length;

/**
 * Поиск просроченных задач и постановка уведомлений в outbox.
 * Уведомление отправляется при переходе порога 1, 3 и 7 дней — не чаще.
 * Запускается планировщиком (automation) раз в час; несколько экземпляров безопасны:
 * строка задачи блокируется FOR UPDATE SKIP LOCKED.
 */
@Injectable()
export class OverdueScanner {
  private readonly logger = new Logger(OverdueScanner.name);

  constructor(
    private readonly prisma: PrismaService,
    private readonly outbox: OutboxService,
  ) {}

  /** Пора ли напоминать: с прошлого уведомления пройден новый порог 1/3/7 дней. */
  private due(deadline: Date | null, notifiedAt: Date | null, now: Date) {
    const days = taskOverdueDays(deadline, true, now);
    const notified = notifiedAt ? taskOverdueDays(deadline, true, notifiedAt) : 0;
    return step(days) > step(notified);
  }

  async scan(now = new Date()): Promise<number> {
    let sent = 0;
    const candidates = await this.prisma.task.findMany({
      where: {
        deletedAt: null,
        status: { in: [...OPEN_TASK_STATUSES] },
        deadline: { lt: now },
        project: { deletedAt: null, status: { notIn: ['COMPLETED', 'CANCELLED'] } },
      },
      select: { id: true, deadline: true, overdueNotifiedAt: true },
      take: 500,
    });
    for (const c of candidates) {
      if (!this.due(c.deadline, c.overdueNotifiedAt, now)) continue;
      const done = await this.prisma.$transaction(async (tx) => {
        const locked = await tx.$queryRaw<{ id: string }[]>`
          SELECT id FROM tasks WHERE id = ${c.id}::uuid FOR UPDATE SKIP LOCKED`;
        if (locked.length === 0) return false;
        // Перечитываем под блокировкой: другой экземпляр мог уже отправить уведомление.
        const fresh = await tx.task.findUniqueOrThrow({ where: { id: c.id } });
        if (!this.due(fresh.deadline, fresh.overdueNotifiedAt, now)) return false;
        await tx.task.update({ where: { id: c.id }, data: { overdueNotifiedAt: now } });
        await this.outbox.publish(
          tx,
          'task.overdue',
          { taskId: c.id, overdueDays: taskOverdueDays(fresh.deadline, true, now) },
          null,
        );
        return true;
      });
      if (done) sent += 1;
    }
    if (sent > 0) this.logger.log(`Overdue notifications queued: ${sent}`);
    return sent;
  }
}

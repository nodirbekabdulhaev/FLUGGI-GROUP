import { Injectable, Logger, OnApplicationBootstrap, OnApplicationShutdown } from '@nestjs/common';
import { loadEnv } from '../../config/env';
import { PrismaService } from '../../core/prisma/prisma.service';
import { TelegramApiError, TelegramClient } from './telegram.client';
import { TelegramService } from './telegram.service';

const MAX_ATTEMPTS = 5;
/** Пауза перед повтором: 30 с, 2 мин, 8 мин, 30 мин. */
const backoff = (attempt: number) => Math.min(30_000 * 4 ** (attempt - 1), 30 * 60_000);

/** Где работают фоновые циклы: в worker, а в разработке — и в API (как outbox). */
export const backgroundEnabled = () => process.env.OUTBOX_IN_API !== 'false';

/**
 * Доставка уведомлений в Telegram (ТЗ §53): очередь в БД, повторы с паузой,
 * журнал ошибок. Сбой Telegram не влияет на бизнес-операции.
 */
@Injectable()
export class TelegramSender {
  private readonly logger = new Logger(TelegramSender.name);

  constructor(
    private readonly prisma: PrismaService,
    private readonly client: TelegramClient,
  ) {}

  async processBatch(limit = 20): Promise<number> {
    if (!this.client.configured) return 0;
    const appUrl = loadEnv().APP_URL;
    return this.prisma.$transaction(
      async (tx) => {
        const rows = await tx.$queryRaw<{ id: string }[]>`
          SELECT id FROM notification_deliveries
          WHERE status = 'PENDING' AND next_attempt_at <= now()
          ORDER BY created_at LIMIT ${limit} FOR UPDATE SKIP LOCKED`;
        for (const { id } of rows) {
          const d = await tx.notificationDelivery.findUniqueOrThrow({
            where: { id },
            include: { user: true },
          });
          if (!d.user.telegramChatId) {
            await tx.notificationDelivery.update({
              where: { id },
              data: { status: 'FAILED', lastError: 'Telegram не подключён' },
            });
            continue;
          }
          const text = [
            `<b>${TelegramClient.escape(d.title)}</b>`,
            d.body ? TelegramClient.escape(d.body) : null,
            d.link ? `<a href="${appUrl}${d.link}">Открыть в CRM</a>` : null,
          ]
            .filter(Boolean)
            .join('\n');
          try {
            await this.client.sendMessage(d.user.telegramChatId, text);
            await tx.notificationDelivery.update({
              where: { id },
              data: { status: 'SENT', sentAt: new Date(), attempts: { increment: 1 } },
            });
          } catch (err) {
            const e = err as TelegramApiError;
            const attempts = d.attempts + 1;
            const final =
              (e instanceof TelegramApiError && e.permanent) || attempts >= MAX_ATTEMPTS;
            await tx.notificationDelivery.update({
              where: { id },
              data: {
                attempts,
                lastError: String(e.message).slice(0, 500),
                status: final ? 'FAILED' : 'PENDING',
                nextAttemptAt: new Date(
                  Date.now() + (e.retryAfter ? e.retryAfter * 1000 : backoff(attempts)),
                ),
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

@Injectable()
export class TelegramRunner implements OnApplicationBootstrap, OnApplicationShutdown {
  private readonly logger = new Logger(TelegramRunner.name);
  private stopped = false;
  private offset = 0;

  constructor(
    private readonly sender: TelegramSender,
    private readonly client: TelegramClient,
    private readonly telegram: TelegramService,
  ) {}

  onApplicationBootstrap() {
    if (!backgroundEnabled() || !this.client.configured) return;
    void this.sendLoop();
    if (loadEnv().TELEGRAM_MODE === 'polling') void this.pollLoop();
  }

  onApplicationShutdown() {
    this.stopped = true;
  }

  private sleep(ms: number) {
    return new Promise((r) => setTimeout(r, ms).unref());
  }

  private async sendLoop() {
    while (!this.stopped) {
      try {
        const n = await this.sender.processBatch();
        await this.sleep(n > 0 ? 200 : 3000);
      } catch (err) {
        this.logger.error(err, 'Telegram send batch failed');
        await this.sleep(15_000);
      }
    }
  }

  /** Режим polling: бот сам забирает сообщения — подходит для локального запуска без домена. */
  private async pollLoop() {
    while (!this.stopped) {
      try {
        const updates = await this.client.getUpdates(this.offset, 25);
        for (const u of updates) {
          this.offset = u.update_id + 1;
          await this.telegram.handleUpdate(u);
        }
      } catch (err) {
        const e = err as TelegramApiError;
        // 409 — запущен webhook или второй poller; ждём дольше
        this.logger.warn(`Telegram polling: ${e.message}`);
        await this.sleep(e.status === 409 ? 60_000 : 10_000);
      }
    }
  }
}

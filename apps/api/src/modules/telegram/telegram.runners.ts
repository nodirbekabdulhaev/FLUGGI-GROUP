import { Injectable, Logger, OnApplicationBootstrap, OnApplicationShutdown } from '@nestjs/common';
import { loadEnv } from '../../config/env';
import { PrismaService } from '../../core/prisma/prisma.service';
import { backgroundEnabled, TelegramApiError, TelegramClient } from './telegram.client';
import { TelegramService } from './telegram.service';

const MAX_ATTEMPTS = 5;
/** Пауза перед повтором: 30 с, 2 мин, 8 мин, 30 мин. */
const backoff = (attempt: number) => Math.min(30_000 * 4 ** (attempt - 1), 30 * 60_000);

export { backgroundEnabled } from './telegram.client';

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

const HEARTBEAT_MS = 30_000;

@Injectable()
export class TelegramRunner implements OnApplicationBootstrap, OnApplicationShutdown {
  private readonly logger = new Logger(TelegramRunner.name);
  private stopped = false;
  private offset = 0;
  private bot: string | null = null;
  private lastBeat = 0;

  constructor(
    private readonly sender: TelegramSender,
    private readonly client: TelegramClient,
    private readonly telegram: TelegramService,
  ) {}

  onApplicationBootstrap() {
    if (!backgroundEnabled() || !this.client.configured) return;
    const mode = loadEnv().TELEGRAM_MODE;
    void this.check().then((ok) => {
      if (ok) this.logger.log(`Telegram-бот @${this.bot} подключён, режим ${mode}`);
      void this.sendLoop();
      if (mode === 'polling') void this.pollLoop();
      else void this.webhookHeartbeat();
    });
  }

  onApplicationShutdown() {
    this.stopped = true;
  }

  /**
   * Проверка токена (getMe) и имени бота. Результат сохраняется в БД: профиль показывает
   * причину сбоя, а если проверок давно не было — что бот не запущен.
   */
  async check(): Promise<boolean> {
    let problem: string | null = null;
    try {
      this.bot = (await this.client.getMe()).username;
      const configured = this.client.username;
      if (configured && configured.toLowerCase() !== this.bot.toLowerCase())
        problem = `TELEGRAM_BOT_USERNAME (@${configured}) не совпадает с ботом, которому принадлежит токен (@${this.bot}). Исправьте .env или удалите TELEGRAM_BOT_USERNAME и перезапустите API`;
    } catch (err) {
      problem = TelegramService.explain(err);
    }
    if (problem) this.logger.error(`Telegram: ${problem}`);
    await this.beat(problem, true);
    return !problem;
  }

  private async beat(problem: string | null, force = false) {
    if (!force && !problem && Date.now() - this.lastBeat < HEARTBEAT_MS) return;
    this.lastBeat = Date.now();
    await this.telegram
      .reportHealth({ problem, bot: this.bot })
      .catch((err) => this.logger.warn(`Telegram health: ${(err as Error).message}`));
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

  /** Режим webhook: сообщения принимает API, здесь — только периодическая проверка токена. */
  private async webhookHeartbeat() {
    while (!this.stopped) {
      await this.sleep(60_000);
      await this.check();
    }
  }

  /** Режим polling: бот сам забирает сообщения — подходит для локального запуска без домена. */
  private async pollLoop() {
    let failing = false;
    while (!this.stopped) {
      try {
        const updates = await this.client.getUpdates(this.offset, 25);
        if (failing) this.logger.log('Telegram polling восстановлен');
        // После сбоя (или если бот не был доступен при запуске) — полная проверка: имя бота,
        // соответствие .env; иначе профиль ещё долго показывал бы старую ошибку
        if (failing || !this.bot) await this.check();
        else await this.beat(null);
        failing = false;
        for (const u of updates) {
          this.offset = u.update_id + 1;
          await this.telegram.handleUpdate(u);
        }
      } catch (err) {
        const e = err as TelegramApiError;
        failing = true;
        await this.beat(TelegramService.explain(err), true);
        this.logger.warn(`Telegram polling: ${e.message}`);
        // 409 — запущен webhook или второй poller; ждём дольше
        await this.sleep(e.status === 409 ? 60_000 : 10_000);
      }
    }
  }
}

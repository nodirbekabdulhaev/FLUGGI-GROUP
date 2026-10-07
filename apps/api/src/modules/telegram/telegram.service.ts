import { createHash, randomBytes } from 'node:crypto';
import { Injectable, Logger } from '@nestjs/common';
import type { TelegramLinkDto, TelegramStatusDto } from '@fluggi/contracts';
import type { AuthContext } from '../../core/auth/auth-context';
import { businessRule } from '../../core/http/app.exception';
import { PrismaService } from '../../core/prisma/prisma.service';
import { TelegramApiError, TelegramClient, type TelegramUpdate } from './telegram.client';

const LINK_TTL_MS = 15 * 60_000;
const HEALTH_KEY = 'telegram.health';
interface Health {
  at: string;
  problem: string | null;
  bot: string | null;
}
const hash = (t: string) => createHash('sha256').update(t).digest('hex');

/**
 * Привязка Telegram к аккаунту (ТЗ §53): CRM выдаёт одноразовый код,
 * сотрудник отправляет боту /start <код>, бот сохраняет telegram_chat_id.
 */
@Injectable()
export class TelegramService {
  private readonly logger = new Logger(TelegramService.name);
  /** Бот «молчит» дольше этого — значит, сообщения никто не принимает. */
  static readonly STALE_MS = 2 * 60_000;

  /** Понятная причина сбоя Telegram для CEO. */
  static explain(err: unknown): string {
    if (err instanceof TelegramApiError) {
      if (err.status === 401 || err.status === 404)
        return 'Токен бота недействителен (возможно, его заменили в @BotFather). Укажите актуальный TELEGRAM_BOT_TOKEN в .env и перезапустите API';
      if (err.status === 409)
        return 'Бот получает сообщения в другом месте: у него включён webhook или с этим токеном запущен второй сервер';
      return `Telegram ответил ошибкой: ${err.description}`;
    }
    return `Нет связи с Telegram: ${(err as Error).message}`;
  }

  /**
   * Состояние бота пишет фоновый процесс (API в разработке или worker) — в БД,
   * чтобы любой экземпляр API видел, принимает ли кто-то сообщения.
   */
  async reportHealth(h: { problem: string | null; bot: string | null }) {
    const value = { ...h, at: new Date().toISOString() };
    await this.prisma.setting.upsert({
      where: { key: HEALTH_KEY },
      update: { value },
      create: { key: HEALTH_KEY, value },
    });
  }

  private async health(): Promise<Health | null> {
    const row = await this.prisma.setting.findUnique({ where: { key: HEALTH_KEY } });
    return (row?.value as Health | undefined) ?? null;
  }

  /** Почему бот может не отвечать — показывается в профиле. */
  async currentProblem(): Promise<string | null> {
    if (!this.client.configured) return null;
    const h = await this.health();
    if (!h || Date.now() - new Date(h.at).getTime() > TelegramService.STALE_MS)
      return 'Бот не запущен: сообщения боту сейчас никто не принимает. Перезапустите API (после изменения .env это обязательно); в production — запустите worker. В логе при запуске должно быть «Telegram-бот @… подключён»';
    return h.problem;
  }

  constructor(
    private readonly prisma: PrismaService,
    private readonly client: TelegramClient,
  ) {}

  /** Имя бота: настоящее (из getMe, его сохраняет фоновый процесс), иначе из .env. */
  async username(): Promise<string | null> {
    return (await this.health())?.bot ?? this.client.username;
  }

  async status(auth: AuthContext): Promise<TelegramStatusDto> {
    const user = await this.prisma.user.findUniqueOrThrow({ where: { id: auth.userId } });
    return {
      botConfigured: this.client.configured,
      botUsername: await this.username(),
      linked: Boolean(user.telegramChatId),
      username: user.telegramUsername,
      problem: await this.currentProblem(),
    };
  }

  async createLink(auth: AuthContext): Promise<TelegramLinkDto> {
    if (!this.client.configured)
      throw businessRule(
        'Telegram-бот не настроен: CEO должен указать TELEGRAM_BOT_TOKEN на сервере',
      );
    const problem = await this.currentProblem();
    if (problem) throw businessRule(problem);
    const bot = await this.username();
    if (!bot) throw businessRule('Не удалось получить имя бота. Укажите TELEGRAM_BOT_USERNAME');
    const token = randomBytes(18).toString('base64url');
    const expiresAt = new Date(Date.now() + LINK_TTL_MS);
    await this.prisma.telegramLinkToken.create({
      data: { tokenHash: hash(token), userId: auth.userId, expiresAt },
    });
    return {
      deepLink: `https://t.me/${bot}?start=${token}`,
      command: `/start ${token}`,
      expiresAt: expiresAt.toISOString(),
    };
  }

  async unlink(auth: AuthContext) {
    await this.prisma.user.update({
      where: { id: auth.userId },
      data: { telegramChatId: null, telegramUsername: null },
    });
  }

  /** Обработка входящего сообщения боту (webhook или polling). */
  async handleUpdate(update: TelegramUpdate): Promise<void> {
    const msg = update.message;
    if (!msg?.text || msg.chat.type !== 'private') return;
    const chatId = String(msg.chat.id);
    const [command, arg] = msg.text.trim().split(/\s+/, 2);
    const reply = (text: string) =>
      this.client
        .sendMessage(chatId, text)
        .catch((err) => this.logger.warn((err as Error).message));

    if (command === '/start' && arg) {
      const row = await this.prisma.telegramLinkToken.findUnique({
        where: { tokenHash: hash(arg) },
        include: { user: true },
      });
      if (!row || row.usedAt || row.expiresAt < new Date() || row.user.status !== 'ACTIVE') {
        await reply(
          'Код недействителен или устарел. Получите новый в профиле CRM: «Подключить Telegram».',
        );
        return;
      }
      await this.prisma.$transaction([
        // Один чат — один аккаунт
        this.prisma.user.updateMany({
          where: { telegramChatId: chatId, id: { not: row.userId } },
          data: { telegramChatId: null, telegramUsername: null },
        }),
        this.prisma.user.update({
          where: { id: row.userId },
          data: { telegramChatId: chatId, telegramUsername: msg.from?.username ?? null },
        }),
        this.prisma.telegramLinkToken.update({
          where: { id: row.id },
          data: { usedAt: new Date() },
        }),
      ]);
      await reply(
        `✅ Telegram подключён к Fluggi CRM.\n<b>${TelegramClient.escape(row.user.fullName)}</b>, сюда будут приходить уведомления. Настроить их можно в профиле CRM.\n\nОтключить: /stop`,
      );
      return;
    }
    if (command === '/stop') {
      const n = await this.prisma.user.updateMany({
        where: { telegramChatId: chatId },
        data: { telegramChatId: null, telegramUsername: null },
      });
      await reply(
        n.count
          ? 'Уведомления отключены. Подключить снова можно в профиле CRM.'
          : 'Этот чат не подключён к CRM.',
      );
      return;
    }
    const linked = await this.prisma.user.findFirst({ where: { telegramChatId: chatId } });
    await reply(
      linked
        ? `Вы подключены как <b>${TelegramClient.escape(linked.fullName)}</b>. Уведомления приходят автоматически.\nОтключить: /stop`
        : 'Это бот Fluggi CRM. Чтобы подключиться, откройте в CRM «Профиль → Подключить Telegram».',
    );
  }
}

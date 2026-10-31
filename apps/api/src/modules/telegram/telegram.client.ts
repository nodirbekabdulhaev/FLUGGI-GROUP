import { Injectable, Logger } from '@nestjs/common';
import { fetch, ProxyAgent, Socks5ProxyAgent, type Dispatcher } from 'undici';
import { loadEnv } from '../../config/env';

/** Где работают фоновые циклы: в worker, а в разработке — и в API (как outbox). */
export const backgroundEnabled = () => process.env.OUTBOX_IN_API !== 'false';

export class TelegramApiError extends Error {
  constructor(
    readonly status: number,
    readonly description: string,
    readonly retryAfter?: number,
  ) {
    super(`Telegram ${status}: ${description}`);
  }

  /** Бот заблокирован пользователем или чат не найден — повторять бессмысленно. */
  get permanent() {
    return this.status === 403 || this.status === 400;
  }
}

export interface TelegramUpdate {
  update_id: number;
  message?: {
    message_id: number;
    text?: string;
    chat: { id: number; type: string };
    from?: { id: number; username?: string; first_name?: string };
  };
}

/** Тонкий клиент Telegram Bot API (без сторонних библиотек). */
@Injectable()
export class TelegramClient {
  private readonly logger = new Logger(TelegramClient.name);

  get configured(): boolean {
    return Boolean(loadEnv().TELEGRAM_BOT_TOKEN);
  }

  get username(): string | null {
    return loadEnv().TELEGRAM_BOT_USERNAME ?? null;
  }

  private proxy: { url: string; dispatcher: Dispatcher } | null = null;

  /** Прокси из TELEGRAM_PROXY_URL (создаётся один раз). */
  private dispatcher(): Dispatcher | undefined {
    const url = loadEnv().TELEGRAM_PROXY_URL;
    if (!url) return undefined;
    if (this.proxy?.url !== url)
      this.proxy = {
        url,
        dispatcher: url.startsWith('socks5://') ? new Socks5ProxyAgent(url) : new ProxyAgent(url),
      };
    return this.proxy.dispatcher;
  }

  async call<T>(method: string, body: object, timeoutMs = 15_000): Promise<T> {
    const env = loadEnv();
    if (!env.TELEGRAM_BOT_TOKEN) throw new TelegramApiError(0, 'Бот не настроен');
    const res = await fetch(`${env.TELEGRAM_API_BASE}/bot${env.TELEGRAM_BOT_TOKEN}/${method}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
      signal: AbortSignal.timeout(timeoutMs),
      dispatcher: this.dispatcher(),
    });
    const data = (await res.json().catch(() => ({}))) as {
      ok?: boolean;
      result?: T;
      description?: string;
      parameters?: { retry_after?: number };
    };
    if (!res.ok || !data.ok)
      throw new TelegramApiError(
        res.status,
        data.description ?? res.statusText,
        data.parameters?.retry_after,
      );
    return data.result as T;
  }

  sendMessage(chatId: string, html: string) {
    return this.call('sendMessage', {
      chat_id: chatId,
      text: html,
      parse_mode: 'HTML',
      disable_web_page_preview: true,
    });
  }

  getUpdates(offset: number, timeoutSec: number) {
    return this.call<TelegramUpdate[]>(
      'getUpdates',
      { offset, timeout: timeoutSec, allowed_updates: ['message'] },
      (timeoutSec + 10) * 1000,
    );
  }

  async getMe(): Promise<{ username: string }> {
    return this.call('getMe', {});
  }

  /** Экранирование для parse_mode=HTML. */
  static escape(text: string): string {
    return text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }
}

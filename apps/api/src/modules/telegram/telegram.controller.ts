import { timingSafeEqual } from 'node:crypto';
import { Body, Controller, Delete, Get, Headers, HttpCode, Post } from '@nestjs/common';
import type { TelegramLinkDto, TelegramStatusDto } from '@fluggi/contracts';
import { loadEnv } from '../../config/env';
import type { AuthContext } from '../../core/auth/auth-context';
import { AuthenticatedOnly, CurrentUser, Public } from '../../core/auth/decorators';
import { AppException, businessRule } from '../../core/http/app.exception';
import { NotificationsService } from '../notifications/notifications.service';
import type { TelegramUpdate } from './telegram.client';
import { TelegramService } from './telegram.service';

@Controller()
export class TelegramController {
  constructor(
    private readonly telegram: TelegramService,
    private readonly notifications: NotificationsService,
  ) {}

  @Get('me/telegram')
  @AuthenticatedOnly()
  status(@CurrentUser() auth: AuthContext): Promise<TelegramStatusDto> {
    return this.telegram.status(auth);
  }

  @Post('me/telegram/link')
  @HttpCode(200)
  @AuthenticatedOnly()
  link(@CurrentUser() auth: AuthContext): Promise<TelegramLinkDto> {
    return this.telegram.createLink(auth);
  }

  @Delete('me/telegram')
  @HttpCode(204)
  @AuthenticatedOnly()
  unlink(@CurrentUser() auth: AuthContext): Promise<void> {
    return this.telegram.unlink(auth);
  }

  /** Тестовое сообщение себе — проверить, что уведомления доходят. */
  @Post('me/telegram/test')
  @HttpCode(200)
  @AuthenticatedOnly()
  async test(@CurrentUser() auth: AuthContext) {
    const s = await this.telegram.status(auth);
    if (!s.linked) throw businessRule('Сначала подключите Telegram');
    await this.notifications.notify(
      [auth.userId],
      {
        type: 'test',
        title: 'Проверка связи',
        body: 'Уведомления Fluggi CRM приходят в Telegram ✅',
        link: '/profile',
      },
      null,
      { inApp: false },
    );
    return { queued: true };
  }

  /** Webhook Telegram (режим webhook). Проверяется секретный заголовок. */
  @Post('telegram/webhook')
  @HttpCode(200)
  @Public()
  async webhook(
    @Headers('x-telegram-bot-api-secret-token') secret: string | undefined,
    @Body() update: TelegramUpdate,
  ) {
    const expected = loadEnv().TELEGRAM_WEBHOOK_SECRET;
    const ok =
      Boolean(expected && secret) &&
      Buffer.from(secret!).length === Buffer.from(expected!).length &&
      timingSafeEqual(Buffer.from(secret!), Buffer.from(expected!));
    if (!ok) throw new AppException('FORBIDDEN', 'Недопустимый запрос');
    await this.telegram.handleUpdate(update);
    return { ok: true };
  }
}

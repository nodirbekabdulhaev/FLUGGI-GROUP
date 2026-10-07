import { Module } from '@nestjs/common';
import { NotificationsModule } from '../notifications/notifications.module';
import { TelegramClient } from './telegram.client';
import { TelegramController } from './telegram.controller';
import { TelegramRunner, TelegramSender } from './telegram.runners';
import { TelegramService } from './telegram.service';

@Module({
  imports: [NotificationsModule],
  controllers: [TelegramController],
  providers: [TelegramClient, TelegramService, TelegramSender, TelegramRunner],
  exports: [TelegramClient, TelegramSender, TelegramService],
})
export class TelegramModule {}

/** Для worker: отправка и polling без HTTP-контроллера. */
@Module({
  providers: [TelegramClient, TelegramService, TelegramSender, TelegramRunner],
  exports: [TelegramSender],
})
export class TelegramWorkerModule {}

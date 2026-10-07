/**
 * Worker-процесс: доставка доменных событий из outbox подписчикам (уведомления, автоматизация),
 * отправка сообщений в Telegram и планировщик задач (отчёты, напоминания, просрочки).
 * В production API запускается с OUTBOX_IN_API=false, и фоновой работой занимается только worker.
 */
import 'reflect-metadata';
import { Logger, Module } from '@nestjs/common';
import { NestFactory } from '@nestjs/core';
import { AuditModule } from './core/audit/audit.module';
import { OutboxModule } from './core/outbox/outbox.module';
import { PrismaModule } from './core/prisma/prisma.module';
import { SettingsModule } from './core/settings/settings.service';
import { AutomationModule } from './modules/automation/automation.module';
import { TelegramWorkerModule } from './modules/telegram/telegram.module';

// Worker обрабатывает outbox всегда, независимо от настройки API.
process.env.OUTBOX_IN_API = 'true';

@Module({
  imports: [
    PrismaModule,
    AuditModule,
    OutboxModule,
    SettingsModule,
    TelegramWorkerModule,
    AutomationModule,
  ],
})
class WorkerModule {}
// Подписчики уведомлений приходят через AutomationModule → NotificationsModule (одним экземпляром).

async function bootstrap() {
  const app = await NestFactory.createApplicationContext(WorkerModule);
  app.enableShutdownHooks();
  new Logger('Worker').log('Worker started');
}

void bootstrap();

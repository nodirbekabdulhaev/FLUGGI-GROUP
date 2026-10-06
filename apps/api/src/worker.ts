/**
 * Worker-процесс: доставка доменных событий из outbox подписчикам
 * (уведомления; Telegram, напоминания и cron — в следующих фазах).
 * В production API запускается с OUTBOX_IN_API=false, и событиями занимается только worker.
 */
import 'reflect-metadata';
import { Logger, Module } from '@nestjs/common';
import { NestFactory } from '@nestjs/core';
import { OutboxModule } from './core/outbox/outbox.module';
import { PrismaModule } from './core/prisma/prisma.module';
import { NotificationEventsModule } from './modules/notifications/notifications.module';

// Worker обрабатывает outbox всегда, независимо от настройки API.
process.env.OUTBOX_IN_API = 'true';

@Module({ imports: [PrismaModule, OutboxModule, NotificationEventsModule] })
class WorkerModule {}

async function bootstrap() {
  const app = await NestFactory.createApplicationContext(WorkerModule);
  app.enableShutdownHooks();
  new Logger('Worker').log('Worker started');
}

void bootstrap();

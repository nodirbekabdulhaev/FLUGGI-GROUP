/**
 * Worker-процесс: доставка доменных событий из outbox.
 * В следующих фазах здесь же — cron-задачи (напоминания, отчёты) и Telegram.
 */
import 'reflect-metadata';
import { Logger, Module } from '@nestjs/common';
import { NestFactory } from '@nestjs/core';
import { OutboxDispatcher } from './core/outbox/outbox.dispatcher';
import { OutboxModule } from './core/outbox/outbox.module';
import { PrismaModule } from './core/prisma/prisma.module';

const POLL_INTERVAL_MS = 2000;

@Module({ imports: [PrismaModule, OutboxModule] })
class WorkerModule {}

async function bootstrap() {
  const app = await NestFactory.createApplicationContext(WorkerModule);
  app.enableShutdownHooks();
  const logger = new Logger('Worker');
  const dispatcher = app.get(OutboxDispatcher);

  // Подписчики событий регистрируются здесь по мере появления модулей
  // (уведомления — Phase 2, Telegram — Phase 7).
  dispatcher.on('user.created', async (payload, meta) => {
    logger.log(`user.created ${payload.userId} (${payload.roleCode}) by ${meta.actorId ?? 'system'}`);
  });

  let stopping = false;
  process.on('SIGTERM', () => (stopping = true));
  process.on('SIGINT', () => (stopping = true));

  logger.log('Worker started');
  while (!stopping) {
    try {
      const processed = await dispatcher.processBatch();
      if (processed === 0) await new Promise((r) => setTimeout(r, POLL_INTERVAL_MS));
    } catch (err) {
      logger.error(err, 'Outbox batch failed');
      await new Promise((r) => setTimeout(r, POLL_INTERVAL_MS * 5));
    }
  }
  await app.close();
}

void bootstrap();

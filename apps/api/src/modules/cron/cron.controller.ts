import { timingSafeEqual } from 'node:crypto';
import {
  Controller,
  Headers,
  HttpCode,
  Logger,
  Module,
  NotFoundException,
  Post,
} from '@nestjs/common';
import { SkipThrottle } from '@nestjs/throttler';
import { loadEnv } from '../../config/env';
import { Public } from '../../core/auth/decorators';
import { OutboxDispatcher } from '../../core/outbox/outbox.dispatcher';
import { OutboxModule } from '../../core/outbox/outbox.module';
import { AutomationModule } from '../automation/automation.module';
import { SchedulerService } from '../automation/scheduler.service';
import { TelegramModule } from '../telegram/telegram.module';
import { TelegramRunner, TelegramSender } from '../telegram/telegram.runners';

/** Сколько секунд один вызов может разбирать очередь (cron вызывает раз в минуту). */
const BUDGET_MS = 40_000;

function secretMatches(expected: string, given: string | undefined) {
  if (!given) return false;
  const a = Buffer.from(expected);
  const b = Buffer.from(given);
  return a.length === b.length && timingSafeEqual(a, b);
}

/**
 * Фоновые задачи по внешнему cron — для виртуального хостинга, где нет постоянного
 * worker-процесса (Passenger останавливает приложение без запросов). Раз в минуту:
 * доставка событий outbox, отправка в Telegram, задачи планировщика.
 * Повторные и параллельные вызовы безопасны: строки блокируются FOR UPDATE SKIP LOCKED,
 * а каждая задача планировщика выполняется один раз за свой слот (таблица job_runs).
 */
@Controller('internal/cron')
export class CronController {
  private readonly logger = new Logger(CronController.name);

  constructor(
    private readonly outbox: OutboxDispatcher,
    private readonly sender: TelegramSender,
    private readonly telegram: TelegramRunner,
    private readonly scheduler: SchedulerService,
  ) {}

  @Post()
  @Public()
  @SkipThrottle()
  @HttpCode(200)
  async run(@Headers('x-cron-secret') secret: string | undefined) {
    const env = loadEnv();
    // Без секрета эндпоинт не существует — его нельзя дёргать извне
    if (!env.CRON_SECRET || !secretMatches(env.CRON_SECRET, secret)) throw new NotFoundException();

    const started = Date.now();
    let events = 0;
    let telegram = 0;
    for (let n = 1; n > 0 && Date.now() - started < BUDGET_MS;) {
      n = await this.outbox.processBatch();
      events += n;
    }
    if (env.TELEGRAM_BOT_TOKEN) {
      for (let n = 1; n > 0 && Date.now() - started < BUDGET_MS;) {
        n = await this.sender.processBatch();
        telegram += n;
      }
      await this.telegram.check();
    }
    if (env.SCHEDULER_ENABLED) await this.scheduler.tick();
    const result = { events, telegram, ms: Date.now() - started };
    if (events || telegram) this.logger.log(`Cron: событий ${events}, Telegram ${telegram}`);
    return result;
  }
}

@Module({
  imports: [OutboxModule, TelegramModule, AutomationModule],
  controllers: [CronController],
})
export class CronModule {}

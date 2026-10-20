import { Injectable, Logger, OnApplicationBootstrap, OnApplicationShutdown } from '@nestjs/common';
import { OutboxDispatcher } from './outbox.dispatcher';

const POLL_MS = 2000;

/**
 * Обработка outbox внутри API-процесса — чтобы при разработке не нужно было
 * запускать отдельный worker. В production включён отдельный worker, а здесь
 * цикл выключается переменной OUTBOX_IN_API=false. Оба варианта безопасны вместе
 * (строки блокируются FOR UPDATE SKIP LOCKED).
 */
@Injectable()
export class OutboxRunner implements OnApplicationBootstrap, OnApplicationShutdown {
  private readonly logger = new Logger(OutboxRunner.name);
  private stopped = false;
  private timer: NodeJS.Timeout | undefined;

  constructor(private readonly dispatcher: OutboxDispatcher) {}

  onApplicationBootstrap() {
    if (process.env.OUTBOX_IN_API === 'false') return;
    this.schedule(POLL_MS);
  }

  onApplicationShutdown() {
    this.stopped = true;
    if (this.timer) clearTimeout(this.timer);
  }

  private schedule(ms: number) {
    if (this.stopped) return;
    this.timer = setTimeout(() => void this.tick(), ms);
  }

  private async tick() {
    try {
      const n = await this.dispatcher.processBatch();
      this.schedule(n > 0 ? 0 : POLL_MS);
    } catch (err) {
      this.logger.error(err, 'Outbox batch failed');
      this.schedule(POLL_MS * 5);
    }
  }
}

import { Global, Module } from '@nestjs/common';
import { OutboxDispatcher } from './outbox.dispatcher';
import { OutboxRunner } from './outbox.runner';
import { OutboxService } from './outbox.service';

@Global()
@Module({
  providers: [OutboxService, OutboxDispatcher, OutboxRunner],
  exports: [OutboxService, OutboxDispatcher],
})
export class OutboxModule {}

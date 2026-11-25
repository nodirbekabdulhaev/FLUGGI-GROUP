import { Module } from '@nestjs/common';
import { NotificationsModule } from '../notifications/notifications.module';
import { RecurringTodosService } from './recurring.service';
import { TodosController } from './todos.controller';
import { TodosService } from './todos.service';

@Module({
  imports: [NotificationsModule],
  controllers: [TodosController],
  providers: [TodosService, RecurringTodosService],
  exports: [RecurringTodosService],
})
export class TodosModule {}

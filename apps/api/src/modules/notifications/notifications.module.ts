import { Module } from '@nestjs/common';
import { NotificationEvents } from './notification-events';
import { NotificationsController } from './notifications.controller';
import { NotificationsService } from './notifications.service';

/** Контроллер + подписчики событий. Подключается и в API, и в worker. */
@Module({
  controllers: [NotificationsController],
  providers: [NotificationsService, NotificationEvents],
  exports: [NotificationsService],
})
export class NotificationsModule {}

/** Только подписчики (для worker-процесса без HTTP). */
@Module({ providers: [NotificationsService, NotificationEvents] })
export class NotificationEventsModule {}

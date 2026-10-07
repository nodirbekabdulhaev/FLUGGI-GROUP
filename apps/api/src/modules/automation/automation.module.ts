import { Module } from '@nestjs/common';
import { DealsModule } from '../deals/deals.module';
import { NotificationsModule } from '../notifications/notifications.module';
import { PeopleModule } from '../people/people.module';
import { OverdueModule } from '../projects/overdue.module';
import { AutomationController } from './automation.controller';
import { AutomationEvents } from './automation.events';
import { FollowUpsService } from './follow-ups.service';
import { RemindersService } from './reminders.service';
import { ReportsService } from './reports.service';
import { SchedulerService } from './scheduler.service';

const providers = [
  FollowUpsService,
  RemindersService,
  ReportsService,
  SchedulerService,
  AutomationEvents,
];

@Module({
  imports: [DealsModule, NotificationsModule, PeopleModule, OverdueModule],
  controllers: [AutomationController],
  providers,
  exports: [SchedulerService, RemindersService, ReportsService],
})
export class AutomationModule {}

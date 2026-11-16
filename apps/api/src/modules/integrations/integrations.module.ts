import { Module } from '@nestjs/common';
import { LeadsModule } from '../leads/leads.module';
import { NotificationsModule } from '../notifications/notifications.module';
import { FormsService } from './forms.service';
import { InboxService } from './inbox.service';
import { IntakeService } from './intake.service';
import {
  InboxController,
  IntegrationsController,
  PublicIntakeController,
} from './integrations.controller';
import { MetaService } from './meta.service';

@Module({
  imports: [LeadsModule, NotificationsModule],
  controllers: [IntegrationsController, InboxController, PublicIntakeController],
  providers: [IntakeService, FormsService, MetaService, InboxService],
})
export class IntegrationsModule {}

import { Global, Module } from '@nestjs/common';
import { ActivityService } from './activity.service';
import { CrmAccessService } from './crm-access.service';
import { TimelineController } from './timeline.controller';

@Global()
@Module({
  controllers: [TimelineController],
  providers: [CrmAccessService, ActivityService],
  exports: [CrmAccessService, ActivityService],
})
export class CrmModule {}

import { Global, Module } from '@nestjs/common';
import { CommissionEngine } from './commission-engine.service';
import { CommissionsController } from './commissions.controller';
import { CommissionsService } from './commissions.service';

@Global()
@Module({
  controllers: [CommissionsController],
  providers: [CommissionsService, CommissionEngine],
  exports: [CommissionEngine],
})
export class CommissionsModule {}

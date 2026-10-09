import { Module } from '@nestjs/common';
import { DealsModule } from '../deals/deals.module';
import { ProjectsModule } from '../projects/projects.module';
import { PaymentsController } from './payments.controller';
import { PaymentsService } from './payments.service';

@Module({
  imports: [DealsModule, ProjectsModule],
  controllers: [PaymentsController],
  providers: [PaymentsService],
})
export class PaymentsModule {}

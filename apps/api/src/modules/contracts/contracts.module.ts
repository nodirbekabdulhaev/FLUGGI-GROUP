import { Module } from '@nestjs/common';
import { DealsModule } from '../deals/deals.module';
import { ContractsController } from './contracts.controller';
import { ContractsService } from './contracts.service';

@Module({
  imports: [DealsModule],
  controllers: [ContractsController],
  providers: [ContractsService],
})
export class ContractsModule {}

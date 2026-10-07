import { Module } from '@nestjs/common';
import { FinanceModule } from '../finance/finance.module';
import { DashboardService } from './dashboard.service';
import { KpiService } from './kpi.service';
import { PeopleController } from './people.controller';
import { PeopleService } from './people.service';

@Module({
  imports: [FinanceModule],
  controllers: [PeopleController],
  providers: [KpiService, PeopleService, DashboardService],
})
export class PeopleModule {}

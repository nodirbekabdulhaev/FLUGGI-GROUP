import { Module } from '@nestjs/common';
import { ProjectsModule } from '../projects/projects.module';
import { ExpensesService } from './expenses.service';
import { FinanceController } from './finance.controller';
import { FinanceService } from './finance.service';
import { CostLinesService } from './cost-lines.service';
import { OverheadService } from './overhead.service';

@Module({
  imports: [ProjectsModule],
  controllers: [FinanceController],
  providers: [ExpensesService, FinanceService, OverheadService, CostLinesService],
  exports: [FinanceService, ExpensesService, OverheadService, CostLinesService],
})
export class FinanceModule {}

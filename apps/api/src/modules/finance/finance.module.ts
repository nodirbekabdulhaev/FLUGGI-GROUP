import { Module } from '@nestjs/common';
import { ProjectsModule } from '../projects/projects.module';
import { ExpensesService } from './expenses.service';
import { FinanceController } from './finance.controller';
import { FinanceService } from './finance.service';

@Module({
  imports: [ProjectsModule],
  controllers: [FinanceController],
  providers: [ExpensesService, FinanceService],
  exports: [FinanceService, ExpensesService],
})
export class FinanceModule {}

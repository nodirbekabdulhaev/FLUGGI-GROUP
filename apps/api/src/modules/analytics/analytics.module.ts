import { Module } from '@nestjs/common';
import { FinanceModule } from '../finance/finance.module';
import { ProjectsModule } from '../projects/projects.module';
import { AnalyticsController } from './analytics.controller';
import { AnalyticsService } from './analytics.service';
import { ClientInsightsService } from './client-insights.service';
import { ExportService } from './export.service';
import { SearchService } from './search.service';

@Module({
  imports: [ProjectsModule, FinanceModule],
  controllers: [AnalyticsController],
  providers: [AnalyticsService, ClientInsightsService, SearchService, ExportService],
  exports: [ClientInsightsService],
})
export class AnalyticsModule {}

/** Для worker и планировщика: пересчёт здоровья клиентов без HTTP-контроллера. */
@Module({ providers: [ClientInsightsService], exports: [ClientInsightsService] })
export class ClientInsightsModule {}

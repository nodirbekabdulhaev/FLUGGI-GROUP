import { Controller, Get, Param, Query, Res, StreamableFile } from '@nestjs/common';
import {
  analyticsQuerySchema,
  clientAnalyticsQuerySchema,
  EXPORT_ENTITIES,
  exportQuerySchema,
  searchQuerySchema,
  type BreakdownRowDto,
  type ClientAnalyticsDto,
  type ClientInsightDto,
  type ExportEntity,
  type ForecastDto,
  type FunnelStepDto,
  type LossReasonRowDto,
  type SalesAnalyticsDto,
  type SearchHitDto,
} from '@fluggi/contracts';
import type { Response } from 'express';
import { z } from 'zod';
import type { AuthContext, RequestMeta } from '../../core/auth/auth-context';
import {
  AuthenticatedOnly,
  CurrentUser,
  ReqMeta,
  RequirePermission,
} from '../../core/auth/decorators';
import { UuidPipe } from '../../core/http/uuid.pipe';
import { zod } from '../../core/http/zod.pipe';
import { AnalyticsService } from './analytics.service';
import { ClientInsightsService } from './client-insights.service';
import { ExportService } from './export.service';
import { SearchService } from './search.service';

type AQ = z.output<typeof analyticsQuerySchema>;
const entityPipe = zod(z.enum(EXPORT_ENTITIES));

/** Аналитика, поиск и экспорт (ТЗ §39–44). */
@Controller()
export class AnalyticsController {
  constructor(
    private readonly analytics: AnalyticsService,
    private readonly clients: ClientInsightsService,
    private readonly searchService: SearchService,
    private readonly exports: ExportService,
  ) {}

  @Get('analytics/sales')
  @RequirePermission('analytics.read')
  sales(
    @CurrentUser() auth: AuthContext,
    @Query(zod(analyticsQuerySchema)) q: AQ,
  ): Promise<SalesAnalyticsDto> {
    return this.analytics.sales(auth, q);
  }

  @Get('analytics/funnel')
  @RequirePermission('analytics.read')
  funnel(
    @CurrentUser() auth: AuthContext,
    @Query(zod(analyticsQuerySchema)) q: AQ,
  ): Promise<FunnelStepDto[]> {
    return this.analytics.funnel(auth, q);
  }

  @Get('analytics/sources')
  @RequirePermission('analytics.read')
  sources(
    @CurrentUser() auth: AuthContext,
    @Query(zod(analyticsQuerySchema)) q: AQ,
  ): Promise<BreakdownRowDto[]> {
    return this.analytics.breakdown(auth, q, 'source');
  }

  @Get('analytics/services')
  @RequirePermission('analytics.read')
  services(
    @CurrentUser() auth: AuthContext,
    @Query(zod(analyticsQuerySchema)) q: AQ,
  ): Promise<BreakdownRowDto[]> {
    return this.analytics.breakdown(auth, q, 'service');
  }

  @Get('analytics/losses')
  @RequirePermission('analytics.read')
  losses(
    @CurrentUser() auth: AuthContext,
    @Query(zod(analyticsQuerySchema)) q: AQ,
  ): Promise<LossReasonRowDto[]> {
    return this.analytics.losses(auth, q);
  }

  @Get('analytics/forecast')
  @RequirePermission('analytics.read')
  forecast(
    @CurrentUser() auth: AuthContext,
    @Query(zod(analyticsQuerySchema)) q: AQ,
  ): Promise<ForecastDto> {
    return this.analytics.forecast(auth, q);
  }

  @Get('analytics/clients')
  @RequirePermission('analytics.read')
  clientList(
    @CurrentUser() auth: AuthContext,
    @Query(zod(clientAnalyticsQuerySchema)) q: z.output<typeof clientAnalyticsQuerySchema>,
  ): Promise<ClientAnalyticsDto> {
    return this.clients.list(auth, q);
  }

  /** LTV и здоровье одного клиента — для карточки клиента. */
  @Get('clients/:id/insight')
  @RequirePermission('client.read')
  insight(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
  ): Promise<Omit<ClientInsightDto, 'name' | 'owner'>> {
    return this.clients.one(auth, id);
  }

  @Get('search')
  @AuthenticatedOnly()
  search(
    @CurrentUser() auth: AuthContext,
    @Query(zod(searchQuerySchema)) q: z.output<typeof searchQuerySchema>,
  ): Promise<SearchHitDto[]> {
    return this.searchService.search(auth, q.q);
  }

  @Get('exports/:entity')
  @RequirePermission('export.run')
  async export(
    @CurrentUser() auth: AuthContext,
    @Param('entity', entityPipe) entity: ExportEntity,
    @Query(zod(exportQuerySchema)) q: z.output<typeof exportQuerySchema>,
    @ReqMeta() meta: RequestMeta,
    @Res({ passthrough: true }) res: Response,
  ): Promise<StreamableFile> {
    const f = await this.exports.file(auth, entity, q, meta);
    res.setHeader('Content-Type', f.contentType);
    res.setHeader('Content-Disposition', `attachment; filename="${f.filename}"`);
    res.setHeader('X-Export-Rows', String(f.rows));
    res.setHeader('Cache-Control', 'no-store');
    return new StreamableFile(f.buffer);
  }
}

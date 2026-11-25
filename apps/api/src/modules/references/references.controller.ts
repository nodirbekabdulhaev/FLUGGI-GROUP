import { Body, Controller, Get, Param, Patch, Post, Put } from '@nestjs/common';
import {
  setExchangeRateSchema,
  updateStageSchema,
  upsertReferenceItemSchema,
  upsertServiceSchema,
  type ExchangeRateDto,
  type ReferenceItemDto,
  type ReferencesDto,
  type ServiceDto,
  type StageDto,
} from '@fluggi/contracts';
import type { z } from 'zod';
import type { AuthContext, RequestMeta } from '../../core/auth/auth-context';
import {
  AuthenticatedOnly,
  CurrentUser,
  ReqMeta,
  RequirePermission,
} from '../../core/auth/decorators';
import { notFound } from '../../core/http/app.exception';
import { UuidPipe } from '../../core/http/uuid.pipe';
import { zod } from '../../core/http/zod.pipe';
import { ReferencesService } from './references.service';

@Controller('references')
export class ReferencesController {
  constructor(private readonly refs: ReferencesService) {}

  /** Справочники нужны формам всех ролей. Финансовых данных здесь нет. */
  @Get()
  @AuthenticatedOnly()
  all(): Promise<ReferencesDto> {
    return this.refs.all();
  }

  @Post('services')
  @RequirePermission('reference.manage', 'ALL')
  createService(
    @CurrentUser() auth: AuthContext,
    @Body(zod(upsertServiceSchema)) body: z.output<typeof upsertServiceSchema>,
    @ReqMeta() meta: RequestMeta,
  ): Promise<ServiceDto> {
    return this.refs.upsertService(auth, null, body, meta);
  }

  @Put('services/:id')
  @RequirePermission('reference.manage', 'ALL')
  updateService(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @Body(zod(upsertServiceSchema)) body: z.output<typeof upsertServiceSchema>,
    @ReqMeta() meta: RequestMeta,
  ): Promise<ServiceDto> {
    return this.refs.upsertService(auth, id, body, meta);
  }

  @Post(':kind')
  @RequirePermission('reference.manage', 'ALL')
  createItem(
    @CurrentUser() auth: AuthContext,
    @Param('kind') kind: string,
    @Body(zod(upsertReferenceItemSchema)) body: z.output<typeof upsertReferenceItemSchema>,
    @ReqMeta() meta: RequestMeta,
  ): Promise<ReferenceItemDto> {
    return this.refs.upsertItem(auth, this.kind(kind), null, body, meta);
  }

  @Put(':kind/:id')
  @RequirePermission('reference.manage', 'ALL')
  updateItem(
    @CurrentUser() auth: AuthContext,
    @Param('kind') kind: string,
    @Param('id', UuidPipe) id: string,
    @Body(zod(upsertReferenceItemSchema)) body: z.output<typeof upsertReferenceItemSchema>,
    @ReqMeta() meta: RequestMeta,
  ): Promise<ReferenceItemDto> {
    return this.refs.upsertItem(auth, this.kind(kind), id, body, meta);
  }

  @Patch('stages/:id')
  @RequirePermission('reference.manage', 'ALL')
  updateStage(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @Body(zod(updateStageSchema)) body: z.output<typeof updateStageSchema>,
    @ReqMeta() meta: RequestMeta,
  ): Promise<StageDto> {
    return this.refs.updateStage(auth, id, body, meta);
  }

  /** Курс валюты влияет на финансы — только CEO (finance.company.read). */
  @Put('exchange-rates')
  @RequirePermission('finance.company.read', 'ALL')
  setRate(
    @CurrentUser() auth: AuthContext,
    @Body(zod(setExchangeRateSchema)) body: z.output<typeof setExchangeRateSchema>,
    @ReqMeta() meta: RequestMeta,
  ): Promise<ExchangeRateDto> {
    return this.refs.setRate(auth, body, meta);
  }

  private kind(kind: string): 'sources' | 'loss-reasons' {
    if (kind === 'sources' || kind === 'loss-reasons') return kind;
    throw notFound('Справочник');
  }
}

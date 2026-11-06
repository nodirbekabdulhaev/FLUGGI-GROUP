import { Body, Controller, Delete, Get, HttpCode, Param, Patch, Post, Query } from '@nestjs/common';
import {
  assignSchema,
  changeDealStageSchema,
  closeSchema,
  createDealSchema,
  dealListQuerySchema,
  updateDealSchema,
  type DealDto,
  type Paginated,
} from '@fluggi/contracts';
import type { z } from 'zod';
import type { AuthContext, RequestMeta } from '../../core/auth/auth-context';
import { CurrentUser, ReqMeta, RequirePermission } from '../../core/auth/decorators';
import { UuidPipe } from '../../core/http/uuid.pipe';
import { zod } from '../../core/http/zod.pipe';
import { DealsService } from './deals.service';

@Controller('deals')
export class DealsController {
  constructor(private readonly deals: DealsService) {}

  @Get()
  @RequirePermission('deal.read')
  list(
    @CurrentUser() auth: AuthContext,
    @Query(zod(dealListQuerySchema)) q: z.output<typeof dealListQuerySchema>,
  ): Promise<Paginated<DealDto>> {
    return this.deals.list(auth, q);
  }

  @Get(':id')
  @RequirePermission('deal.read')
  get(@CurrentUser() auth: AuthContext, @Param('id', UuidPipe) id: string): Promise<DealDto> {
    return this.deals.get(auth, id);
  }

  @Post()
  @RequirePermission('deal.create')
  create(
    @CurrentUser() auth: AuthContext,
    @Body(zod(createDealSchema)) body: z.output<typeof createDealSchema>,
    @ReqMeta() meta: RequestMeta,
  ): Promise<DealDto> {
    return this.deals.create(auth, body, meta);
  }

  @Patch(':id')
  @RequirePermission('deal.update')
  update(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @Body(zod(updateDealSchema)) body: z.output<typeof updateDealSchema>,
    @ReqMeta() meta: RequestMeta,
  ): Promise<DealDto> {
    return this.deals.update(auth, id, body, meta);
  }

  @Post(':id/stage')
  @HttpCode(200)
  @RequirePermission('deal.change_stage')
  stage(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @Body(zod(changeDealStageSchema)) body: z.output<typeof changeDealStageSchema>,
    @ReqMeta() meta: RequestMeta,
  ): Promise<DealDto> {
    return this.deals.changeStage(auth, id, body.stageCode, meta);
  }

  @Post(':id/assign')
  @HttpCode(200)
  @RequirePermission('lead.assign')
  assign(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @Body(zod(assignSchema)) body: z.output<typeof assignSchema>,
    @ReqMeta() meta: RequestMeta,
  ): Promise<DealDto> {
    return this.deals.assign(auth, id, body.ownerId, meta);
  }

  @Post(':id/close')
  @HttpCode(200)
  @RequirePermission('deal.update')
  close(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @Body(zod(closeSchema)) body: z.output<typeof closeSchema>,
    @ReqMeta() meta: RequestMeta,
  ): Promise<DealDto> {
    return this.deals.close(auth, id, body, meta);
  }

  @Post(':id/reopen')
  @HttpCode(200)
  @RequirePermission('deal.update')
  reopen(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @ReqMeta() meta: RequestMeta,
  ): Promise<DealDto> {
    return this.deals.reopen(auth, id, meta);
  }

  @Delete(':id')
  @HttpCode(204)
  @RequirePermission('deal.delete')
  remove(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @ReqMeta() meta: RequestMeta,
  ): Promise<void> {
    return this.deals.remove(auth, id, meta);
  }
}

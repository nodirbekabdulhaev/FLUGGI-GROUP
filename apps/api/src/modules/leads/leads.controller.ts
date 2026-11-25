import { Body, Controller, Delete, Get, HttpCode, Param, Patch, Post, Query } from '@nestjs/common';
import {
  assignSchema,
  changeLeadStageSchema,
  closeSchema,
  convertLeadSchema,
  createLeadSchema,
  leadListQuerySchema,
  updateLeadSchema,
  type LeadDto,
  type Paginated,
} from '@fluggi/contracts';
import type { z } from 'zod';
import type { AuthContext, RequestMeta } from '../../core/auth/auth-context';
import { CurrentUser, ReqMeta, RequirePermission } from '../../core/auth/decorators';
import { UuidPipe } from '../../core/http/uuid.pipe';
import { zod } from '../../core/http/zod.pipe';
import { LeadsService } from './leads.service';

@Controller('leads')
export class LeadsController {
  constructor(private readonly leads: LeadsService) {}

  @Get()
  @RequirePermission('lead.read')
  list(
    @CurrentUser() auth: AuthContext,
    @Query(zod(leadListQuerySchema)) q: z.output<typeof leadListQuerySchema>,
  ): Promise<Paginated<LeadDto>> {
    return this.leads.list(auth, q);
  }

  @Get(':id')
  @RequirePermission('lead.read')
  get(@CurrentUser() auth: AuthContext, @Param('id', UuidPipe) id: string): Promise<LeadDto> {
    return this.leads.get(auth, id);
  }

  @Post()
  @RequirePermission('lead.create')
  create(
    @CurrentUser() auth: AuthContext,
    @Body(zod(createLeadSchema)) body: z.output<typeof createLeadSchema>,
    @ReqMeta() meta: RequestMeta,
  ): Promise<LeadDto> {
    return this.leads.create(auth, body, meta);
  }

  @Patch(':id')
  @RequirePermission('lead.update')
  update(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @Body(zod(updateLeadSchema)) body: z.output<typeof updateLeadSchema>,
    @ReqMeta() meta: RequestMeta,
  ): Promise<LeadDto> {
    return this.leads.update(auth, id, body, meta);
  }

  @Post(':id/stage')
  @HttpCode(200)
  @RequirePermission('lead.update')
  stage(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @Body(zod(changeLeadStageSchema)) body: z.output<typeof changeLeadStageSchema>,
    @ReqMeta() meta: RequestMeta,
  ): Promise<LeadDto> {
    return this.leads.changeStage(auth, id, body.stageCode, meta);
  }

  @Post(':id/assign')
  @HttpCode(200)
  @RequirePermission('lead.assign')
  assign(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @Body(zod(assignSchema)) body: z.output<typeof assignSchema>,
    @ReqMeta() meta: RequestMeta,
  ): Promise<LeadDto> {
    return this.leads.assign(auth, id, body.ownerId, meta);
  }

  @Post(':id/close')
  @HttpCode(200)
  @RequirePermission('lead.update')
  close(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @Body(zod(closeSchema)) body: z.output<typeof closeSchema>,
    @ReqMeta() meta: RequestMeta,
  ): Promise<LeadDto> {
    return this.leads.close(auth, id, body, meta);
  }

  @Post(':id/reopen')
  @HttpCode(200)
  @RequirePermission('lead.update')
  reopen(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @ReqMeta() meta: RequestMeta,
  ): Promise<LeadDto> {
    return this.leads.reopen(auth, id, meta);
  }

  @Post(':id/convert')
  @HttpCode(200)
  @RequirePermission('deal.create')
  convert(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @Body(zod(convertLeadSchema)) body: z.output<typeof convertLeadSchema>,
    @ReqMeta() meta: RequestMeta,
  ) {
    return this.leads.convert(auth, id, body, meta);
  }

  @Delete(':id')
  @HttpCode(204)
  @RequirePermission('lead.delete')
  remove(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @ReqMeta() meta: RequestMeta,
  ): Promise<void> {
    return this.leads.remove(auth, id, meta);
  }
}

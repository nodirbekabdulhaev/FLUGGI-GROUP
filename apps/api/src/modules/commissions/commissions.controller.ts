import { Body, Controller, Get, HttpCode, Param, Post, Put, Query } from '@nestjs/common';
import {
  commissionIdsSchema,
  commissionListQuerySchema,
  upsertCommissionRuleSchema,
  type CommissionRuleDto,
} from '@fluggi/contracts';
import type { z } from 'zod';
import type { AuthContext, RequestMeta } from '../../core/auth/auth-context';
import { CurrentUser, ReqMeta, RequirePermission } from '../../core/auth/decorators';
import { UuidPipe } from '../../core/http/uuid.pipe';
import { zod } from '../../core/http/zod.pipe';
import { CommissionsService } from './commissions.service';

@Controller()
export class CommissionsController {
  constructor(private readonly commissions: CommissionsService) {}

  @Get('commissions')
  @RequirePermission('commission.read')
  list(
    @CurrentUser() auth: AuthContext,
    @Query(zod(commissionListQuerySchema)) q: z.output<typeof commissionListQuerySchema>,
  ) {
    return this.commissions.list(auth, q);
  }

  @Post('commissions/approve')
  @HttpCode(200)
  @RequirePermission('commission.approve', 'ALL')
  approve(
    @CurrentUser() auth: AuthContext,
    @Body(zod(commissionIdsSchema)) body: z.output<typeof commissionIdsSchema>,
    @ReqMeta() meta: RequestMeta,
  ) {
    return this.commissions.transition(auth, body.ids, 'APPROVED', meta);
  }

  @Post('commissions/pay')
  @HttpCode(200)
  @RequirePermission('commission.approve', 'ALL')
  pay(
    @CurrentUser() auth: AuthContext,
    @Body(zod(commissionIdsSchema)) body: z.output<typeof commissionIdsSchema>,
    @ReqMeta() meta: RequestMeta,
  ) {
    return this.commissions.transition(auth, body.ids, 'PAID', meta);
  }

  @Get('commission-rules')
  @RequirePermission('commission_rule.manage', 'ALL')
  rules(): Promise<CommissionRuleDto[]> {
    return this.commissions.rules();
  }

  @Post('commission-rules')
  @RequirePermission('commission_rule.manage', 'ALL')
  async create(
    @CurrentUser() auth: AuthContext,
    @Body(zod(upsertCommissionRuleSchema)) body: z.output<typeof upsertCommissionRuleSchema>,
    @ReqMeta() meta: RequestMeta,
  ) {
    return { id: await this.commissions.upsertRule(auth, null, body, meta) };
  }

  @Put('commission-rules/:id')
  @RequirePermission('commission_rule.manage', 'ALL')
  async update(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @Body(zod(upsertCommissionRuleSchema)) body: z.output<typeof upsertCommissionRuleSchema>,
    @ReqMeta() meta: RequestMeta,
  ) {
    return { id: await this.commissions.upsertRule(auth, id, body, meta) };
  }
}

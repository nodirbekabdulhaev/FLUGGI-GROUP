import { Body, Controller, Get, Param, Post, Put, Query } from '@nestjs/common';
import {
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

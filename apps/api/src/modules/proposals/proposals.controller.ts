import { Body, Controller, Get, HttpCode, Param, Post, Put, Query } from '@nestjs/common';
import {
  createProposalSchema,
  proposalListQuerySchema,
  upsertProposalSchema,
  type Paginated,
  type ProposalDto,
  type ProposalVersionDto,
} from '@fluggi/contracts';
import type { z } from 'zod';
import type { AuthContext, RequestMeta } from '../../core/auth/auth-context';
import { CurrentUser, ReqMeta, RequirePermission } from '../../core/auth/decorators';
import { UuidPipe } from '../../core/http/uuid.pipe';
import { zod } from '../../core/http/zod.pipe';
import { ProposalsService } from './proposals.service';

@Controller('proposals')
export class ProposalsController {
  constructor(private readonly proposals: ProposalsService) {}

  @Get()
  @RequirePermission('proposal.read')
  list(
    @CurrentUser() auth: AuthContext,
    @Query(zod(proposalListQuerySchema)) q: z.output<typeof proposalListQuerySchema>,
  ): Promise<Paginated<ProposalDto>> {
    return this.proposals.list(auth, q);
  }

  @Get(':id')
  @RequirePermission('proposal.read')
  get(@CurrentUser() auth: AuthContext, @Param('id', UuidPipe) id: string): Promise<ProposalDto> {
    return this.proposals.get(auth, id);
  }

  @Get(':id/versions')
  @RequirePermission('proposal.read')
  versions(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
  ): Promise<ProposalVersionDto[]> {
    return this.proposals.versions(auth, id);
  }

  @Post()
  @RequirePermission('proposal.create')
  create(
    @CurrentUser() auth: AuthContext,
    @Body(zod(createProposalSchema)) body: z.output<typeof createProposalSchema>,
    @ReqMeta() meta: RequestMeta,
  ): Promise<ProposalDto> {
    return this.proposals.create(auth, body, meta);
  }

  @Put(':id')
  @RequirePermission('proposal.update')
  update(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @Body(zod(upsertProposalSchema)) body: z.output<typeof upsertProposalSchema>,
    @ReqMeta() meta: RequestMeta,
  ): Promise<ProposalDto> {
    return this.proposals.update(auth, id, body, meta);
  }

  @Post(':id/submit-approval')
  @HttpCode(200)
  @RequirePermission('proposal.update')
  submit(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @ReqMeta() meta: RequestMeta,
  ) {
    return this.proposals.submitApproval(auth, id, meta);
  }

  @Post(':id/approve')
  @HttpCode(200)
  @RequirePermission('proposal.approve')
  approve(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @ReqMeta() meta: RequestMeta,
  ) {
    return this.proposals.approve(auth, id, meta);
  }

  @Post(':id/send')
  @HttpCode(200)
  @RequirePermission('proposal.send')
  send(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @ReqMeta() meta: RequestMeta,
  ) {
    return this.proposals.send(auth, id, meta);
  }

  @Post(':id/mark-viewed')
  @HttpCode(200)
  @RequirePermission('proposal.update')
  viewed(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @ReqMeta() meta: RequestMeta,
  ) {
    return this.proposals.markViewed(auth, id, meta);
  }

  @Post(':id/accept')
  @HttpCode(200)
  @RequirePermission('proposal.update')
  accept(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @ReqMeta() meta: RequestMeta,
  ) {
    return this.proposals.accept(auth, id, meta);
  }

  @Post(':id/reject')
  @HttpCode(200)
  @RequirePermission('proposal.update')
  reject(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @ReqMeta() meta: RequestMeta,
  ) {
    return this.proposals.reject(auth, id, meta);
  }
}

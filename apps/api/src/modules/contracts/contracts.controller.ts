import { Body, Controller, Get, HttpCode, Param, Patch, Post, Query } from '@nestjs/common';
import {
  contractListQuerySchema,
  createContractSchema,
  updateContractSchema,
  type ContractDto,
  type Paginated,
} from '@fluggi/contracts';
import type { z } from 'zod';
import type { AuthContext, RequestMeta } from '../../core/auth/auth-context';
import { CurrentUser, ReqMeta, RequirePermission } from '../../core/auth/decorators';
import { notFound } from '../../core/http/app.exception';
import { UuidPipe } from '../../core/http/uuid.pipe';
import { zod } from '../../core/http/zod.pipe';
import { ContractsService } from './contracts.service';

const ACTIONS = ['send', 'submit-approval', 'sign', 'cancel'] as const;

@Controller('contracts')
export class ContractsController {
  constructor(private readonly contracts: ContractsService) {}

  @Get()
  @RequirePermission('contract.read')
  list(
    @CurrentUser() auth: AuthContext,
    @Query(zod(contractListQuerySchema)) q: z.output<typeof contractListQuerySchema>,
  ): Promise<Paginated<ContractDto>> {
    return this.contracts.list(auth, q);
  }

  @Get(':id')
  @RequirePermission('contract.read')
  get(@CurrentUser() auth: AuthContext, @Param('id', UuidPipe) id: string): Promise<ContractDto> {
    return this.contracts.get(auth, id);
  }

  @Post()
  @RequirePermission('contract.create')
  create(
    @CurrentUser() auth: AuthContext,
    @Body(zod(createContractSchema)) body: z.output<typeof createContractSchema>,
    @ReqMeta() meta: RequestMeta,
  ): Promise<ContractDto> {
    return this.contracts.create(auth, body, meta);
  }

  @Patch(':id')
  @RequirePermission('contract.update')
  update(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @Body(zod(updateContractSchema)) body: z.output<typeof updateContractSchema>,
    @ReqMeta() meta: RequestMeta,
  ): Promise<ContractDto> {
    return this.contracts.update(auth, id, body, meta);
  }

  @Post(':id/:action')
  @HttpCode(200)
  @RequirePermission('contract.update')
  action(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @Param('action') action: string,
    @ReqMeta() meta: RequestMeta,
  ): Promise<ContractDto> {
    if (!(ACTIONS as readonly string[]).includes(action)) throw notFound('Действие');
    return this.contracts.transition(auth, id, action as (typeof ACTIONS)[number], meta);
  }
}

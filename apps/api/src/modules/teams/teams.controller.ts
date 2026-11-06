import { Body, Controller, Delete, Get, HttpCode, Param, Patch, Post } from '@nestjs/common';
import { createTeamSchema, updateTeamSchema, type TeamDto } from '@fluggi/contracts';
import type { z } from 'zod';
import type { AuthContext, RequestMeta } from '../../core/auth/auth-context';
import {
  AuthenticatedOnly,
  CurrentUser,
  ReqMeta,
  RequirePermission,
} from '../../core/auth/decorators';
import { UuidPipe } from '../../core/http/uuid.pipe';
import { zod } from '../../core/http/zod.pipe';
import { TeamsService } from './teams.service';

@Controller('teams')
export class TeamsController {
  constructor(private readonly teams: TeamsService) {}

  /** Справочник отделов нужен в фильтрах и формах всем ролям. */
  @Get()
  @AuthenticatedOnly()
  list(): Promise<TeamDto[]> {
    return this.teams.list();
  }

  @Post()
  @RequirePermission('employee.manage', 'ALL')
  create(
    @CurrentUser() auth: AuthContext,
    @Body(zod(createTeamSchema)) body: z.output<typeof createTeamSchema>,
    @ReqMeta() meta: RequestMeta,
  ): Promise<TeamDto> {
    return this.teams.create(auth, body, meta);
  }

  @Patch(':id')
  @RequirePermission('employee.manage', 'ALL')
  update(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @Body(zod(updateTeamSchema)) body: z.output<typeof updateTeamSchema>,
    @ReqMeta() meta: RequestMeta,
  ): Promise<TeamDto> {
    return this.teams.update(auth, id, body, meta);
  }

  @Delete(':id')
  @HttpCode(204)
  @RequirePermission('employee.manage', 'ALL')
  remove(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @ReqMeta() meta: RequestMeta,
  ): Promise<void> {
    return this.teams.remove(auth, id, meta);
  }
}

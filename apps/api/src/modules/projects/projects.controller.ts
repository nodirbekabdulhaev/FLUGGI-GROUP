import { Body, Controller, Get, HttpCode, Param, Patch, Post, Query } from '@nestjs/common';
import {
  addMemberSchema,
  applyTemplateSchema,
  projectListQuerySchema,
  projectStatusSchema,
  updateMemberSchema,
  updateProjectSchema,
  type ActivityDto,
  type MemberCandidateDto,
  type Paginated,
  type ProjectDetailDto,
  type ProjectDto,
} from '@fluggi/contracts';
import type { z } from 'zod';
import type { AuthContext, RequestMeta } from '../../core/auth/auth-context';
import { CurrentUser, ReqMeta, RequirePermission } from '../../core/auth/decorators';
import { UuidPipe } from '../../core/http/uuid.pipe';
import { zod } from '../../core/http/zod.pipe';
import { PrismaService } from '../../core/prisma/prisma.service';
import { ProjectAccessService } from './project-access.service';
import { ProjectsService } from './projects.service';

/** Проекты (ТЗ §19–21). Видимость — ProjectAccessService. */
@Controller('projects')
export class ProjectsController {
  constructor(
    private readonly projects: ProjectsService,
    private readonly access: ProjectAccessService,
    private readonly prisma: PrismaService,
  ) {}

  @Get()
  @RequirePermission('project.read')
  list(
    @CurrentUser() auth: AuthContext,
    @Query(zod(projectListQuerySchema)) q: z.output<typeof projectListQuerySchema>,
  ): Promise<Paginated<ProjectDto>> {
    return this.projects.list(auth, q);
  }

  @Get(':id')
  @RequirePermission('project.read')
  get(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
  ): Promise<ProjectDetailDto> {
    return this.projects.get(auth, id);
  }

  @Patch(':id')
  @RequirePermission('project.update')
  update(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @Body(zod(updateProjectSchema)) body: z.output<typeof updateProjectSchema>,
    @ReqMeta() meta: RequestMeta,
  ): Promise<ProjectDetailDto> {
    return this.projects.update(auth, id, body, meta);
  }

  @Post(':id/status')
  @HttpCode(200)
  @RequirePermission('project.update')
  status(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @Body(zod(projectStatusSchema)) body: z.output<typeof projectStatusSchema>,
    @ReqMeta() meta: RequestMeta,
  ): Promise<ProjectDetailDto> {
    return this.projects.setStatus(auth, id, body, meta);
  }

  @Post(':id/apply-template')
  @HttpCode(200)
  @RequirePermission('project.update')
  applyTemplate(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @Body(zod(applyTemplateSchema)) body: z.output<typeof applyTemplateSchema>,
    @ReqMeta() meta: RequestMeta,
  ): Promise<ProjectDetailDto> {
    return this.projects.applyTemplate(auth, id, body.templateId, meta);
  }

  @Get(':id/timeline')
  @RequirePermission('project.read')
  timeline(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
  ): Promise<ActivityDto[]> {
    return this.projects.timeline(auth, id);
  }

  /** Кого можно добавить в команду: активные исполнители, менеджеры и РОП. */
  @Get(':id/candidates')
  @RequirePermission('project.assign')
  async candidates(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
  ): Promise<MemberCandidateDto[]> {
    await this.access.project(auth, id, 'project.assign');
    const users = await this.prisma.user.findMany({
      where: {
        status: 'ACTIVE',
        deletedAt: null,
        role: { code: { in: ['EXECUTOR', 'MANAGER', 'ROP'] } },
      },
      include: { role: true, employee: true },
      orderBy: { fullName: 'asc' },
    });
    return users.map((u) => ({
      id: u.id,
      name: u.fullName,
      role: u.role.code,
      specialty: u.employee?.specialty ?? null,
    }));
  }

  @Post(':id/members')
  @RequirePermission('project.assign')
  addMember(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @Body(zod(addMemberSchema)) body: z.output<typeof addMemberSchema>,
    @ReqMeta() meta: RequestMeta,
  ): Promise<ProjectDetailDto> {
    return this.projects.addMember(auth, id, body, meta);
  }

  @Patch(':id/members/:memberId')
  @RequirePermission('project.assign')
  updateMember(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @Param('memberId', UuidPipe) memberId: string,
    @Body(zod(updateMemberSchema)) body: z.output<typeof updateMemberSchema>,
    @ReqMeta() meta: RequestMeta,
  ): Promise<ProjectDetailDto> {
    return this.projects.updateMember(auth, id, memberId, body, meta);
  }
}

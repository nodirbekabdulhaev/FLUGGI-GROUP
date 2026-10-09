import { Body, Controller, Get, Param, Post, Put } from '@nestjs/common';
import { upsertProjectTemplateSchema, type ProjectTemplateDto } from '@fluggi/contracts';
import type { z } from 'zod';
import type { AuthContext, RequestMeta } from '../../core/auth/auth-context';
import {
  AuthenticatedOnly,
  CurrentUser,
  ReqMeta,
  RequirePermission,
} from '../../core/auth/decorators';
import { forbidden } from '../../core/http/app.exception';
import { UuidPipe } from '../../core/http/uuid.pipe';
import { zod } from '../../core/http/zod.pipe';
import { TemplatesService } from './templates.service';

/** Шаблоны проектов (ТЗ §62): настраивает CEO/админ, применяет руководитель проекта. */
@Controller('project-templates')
export class TemplatesController {
  constructor(private readonly templates: TemplatesService) {}

  @Get()
  @AuthenticatedOnly()
  list(@CurrentUser() auth: AuthContext): Promise<ProjectTemplateDto[]> {
    const manage = Boolean(auth.permissions['reference.manage']);
    if (!manage && !auth.permissions['project.update']) throw forbidden();
    // Для применения к проекту — только активные; в настройках — все.
    return this.templates.list(!manage);
  }

  @Post()
  @RequirePermission('reference.manage')
  create(
    @CurrentUser() auth: AuthContext,
    @Body(zod(upsertProjectTemplateSchema)) body: z.output<typeof upsertProjectTemplateSchema>,
    @ReqMeta() meta: RequestMeta,
  ): Promise<ProjectTemplateDto> {
    return this.templates.create(auth, body, meta);
  }

  @Put(':id')
  @RequirePermission('reference.manage')
  update(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @Body(zod(upsertProjectTemplateSchema)) body: z.output<typeof upsertProjectTemplateSchema>,
    @ReqMeta() meta: RequestMeta,
  ): Promise<ProjectTemplateDto> {
    return this.templates.update(auth, id, body, meta);
  }
}

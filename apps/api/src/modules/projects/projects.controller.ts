import { Controller, Get, Param, Query } from '@nestjs/common';
import {
  formatNumber,
  paginationQuerySchema,
  type Paginated,
  type ProjectBriefDto,
} from '@fluggi/contracts';
import type { Prisma } from '@fluggi/db';
import type { z } from 'zod';
import type { AuthContext } from '../../core/auth/auth-context';
import { CurrentUser, RequirePermission } from '../../core/auth/decorators';
import { notFound } from '../../core/http/app.exception';
import { decReq } from '../../core/http/serialize';
import { UuidPipe } from '../../core/http/uuid.pipe';
import { zod } from '../../core/http/zod.pipe';
import { PrismaService } from '../../core/prisma/prisma.service';
import { scopeWhere } from '../../core/rbac/scope';

const include = {
  client: { select: { id: true, name: true } },
  deal: { select: { id: true, number: true, title: true } },
  rop: { select: { id: true, fullName: true } },
  manager: { select: { id: true, fullName: true } },
} satisfies Prisma.ProjectInclude;

const toDto = (p: Prisma.ProjectGetPayload<{ include: typeof include }>): ProjectBriefDto => ({
  id: p.id,
  number: formatNumber('P', p.number),
  name: p.name,
  client: p.client,
  deal: { id: p.deal.id, name: p.deal.title, number: formatNumber('D', p.deal.number) },
  rop: { id: p.rop.id, name: p.rop.fullName },
  manager: { id: p.manager.id, name: p.manager.fullName },
  status: p.status,
  price: decReq(p.price),
  currency: p.currency,
  createdAt: p.createdAt.toISOString(),
});

/**
 * Проекты, созданные из оплат. Полное управление проектом (команда, задачи, Kanban) — Phase 4.
 * Видимость: менеджер — свои, РОП — отдела и где он РОП, CEO — все.
 */
@Controller('projects')
export class ProjectsController {
  constructor(private readonly prisma: PrismaService) {}

  private where(auth: AuthContext): Prisma.ProjectWhereInput {
    return {
      deletedAt: null,
      ...scopeWhere<Prisma.ProjectWhereInput>(auth, 'project.read', {
        own: (userId) => ({ OR: [{ managerId: userId }, { ropId: userId }] }),
        team: (teamIds, userId) => ({
          OR: [{ teamId: { in: teamIds } }, { ropId: userId }, { managerId: userId }],
        }),
      }),
    };
  }

  @Get()
  @RequirePermission('project.read')
  async list(
    @CurrentUser() auth: AuthContext,
    @Query(zod(paginationQuerySchema)) q: z.output<typeof paginationQuerySchema>,
  ): Promise<Paginated<ProjectBriefDto>> {
    const where = this.where(auth);
    const [items, total] = await Promise.all([
      this.prisma.project.findMany({
        where,
        include,
        orderBy: { createdAt: 'desc' },
        skip: (q.page - 1) * q.pageSize,
        take: q.pageSize,
      }),
      this.prisma.project.count({ where }),
    ]);
    return { items: items.map(toDto), total, page: q.page, pageSize: q.pageSize };
  }

  @Get(':id')
  @RequirePermission('project.read')
  async get(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
  ): Promise<ProjectBriefDto> {
    const p = await this.prisma.project.findFirst({
      where: { AND: [this.where(auth), { id }] },
      include,
    });
    if (!p) throw notFound('Проект');
    return toDto(p);
  }
}

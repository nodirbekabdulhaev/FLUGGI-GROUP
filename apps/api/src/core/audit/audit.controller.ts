import { Controller, Get, Query } from '@nestjs/common';
import {
  auditListQuerySchema,
  type AuditChange,
  type AuditLogDto,
  type Paginated,
} from '@fluggi/contracts';
import type { Prisma } from '@fluggi/db';
import type { z } from 'zod';
import { RequirePermission } from '../auth/decorators';
import { zod } from '../http/zod.pipe';
import { PrismaService } from '../prisma/prisma.service';

@Controller('audit-logs')
export class AuditController {
  constructor(private readonly prisma: PrismaService) {}

  @Get()
  @RequirePermission('audit.read', 'ALL')
  async list(
    @Query(zod(auditListQuerySchema)) query: z.output<typeof auditListQuerySchema>,
  ): Promise<Paginated<AuditLogDto>> {
    const where: Prisma.AuditLogWhereInput = {
      actorId: query.actorId,
      entityType: query.entityType,
      entityId: query.entityId,
      createdAt: {
        gte: query.dateFrom ? new Date(query.dateFrom) : undefined,
        lte: query.dateTo ? new Date(query.dateTo) : undefined,
      },
    };
    const [items, total] = await Promise.all([
      this.prisma.auditLog.findMany({
        where,
        include: { actor: { select: { id: true, fullName: true } } },
        orderBy: { createdAt: 'desc' },
        skip: (query.page - 1) * query.pageSize,
        take: query.pageSize,
      }),
      this.prisma.auditLog.count({ where }),
    ]);
    return {
      items: items.map((a) => ({
        id: a.id,
        actor: a.actor,
        action: a.action,
        entityType: a.entityType,
        entityId: a.entityId,
        changes: a.changes as Record<string, AuditChange> | null,
        ip: a.ip,
        createdAt: a.createdAt.toISOString(),
      })),
      total,
      page: query.page,
      pageSize: query.pageSize,
    };
  }
}

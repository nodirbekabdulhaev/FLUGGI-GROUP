import { Body, Controller, Delete, Get, HttpCode, Param, Post, Query } from '@nestjs/common';
import {
  createCommentSchema,
  timelineQuerySchema,
  type ActivityDto,
  type CommentDto,
  type StageHistoryDto,
} from '@fluggi/contracts';
import type { Prisma } from '@fluggi/db';
import type { z } from 'zod';
import type { AuthContext, RequestMeta } from '../../core/auth/auth-context';
import { AuthenticatedOnly, CurrentUser, ReqMeta } from '../../core/auth/decorators';
import { AuditService } from '../../core/audit/audit.service';
import { forbidden, notFound } from '../../core/http/app.exception';
import { UuidPipe } from '../../core/http/uuid.pipe';
import { zod } from '../../core/http/zod.pipe';
import { PrismaService } from '../../core/prisma/prisma.service';
import { ActivityService } from './activity.service';
import { CrmAccessService } from './crm-access.service';

type Target = { leadId?: string; dealId?: string; clientId?: string };

/**
 * Таймлайн, история этапов и комментарии карточек лида/сделки/клиента.
 * Доступ проверяется по самой записи (право *.read с её областью).
 */
@Controller()
export class TimelineController {
  constructor(
    private readonly prisma: PrismaService,
    private readonly access: CrmAccessService,
    private readonly activity: ActivityService,
    private readonly audit: AuditService,
  ) {}

  private async assertAccess(auth: AuthContext, t: Target) {
    if (t.leadId) await this.access.lead(auth, t.leadId);
    else if (t.dealId) await this.access.deal(auth, t.dealId);
    else if (t.clientId) await this.access.client(auth, t.clientId);
  }

  @Get('timeline')
  @AuthenticatedOnly()
  async timeline(
    @CurrentUser() auth: AuthContext,
    @Query(zod(timelineQuerySchema)) q: z.output<typeof timelineQuerySchema>,
  ): Promise<ActivityDto[]> {
    await this.assertAccess(auth, q);
    let where: Prisma.ActivityWhereInput = q;
    // Таймлайн сделки включает историю лида, из которого она возникла.
    if (q.dealId) {
      const lead = await this.prisma.lead.findFirst({
        where: { dealId: q.dealId },
        select: { id: true },
      });
      if (lead) where = { OR: [{ dealId: q.dealId }, { leadId: lead.id }] };
    }
    const rows = await this.prisma.activity.findMany({
      where,
      include: { actor: { select: { id: true, fullName: true } } },
      orderBy: { createdAt: 'desc' },
      take: 200,
    });
    return rows.map((a) => ({
      id: a.id,
      type: a.type,
      actor: a.actor ? { id: a.actor.id, name: a.actor.fullName } : null,
      payload: a.payload as Record<string, unknown> | null,
      createdAt: a.createdAt.toISOString(),
    }));
  }

  @Get('stage-history')
  @AuthenticatedOnly()
  async stageHistory(
    @CurrentUser() auth: AuthContext,
    @Query(zod(timelineQuerySchema)) q: z.output<typeof timelineQuerySchema>,
  ): Promise<StageHistoryDto[]> {
    await this.assertAccess(auth, q);
    if (q.clientId) return [];
    const rows = await this.prisma.stageHistory.findMany({
      where: q.leadId ? { leadId: q.leadId } : { dealId: q.dealId },
      include: {
        fromStage: true,
        toStage: true,
        changedBy: { select: { id: true, fullName: true } },
      },
      orderBy: { createdAt: 'desc' },
    });
    const ref = (s: { id: string; code: string; nameRu: string; color: string }) => ({
      id: s.id,
      code: s.code,
      name: s.nameRu,
      color: s.color,
    });
    return rows.map((h) => ({
      id: h.id,
      from: h.fromStage ? ref(h.fromStage) : null,
      to: ref(h.toStage),
      changedBy: { id: h.changedBy.id, name: h.changedBy.fullName },
      durationSec: h.durationSec,
      createdAt: h.createdAt.toISOString(),
    }));
  }

  @Get('comments')
  @AuthenticatedOnly()
  async comments(
    @CurrentUser() auth: AuthContext,
    @Query(zod(timelineQuerySchema)) q: z.output<typeof timelineQuerySchema>,
  ): Promise<CommentDto[]> {
    await this.assertAccess(auth, q);
    const rows = await this.prisma.comment.findMany({
      where: { ...q, deletedAt: null },
      include: { author: { select: { id: true, fullName: true } } },
      orderBy: { createdAt: 'desc' },
    });
    return rows.map((c) => ({
      id: c.id,
      author: { id: c.author.id, name: c.author.fullName },
      body: c.body,
      createdAt: c.createdAt.toISOString(),
      canDelete: c.authorId === auth.userId,
    }));
  }

  @Post('comments')
  @AuthenticatedOnly()
  async addComment(
    @CurrentUser() auth: AuthContext,
    @Body(zod(createCommentSchema)) body: z.output<typeof createCommentSchema>,
  ): Promise<CommentDto> {
    const { body: text, ...target } = body;
    await this.assertAccess(auth, target);
    const c = await this.prisma.$transaction(async (tx) => {
      const created = await tx.comment.create({
        data: { ...target, body: text, authorId: auth.userId },
        include: { author: { select: { id: true, fullName: true } } },
      });
      await this.activity.log(tx, {
        type: 'comment.added',
        actorId: auth.userId,
        ...target,
        payload: { commentId: created.id, preview: text.slice(0, 140) },
      });
      return created;
    });
    return {
      id: c.id,
      author: { id: c.author.id, name: c.author.fullName },
      body: c.body,
      createdAt: c.createdAt.toISOString(),
      canDelete: true,
    };
  }

  /** Автор может скрыть свой комментарий (soft delete, след остаётся в аудите). */
  @Delete('comments/:id')
  @AuthenticatedOnly()
  @HttpCode(204)
  async deleteComment(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @ReqMeta() meta: RequestMeta,
  ): Promise<void> {
    const c = await this.prisma.comment.findFirst({ where: { id, deletedAt: null } });
    if (!c) throw notFound('Комментарий');
    if (c.authorId !== auth.userId) throw forbidden('Удалить можно только свой комментарий');
    await this.prisma.$transaction(async (tx) => {
      await tx.comment.update({ where: { id }, data: { deletedAt: new Date() } });
      await this.audit.log(tx, {
        actorId: auth.userId,
        action: 'comment.delete',
        entityType: 'comment',
        entityId: id,
        changes: { body: { old: c.body, new: null } },
        meta,
      });
    });
  }
}

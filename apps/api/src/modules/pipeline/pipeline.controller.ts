import { Controller, Get, Query } from '@nestjs/common';
import {
  formatNumber,
  pipelineQuerySchema,
  type PipelineCard,
  type PipelineColumn,
  type PipelineDto,
} from '@fluggi/contracts';
import { forecast, sum } from '@fluggi/domain';
import type { Prisma } from '@fluggi/db';
import type { z } from 'zod';
import type { AuthContext } from '../../core/auth/auth-context';
import { AuthenticatedOnly, CurrentUser } from '../../core/auth/decorators';
import { forbidden } from '../../core/http/app.exception';
import { dec } from '../../core/http/serialize';
import { zod } from '../../core/http/zod.pipe';
import { PrismaService } from '../../core/prisma/prisma.service';
import { CrmAccessService } from '../crm/crm-access.service';
import { toStageDto } from '../references/references.service';

const CARDS_PER_COLUMN = 100;

/** Единая доска воронки: этапы лида, затем этапы сделки (ТЗ §7). */
@Controller('pipeline')
export class PipelineController {
  constructor(
    private readonly prisma: PrismaService,
    private readonly access: CrmAccessService,
  ) {}

  @Get()
  @AuthenticatedOnly()
  async get(
    @CurrentUser() auth: AuthContext,
    @Query(zod(pipelineQuerySchema)) q: z.output<typeof pipelineQuerySchema>,
  ): Promise<PipelineDto> {
    const canLeads = Boolean(auth.permissions['lead.read']);
    const canDeals = Boolean(auth.permissions['deal.read']);
    if (!canLeads && !canDeals) throw forbidden();

    const common = { ownerId: q.ownerId, teamId: q.teamId, serviceId: q.serviceId };
    const stages = await this.prisma.dealStage.findMany({ orderBy: { sort: 'asc' } });

    const leads = canLeads
      ? await this.prisma.lead.findMany({
          where: {
            AND: [this.access.leadWhere(auth), { status: 'OPEN', ...common, sourceId: q.sourceId }],
          } satisfies Prisma.LeadWhereInput,
          include: { owner: { select: { id: true, fullName: true } } },
          orderBy: { updatedAt: 'desc' },
        })
      : [];
    const deals = canDeals
      ? await this.prisma.deal.findMany({
          where: {
            AND: [
              this.access.dealWhere(auth),
              {
                status: 'OPEN',
                ...common,
                ...(q.sourceId ? { client: { sourceId: q.sourceId } } : {}),
              },
            ],
          } satisfies Prisma.DealWhereInput,
          include: {
            owner: { select: { id: true, fullName: true } },
            client: { select: { name: true } },
          },
          orderBy: { updatedAt: 'desc' },
        })
      : [];

    const columns: PipelineColumn[] = [];
    for (const stage of stages) {
      if (stage.entity === 'LEAD' && !canLeads) continue;
      if (stage.entity === 'DEAL' && !canDeals) continue;
      let cards: PipelineCard[];
      let amounts: string[];
      if (stage.entity === 'LEAD') {
        const rows = leads.filter((l) => l.stageId === stage.id);
        amounts = rows.map((l) => l.budgetUzs?.toString() ?? '0');
        cards = rows.map((l) => ({
          id: l.id,
          kind: 'lead',
          number: formatNumber('L', l.number),
          title: l.title,
          subtitle: l.companyName ?? l.contactName,
          amount: dec(l.budget),
          currency: l.currency,
          amountUzs: dec(l.budgetUzs),
          owner: { id: l.owner.id, name: l.owner.fullName },
          scoreLevel: l.scoreLevel,
          nextContactAt: l.nextContactAt?.toISOString() ?? null,
          updatedAt: l.updatedAt.toISOString(),
        }));
      } else {
        const rows = deals.filter((d) => d.stageId === stage.id);
        amounts = rows.map((d) => d.amountUzs.toString());
        cards = rows.map((d) => ({
          id: d.id,
          kind: 'deal',
          number: formatNumber('D', d.number),
          title: d.title,
          subtitle: d.client.name,
          amount: dec(d.amount),
          currency: d.currency,
          amountUzs: dec(d.amountUzs),
          owner: { id: d.owner.id, name: d.owner.fullName },
          scoreLevel: null,
          nextContactAt: null,
          updatedAt: d.updatedAt.toISOString(),
        }));
      }
      columns.push({
        stage: toStageDto(stage),
        items: cards.slice(0, CARDS_PER_COLUMN),
        count: cards.length,
        totalUzs: sum(amounts).toFixed(2),
      });
    }

    const stageProb = new Map(stages.map((s) => [s.id, s.probability]));
    const f = forecast(
      deals.map((d) => ({
        amountUzs: d.amountUzs.toString(),
        probability: d.probabilityOverride ?? stageProb.get(d.stageId) ?? 0,
      })),
    );
    return { columns, pipelineUzs: f.pipeline.toFixed(2), weightedUzs: f.weighted.toFixed(2) };
  }
}

import { Injectable } from '@nestjs/common';
import {
  formatNumber,
  type completeFollowUpSchema,
  type FollowUpDto,
  type followUpListQuerySchema,
  type Paginated,
} from '@fluggi/contracts';
import { addDays, companyDate, projectOverdueDays } from '@fluggi/domain';
import type { Prisma } from '@fluggi/db';
import type { z } from 'zod';
import type { AuthContext, RequestMeta } from '../../core/auth/auth-context';
import { AuditService } from '../../core/audit/audit.service';
import { businessRule, forbidden, notFound } from '../../core/http/app.exception';
import { dateOnly, parseDate } from '../../core/http/serialize';
import { PrismaService, type Tx } from '../../core/prisma/prisma.service';
import { SettingsService } from '../../core/settings/settings.service';
import { CrmAccessService } from '../crm/crm-access.service';
import { DealsService } from '../deals/deals.service';

const include = {
  client: { select: { id: true, name: true } },
  project: { select: { id: true, number: true, name: true } },
  owner: { select: { id: true, fullName: true } },
  resultDeal: { select: { id: true, number: true, title: true } },
} satisfies Prisma.FollowUpInclude;

type Row = Prisma.FollowUpGetPayload<{ include: typeof include }>;

const KIND_TITLE = {
  CONTACT: 'Связаться с клиентом',
  NEW_PROJECT: 'Предложить новый проект',
  REPEAT_SALE: 'Повторная продажа',
} as const;

/** Повторные продажи (ТЗ §38, Rule 8): follow-up после завершения проекта. */
@Injectable()
export class FollowUpsService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly settings: SettingsService,
    private readonly access: CrmAccessService,
    private readonly deals: DealsService,
    private readonly audit: AuditService,
  ) {}

  static title(kind: keyof typeof KIND_TITLE) {
    return KIND_TITLE[kind];
  }

  private toDto(f: Row): FollowUpDto {
    const due = dateOnly(f.dueDate)!;
    return {
      id: f.id,
      client: f.client,
      project: f.project
        ? { id: f.project.id, name: f.project.name, number: formatNumber('P', f.project.number) }
        : null,
      owner: { id: f.owner.id, name: f.owner.fullName },
      kind: f.kind,
      dueDate: due,
      overdueDays: projectOverdueDays(due, f.status === 'PENDING', new Date()),
      status: f.status,
      result: f.result,
      resultDeal: f.resultDeal
        ? {
            id: f.resultDeal.id,
            name: f.resultDeal.title,
            number: formatNumber('D', f.resultDeal.number),
          }
        : null,
      completedAt: f.completedAt?.toISOString() ?? null,
    };
  }

  /** Создаёт follow-up по интервалам из настроек; повторный вызов для проекта ничего не делает. */
  async createForProject(tx: Tx, projectId: string): Promise<number> {
    const project = await tx.project.findUnique({ where: { id: projectId } });
    if (!project || project.status !== 'COMPLETED') return 0;
    if (await tx.followUp.count({ where: { projectId } })) return 0;
    const { followUps } = await this.settings.automation();
    const start = companyDate(project.completedAt ?? new Date());
    await tx.followUp.createMany({
      data: followUps.map((f) => ({
        clientId: project.clientId,
        projectId,
        ownerId: project.managerId,
        kind: f.kind,
        dueDate: parseDate(addDays(start, f.days))!,
      })),
    });
    return followUps.length;
  }

  private where(auth: AuthContext): Prisma.FollowUpWhereInput {
    if (!auth.permissions['client.read']) throw forbidden();
    return { OR: [{ ownerId: auth.userId }, { client: this.access.clientWhere(auth) }] };
  }

  async list(
    auth: AuthContext,
    q: z.output<typeof followUpListQuerySchema>,
  ): Promise<Paginated<FollowUpDto>> {
    const and: Prisma.FollowUpWhereInput[] = [this.where(auth)];
    if (q.status) and.push({ status: q.status });
    if (q.clientId) and.push({ clientId: q.clientId });
    if (q.due)
      and.push({ status: 'PENDING', dueDate: { lte: parseDate(companyDate(new Date()))! } });
    const where = { AND: and };
    const [rows, total] = await Promise.all([
      this.prisma.followUp.findMany({
        where,
        include,
        orderBy: [{ status: 'asc' }, { dueDate: 'asc' }],
        skip: (q.page - 1) * q.pageSize,
        take: q.pageSize,
      }),
      this.prisma.followUp.count({ where }),
    ]);
    return { items: rows.map((r) => this.toDto(r)), total, page: q.page, pageSize: q.pageSize };
  }

  /** Отметить выполненным / пропустить; можно сразу создать сделку «Повторная продажа». */
  async complete(
    auth: AuthContext,
    id: string,
    input: z.output<typeof completeFollowUpSchema>,
    meta: RequestMeta,
  ): Promise<FollowUpDto> {
    const f = await this.prisma.followUp.findFirst({
      where: { AND: [this.where(auth), { id }] },
      include: { project: { include: { deal: true } } },
    });
    if (!f) throw notFound('Follow-up');
    if (f.status !== 'PENDING') throw businessRule('Follow-up уже закрыт');
    let dealId: string | null = null;
    if (input.createDeal) {
      if (input.status !== 'DONE') throw businessRule('Сделку можно создать только при выполнении');
      const deal = await this.deals.create(
        auth,
        {
          clientId: f.clientId,
          title: `Повторная продажа${f.project ? ` — ${f.project.name}` : ''}`,
          serviceId: f.project?.deal.serviceId ?? undefined,
          amount: f.project ? f.project.priceUzs.toFixed(2) : '0',
          currency: 'UZS',
          isRepeat: true,
        },
        meta,
      );
      dealId = deal.id;
    }
    await this.prisma.$transaction(async (tx) => {
      await tx.followUp.update({
        where: { id },
        data: {
          status: input.status,
          result: input.result ?? null,
          resultDealId: dealId,
          completedAt: new Date(),
          completedById: auth.userId,
        },
      });
      await this.audit.log(tx, {
        actorId: auth.userId,
        action: 'followup.complete',
        entityType: 'follow_up',
        entityId: id,
        changes: {
          status: { old: 'PENDING', new: input.status },
          dealId: { old: null, new: dealId },
        },
        meta,
      });
    });
    return this.toDto(await this.prisma.followUp.findUniqueOrThrow({ where: { id }, include }));
  }
}

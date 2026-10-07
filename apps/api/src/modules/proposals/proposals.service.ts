import { Injectable } from '@nestjs/common';
import type {
  Paginated,
  ProposalDto,
  ProposalListQuery,
  ProposalVersionDto,
  createProposalSchema,
  upsertProposalSchema,
} from '@fluggi/contracts';
import { proposalTotals, toUzs } from '@fluggi/domain';
import type { Prisma, Proposal, ProposalStatus } from '@fluggi/db';
import type { z } from 'zod';
import type { AuthContext, RequestMeta } from '../../core/auth/auth-context';
import { AuditService } from '../../core/audit/audit.service';
import { businessRule, notFound } from '../../core/http/app.exception';
import { parseDate } from '../../core/http/serialize';
import { OutboxService } from '../../core/outbox/outbox.service';
import { PrismaService, type Tx } from '../../core/prisma/prisma.service';
import { ActivityService } from '../crm/activity.service';
import { CrmAccessService } from '../crm/crm-access.service';
import { DealsService } from '../deals/deals.service';
import { ExchangeRateService } from '../references/exchange-rate.service';
import { proposalInclude, toProposalDto } from './proposals.mapper';

type Upsert = z.output<typeof upsertProposalSchema>;
const EDITABLE: ProposalStatus[] = ['DRAFT', 'IN_APPROVAL', 'SENT', 'VIEWED', 'REJECTED'];

@Injectable()
export class ProposalsService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly access: CrmAccessService,
    private readonly activity: ActivityService,
    private readonly audit: AuditService,
    private readonly outbox: OutboxService,
    private readonly rates: ExchangeRateService,
    private readonly deals: DealsService,
  ) {}

  /** КП видно тем, кто видит сделку (и имеет proposal.read). */
  private where(auth: AuthContext): Prisma.ProposalWhereInput {
    return { deal: this.access.dealWhere(auth, 'proposal.read') };
  }

  async list(
    auth: AuthContext,
    q: ProposalListQuery & { page: number; pageSize: number },
  ): Promise<Paginated<ProposalDto>> {
    const and: Prisma.ProposalWhereInput[] = [this.where(auth)];
    if (q.status) and.push({ status: q.status });
    if (q.dealId) and.push({ dealId: q.dealId });
    if (q.managerId) and.push({ managerId: q.managerId });
    if (q.q)
      and.push({
        OR: [
          { title: { contains: q.q, mode: 'insensitive' } },
          { client: { name: { contains: q.q, mode: 'insensitive' } } },
        ],
      });
    const where = { AND: and };
    const [items, total] = await Promise.all([
      this.prisma.proposal.findMany({
        where,
        include: proposalInclude,
        orderBy: { updatedAt: 'desc' },
        skip: (q.page - 1) * q.pageSize,
        take: q.pageSize,
      }),
      this.prisma.proposal.count({ where }),
    ]);
    return { items: items.map(toProposalDto), total, page: q.page, pageSize: q.pageSize };
  }

  private async find(
    auth: AuthContext,
    id: string,
    code:
      'proposal.read' | 'proposal.update' | 'proposal.send' | 'proposal.approve' = 'proposal.read',
  ) {
    const p = await this.prisma.proposal.findFirst({
      where: { id, deal: this.access.dealWhere(auth, code) },
    });
    if (!p) throw notFound('КП');
    return p;
  }

  async get(auth: AuthContext, id: string): Promise<ProposalDto> {
    await this.find(auth, id);
    return toProposalDto(
      await this.prisma.proposal.findUniqueOrThrow({ where: { id }, include: proposalInclude }),
    );
  }

  async versions(auth: AuthContext, id: string): Promise<ProposalVersionDto[]> {
    await this.find(auth, id);
    const rows = await this.prisma.proposalVersion.findMany({
      where: { proposalId: id },
      include: { author: { select: { id: true, fullName: true } } },
      orderBy: { version: 'desc' },
    });
    return rows.map((v) => ({
      version: v.version,
      total: v.total.toFixed(2),
      currency: v.currency,
      author: { id: v.author.id, name: v.author.fullName },
      comment: v.comment,
      createdAt: v.createdAt.toISOString(),
    }));
  }

  private async compute(input: Upsert) {
    // Тариф определяет услугу позиции; архивный тариф в новое КП не добавить
    const tariffIds = [
      ...new Set(input.items.map((i) => i.tariffId).filter((x): x is string => Boolean(x))),
    ];
    if (tariffIds.length) {
      const tariffs = await this.prisma.tariff.findMany({ where: { id: { in: tariffIds } } });
      for (const [idx, item] of input.items.entries()) {
        if (!item.tariffId) continue;
        const t = tariffs.find((x) => x.id === item.tariffId);
        if (!t || !t.isActive)
          throw businessRule('Тариф не найден или отключён', [
            { path: `items.${idx}.tariffId`, message: 'Выберите действующий тариф' },
          ]);
        item.serviceId = t.serviceId;
      }
    }
    const totals = proposalTotals(input.items);
    const rate = await this.rates.rateFor(input.currency);
    return {
      totals,
      rate,
      totalUzs: toUzs(totals.total.toString(), input.currency, rate).toFixed(2),
    };
  }

  /** Новая версия = снимок шапки и позиций (ТЗ §16). */
  private async snapshot(
    tx: Tx,
    proposalId: string,
    version: number,
    authorId: string,
    comment?: string,
  ) {
    const p = await tx.proposal.findUniqueOrThrow({
      where: { id: proposalId },
      include: proposalInclude,
    });
    const dto = toProposalDto(p);
    await tx.proposalVersion.create({
      data: {
        proposalId,
        version,
        snapshot: dto as unknown as Prisma.InputJsonValue,
        total: p.total,
        currency: p.currency,
        authorId,
        comment: comment ?? null,
      },
    });
    return dto;
  }

  async create(
    auth: AuthContext,
    input: z.output<typeof createProposalSchema>,
    meta: RequestMeta,
  ): Promise<ProposalDto> {
    const deal = await this.access.deal(auth, input.dealId, 'proposal.create');
    if (deal.status !== 'OPEN') throw businessRule('Сделка закрыта');
    const { totals, rate, totalUzs } = await this.compute(input);
    return this.prisma.$transaction(async (tx) => {
      const p = await tx.proposal.create({
        data: {
          dealId: deal.id,
          clientId: deal.clientId,
          managerId: deal.ownerId,
          title: input.title,
          description: input.description ?? null,
          currency: input.currency,
          subtotal: totals.subtotal.toFixed(2),
          discountAmount: totals.discountAmount.toFixed(2),
          total: totals.total.toFixed(2),
          exchangeRate: rate,
          totalUzs,
          implementationTerm: input.implementationTerm ?? null,
          paymentTerms: input.paymentTerms ?? null,
          validUntil: parseDate(input.validUntil),
          items: {
            create: input.items.map((i, idx) => ({
              serviceId: i.serviceId ?? null,
              tariffId: i.tariffId ?? null,
              description: i.description,
              quantity: i.quantity,
              unitPrice: i.unitPrice,
              discountPct: i.discountPct,
              total: totals.lines[idx]!.total.toFixed(2),
              sort: idx,
            })),
          },
        },
      });
      const dto = await this.snapshot(tx, p.id, 1, auth.userId, input.versionComment ?? 'Создано');
      await this.activity.log(tx, {
        type: 'proposal.created',
        actorId: auth.userId,
        dealId: deal.id,
        payload: { number: dto.number, total: dto.total, currency: dto.currency },
      });
      await this.audit.log(tx, {
        actorId: auth.userId,
        action: 'proposal.create',
        entityType: 'proposal',
        entityId: p.id,
        changes: { total: { old: null, new: dto.total } },
        meta,
      });
      return dto;
    });
  }

  /** Любое изменение → новая версия; отправленное КП возвращается в черновик и требует повторной отправки. */
  async update(
    auth: AuthContext,
    id: string,
    input: Upsert,
    meta: RequestMeta,
  ): Promise<ProposalDto> {
    const before = await this.find(auth, id, 'proposal.update');
    if (!EDITABLE.includes(before.status))
      throw businessRule('Принятое КП изменить нельзя — создайте новое');
    const { totals, rate, totalUzs } = await this.compute(input);
    return this.prisma.$transaction(async (tx) => {
      await tx.proposalItem.deleteMany({ where: { proposalId: id } });
      const version = before.currentVersion + 1;
      await tx.proposal.update({
        where: { id },
        data: {
          title: input.title,
          description: input.description ?? null,
          currency: input.currency,
          subtotal: totals.subtotal.toFixed(2),
          discountAmount: totals.discountAmount.toFixed(2),
          total: totals.total.toFixed(2),
          exchangeRate: rate,
          totalUzs,
          implementationTerm: input.implementationTerm ?? null,
          paymentTerms: input.paymentTerms ?? null,
          validUntil: parseDate(input.validUntil),
          currentVersion: version,
          status: 'DRAFT',
          approvedById: null,
          approvedAt: null,
          items: {
            create: input.items.map((i, idx) => ({
              serviceId: i.serviceId ?? null,
              tariffId: i.tariffId ?? null,
              description: i.description,
              quantity: i.quantity,
              unitPrice: i.unitPrice,
              discountPct: i.discountPct,
              total: totals.lines[idx]!.total.toFixed(2),
              sort: idx,
            })),
          },
        },
      });
      const dto = await this.snapshot(tx, id, version, auth.userId, input.versionComment);
      await this.activity.log(tx, {
        type: 'proposal.updated',
        actorId: auth.userId,
        dealId: before.dealId,
        payload: {
          number: dto.number,
          version,
          old: before.total.toFixed(2),
          new: dto.total,
          currency: dto.currency,
        },
      });
      await this.audit.log(tx, {
        actorId: auth.userId,
        action: 'proposal.update',
        entityType: 'proposal',
        entityId: id,
        changes: {
          total: { old: before.total.toFixed(2), new: dto.total },
          version: { old: before.currentVersion, new: version },
        },
        meta,
      });
      return dto;
    });
  }

  private async transition(
    auth: AuthContext,
    p: Proposal,
    data: Prisma.ProposalUncheckedUpdateInput,
    action: string,
    meta: RequestMeta,
    after?: (tx: Tx) => Promise<void>,
  ): Promise<ProposalDto> {
    return this.prisma.$transaction(async (tx) => {
      await tx.proposal.update({ where: { id: p.id }, data });
      await this.activity.log(tx, {
        type: `proposal.${action}`,
        actorId: auth.userId,
        dealId: p.dealId,
        payload: { proposalId: p.id, title: p.title },
      });
      await this.audit.log(tx, {
        actorId: auth.userId,
        action: `proposal.${action}`,
        entityType: 'proposal',
        entityId: p.id,
        changes: data.status ? { status: { old: p.status, new: data.status } } : null,
        meta,
      });
      if (after) await after(tx);
      return toProposalDto(
        await tx.proposal.findUniqueOrThrow({ where: { id: p.id }, include: proposalInclude }),
      );
    });
  }

  async submitApproval(auth: AuthContext, id: string, meta: RequestMeta) {
    const p = await this.find(auth, id, 'proposal.update');
    if (p.status !== 'DRAFT') throw businessRule('На согласование отправляется черновик');
    const deal = await this.prisma.deal.findUniqueOrThrow({ where: { id: p.dealId } });
    return this.transition(auth, p, { status: 'IN_APPROVAL' }, 'approval_requested', meta, (tx) =>
      this.outbox.publish(
        tx,
        'proposal.approval_requested',
        { proposalId: id, dealId: p.dealId, teamId: deal.teamId },
        auth.userId,
      ),
    );
  }

  /** РОП утверждает КП (ТЗ §3.2). */
  async approve(auth: AuthContext, id: string, meta: RequestMeta) {
    const p = await this.find(auth, id, 'proposal.approve');
    if (!['DRAFT', 'IN_APPROVAL'].includes(p.status))
      throw businessRule('Утвердить можно черновик или КП на согласовании');
    return this.transition(
      auth,
      p,
      { status: 'DRAFT', approvedById: auth.userId, approvedAt: new Date() },
      'approved',
      meta,
    );
  }

  async send(auth: AuthContext, id: string, meta: RequestMeta) {
    const p = await this.find(auth, id, 'proposal.send');
    if (p.status === 'IN_APPROVAL')
      throw businessRule('КП на согласовании у РОП — дождитесь утверждения');
    if (!['DRAFT', 'REJECTED'].includes(p.status)) throw businessRule('КП уже отправлено');
    return this.transition(
      auth,
      p,
      { status: 'SENT', sentAt: new Date() },
      'sent',
      meta,
      async (tx) => {
        await this.deals.advanceTo(tx, p.dealId, 'PROPOSAL_SENT', auth.userId);
        await this.outbox.publish(
          tx,
          'proposal.sent',
          { proposalId: id, dealId: p.dealId },
          auth.userId,
        );
      },
    );
  }

  async markViewed(auth: AuthContext, id: string, meta: RequestMeta) {
    const p = await this.find(auth, id, 'proposal.update');
    if (p.status !== 'SENT') throw businessRule('Отметить просмотр можно у отправленного КП');
    return this.transition(auth, p, { status: 'VIEWED', viewedAt: new Date() }, 'viewed', meta);
  }

  /** Клиент принял КП: сделка → «Переговоры», сумма сделки = итог КП. */
  async accept(auth: AuthContext, id: string, meta: RequestMeta) {
    const p = await this.find(auth, id, 'proposal.update');
    if (!['SENT', 'VIEWED'].includes(p.status)) throw businessRule('Принять можно отправленное КП');
    return this.transition(
      auth,
      p,
      { status: 'ACCEPTED', acceptedAt: new Date() },
      'accepted',
      meta,
      async (tx) => {
        const deal = await tx.deal.findUniqueOrThrow({ where: { id: p.dealId } });
        if (!deal.amount.eq(p.total) || deal.currency !== p.currency) {
          await tx.deal.update({
            where: { id: deal.id },
            data: {
              amount: p.total,
              currency: p.currency,
              exchangeRate: p.exchangeRate,
              amountUzs: p.totalUzs,
            },
          });
          await this.activity.log(tx, {
            type: 'deal.amount_changed',
            actorId: auth.userId,
            dealId: deal.id,
            payload: {
              changes: { amount: { old: deal.amount.toFixed(2), new: p.total.toFixed(2) } },
              reason: 'КП принято',
            },
          });
        }
        await this.deals.advanceTo(tx, p.dealId, 'NEGOTIATION', auth.userId);
        await this.outbox.publish(
          tx,
          'proposal.accepted',
          { proposalId: id, dealId: p.dealId },
          auth.userId,
        );
      },
    );
  }

  async reject(auth: AuthContext, id: string, meta: RequestMeta) {
    const p = await this.find(auth, id, 'proposal.update');
    if (!['SENT', 'VIEWED'].includes(p.status))
      throw businessRule('Отклонить можно отправленное КП');
    return this.transition(
      auth,
      p,
      { status: 'REJECTED', rejectedAt: new Date() },
      'rejected',
      meta,
    );
  }
}

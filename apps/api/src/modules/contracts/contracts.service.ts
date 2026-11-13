import { Injectable } from '@nestjs/common';
import {
  formatNumber,
  type ContractDto,
  type ContractListQuery,
  type Paginated,
  type createContractSchema,
  type updateContractSchema,
} from '@fluggi/contracts';
import { sum } from '@fluggi/domain';
import type { Contract, ContractStatus, Prisma } from '@fluggi/db';
import type { z } from 'zod';
import type { AuthContext, RequestMeta } from '../../core/auth/auth-context';
import { AuditService, diffFields } from '../../core/audit/audit.service';
import { businessRule, notFound } from '../../core/http/app.exception';
import { dateOnly, decReq, iso, parseDate } from '../../core/http/serialize';
import { OutboxService } from '../../core/outbox/outbox.service';
import { PrismaService, type Tx } from '../../core/prisma/prisma.service';
import { ActivityService } from '../crm/activity.service';
import { CrmAccessService } from '../crm/crm-access.service';
import { DealsService } from '../deals/deals.service';
import { toFileDto } from '../files/files.service';
import { ExchangeRateService } from '../references/exchange-rate.service';

const include = {
  deal: { select: { id: true, number: true, title: true } },
  client: { select: { id: true, name: true } },
  proposal: { select: { id: true, number: true, title: true } },
  createdBy: { select: { id: true, fullName: true } },
  files: {
    where: { deletedAt: null },
    include: { uploadedBy: { select: { id: true, fullName: true } } },
  },
  payments: { where: { status: 'PAID' }, select: { amountUzs: true, type: true } },
} satisfies Prisma.ContractInclude;
type Row = Prisma.ContractGetPayload<{ include: typeof include }>;

const toDto = (c: Row): ContractDto => ({
  id: c.id,
  number: formatNumber('C', c.number).replace(/^C-/, 'ДГ-'),
  deal: { id: c.deal.id, name: c.deal.title, number: formatNumber('D', c.deal.number) },
  client: c.client,
  proposal: c.proposal
    ? { id: c.proposal.id, name: c.proposal.title, number: formatNumber('KP', c.proposal.number) }
    : null,
  contractDate: dateOnly(c.contractDate)!,
  amount: decReq(c.amount),
  currency: c.currency,
  amountUzs: decReq(c.amountUzs),
  status: c.status,
  signedAt: iso(c.signedAt),
  comment: c.comment,
  paidUzs: sum(
    c.payments.map((p) => (p.type === 'REFUND' ? p.amountUzs.neg() : p.amountUzs).toString()),
  ).toFixed(2),
  files: c.files.map(toFileDto),
  createdBy: { id: c.createdBy.id, name: c.createdBy.fullName },
  createdAt: c.createdAt.toISOString(),
});

const TRANSITIONS: Record<string, { from: ContractStatus[]; to: ContractStatus }> = {
  send: { from: ['DRAFT'], to: 'SENT' },
  'submit-approval': { from: ['DRAFT', 'SENT'], to: 'IN_APPROVAL' },
  sign: { from: ['DRAFT', 'SENT', 'IN_APPROVAL'], to: 'SIGNED' },
  cancel: { from: ['DRAFT', 'SENT', 'IN_APPROVAL', 'SIGNED'], to: 'CANCELLED' },
};

@Injectable()
export class ContractsService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly access: CrmAccessService,
    private readonly activity: ActivityService,
    private readonly audit: AuditService,
    private readonly outbox: OutboxService,
    private readonly rates: ExchangeRateService,
    private readonly deals: DealsService,
  ) {}

  async list(
    auth: AuthContext,
    q: ContractListQuery & { page: number; pageSize: number },
  ): Promise<Paginated<ContractDto>> {
    const and: Prisma.ContractWhereInput[] = [
      { deal: this.access.dealWhere(auth, 'contract.read') },
    ];
    if (q.status) and.push({ status: q.status });
    if (q.dealId) and.push({ dealId: q.dealId });
    if (q.q)
      and.push({
        OR: [{ client: { name: { contains: q.q } } }, { deal: { title: { contains: q.q } } }],
      });
    const where = { AND: and };
    const [items, total] = await Promise.all([
      this.prisma.contract.findMany({
        where,
        include,
        orderBy: { createdAt: 'desc' },
        skip: (q.page - 1) * q.pageSize,
        take: q.pageSize,
      }),
      this.prisma.contract.count({ where }),
    ]);
    return { items: items.map(toDto), total, page: q.page, pageSize: q.pageSize };
  }

  private async find(
    auth: AuthContext,
    id: string,
    code: 'contract.read' | 'contract.update' = 'contract.read',
  ): Promise<Contract> {
    const c = await this.prisma.contract.findFirst({
      where: { id, deal: this.access.dealWhere(auth, code) },
    });
    if (!c) throw notFound('Договор');
    return c;
  }

  async get(auth: AuthContext, id: string): Promise<ContractDto> {
    await this.find(auth, id);
    return toDto(await this.prisma.contract.findUniqueOrThrow({ where: { id }, include }));
  }

  /** Договор по сделке; из принятого КП сумма берётся из КП. Сделка → «Договор». */
  async create(
    auth: AuthContext,
    input: z.output<typeof createContractSchema>,
    meta: RequestMeta,
  ): Promise<ContractDto> {
    const deal = await this.access.deal(auth, input.dealId, 'contract.create');
    if (deal.status !== 'OPEN') throw businessRule('Сделка закрыта');
    if (input.proposalId) {
      const p = await this.prisma.proposal.findFirst({
        where: { id: input.proposalId, dealId: deal.id },
      });
      if (!p) throw businessRule('КП не относится к этой сделке');
      if (p.status !== 'ACCEPTED') throw businessRule('Договор создаётся по принятому КП');
    }
    const { rate, amountUzs } = await this.rates.convert(input.amount, input.currency);
    return this.prisma.$transaction(async (tx) => {
      const c = await tx.contract.create({
        data: {
          dealId: deal.id,
          clientId: deal.clientId,
          proposalId: input.proposalId,
          contractDate: parseDate(input.contractDate)!,
          amount: input.amount,
          currency: input.currency,
          exchangeRate: rate,
          amountUzs,
          comment: input.comment,
          createdById: auth.userId,
        },
        include,
      });
      await this.activity.log(tx, {
        type: 'contract.created',
        actorId: auth.userId,
        dealId: deal.id,
        payload: { number: toDto(c).number, amount: input.amount, currency: input.currency },
      });
      await this.deals.advanceTo(tx, deal.id, 'CONTRACT', auth.userId);
      await this.audit.log(tx, {
        actorId: auth.userId,
        action: 'contract.create',
        entityType: 'contract',
        entityId: c.id,
        changes: { amount: { old: null, new: input.amount } },
        meta,
      });
      return toDto(c);
    });
  }

  async update(
    auth: AuthContext,
    id: string,
    input: z.output<typeof updateContractSchema>,
    meta: RequestMeta,
  ): Promise<ContractDto> {
    const before = await this.find(auth, id, 'contract.update');
    if (before.status === 'SIGNED' || before.status === 'CANCELLED')
      throw businessRule('Подписанный или отменённый договор изменить нельзя');
    const data: Prisma.ContractUncheckedUpdateInput = {
      ...input,
      contractDate: parseDate(input.contractDate) ?? undefined,
    };
    if (input.amount || input.currency) {
      const conv = await this.rates.convert(
        input.amount ?? before.amount.toString(),
        input.currency ?? before.currency,
      );
      data.exchangeRate = conv.rate;
      data.amountUzs = conv.amountUzs;
    }
    return this.prisma.$transaction(async (tx) => {
      const after = await tx.contract.update({ where: { id }, data, include });
      const changes = diffFields(before, after, ['contractDate', 'amount', 'currency', 'comment']);
      if (changes)
        await this.audit.log(tx, {
          actorId: auth.userId,
          action: 'contract.update',
          entityType: 'contract',
          entityId: id,
          changes,
          meta,
        });
      return toDto(after);
    });
  }

  async transition(
    auth: AuthContext,
    id: string,
    action: keyof typeof TRANSITIONS,
    meta: RequestMeta,
  ): Promise<ContractDto> {
    const c = await this.find(auth, id, 'contract.update');
    const t = TRANSITIONS[action]!;
    if (!t.from.includes(c.status))
      throw businessRule('Это действие недоступно для договора в текущем статусе');
    if (action === 'cancel') {
      const paid = await this.prisma.payment.count({ where: { contractId: id, status: 'PAID' } });
      if (paid) throw businessRule('По договору есть подтверждённые оплаты — отменить нельзя');
    }
    return this.prisma.$transaction(async (tx: Tx) => {
      const after = await tx.contract.update({
        where: { id },
        data: { status: t.to, ...(t.to === 'SIGNED' ? { signedAt: new Date() } : {}) },
        include,
      });
      await this.activity.log(tx, {
        type: `contract.${t.to === 'SIGNED' ? 'signed' : t.to.toLowerCase()}`,
        actorId: auth.userId,
        dealId: c.dealId,
        payload: { number: toDto(after).number },
      });
      await this.audit.log(tx, {
        actorId: auth.userId,
        action: `contract.${action}`,
        entityType: 'contract',
        entityId: id,
        changes: { status: { old: c.status, new: t.to } },
        meta,
      });
      if (t.to === 'SIGNED') {
        const deal = await tx.deal.findUniqueOrThrow({ where: { id: c.dealId } });
        await this.deals.advanceTo(tx, c.dealId, 'AWAITING_PAYMENT', auth.userId);
        await this.outbox.publish(
          tx,
          'contract.signed',
          { contractId: id, dealId: c.dealId, managerId: deal.ownerId, teamId: deal.teamId },
          auth.userId,
        );
      }
      return toDto(after);
    });
  }
}

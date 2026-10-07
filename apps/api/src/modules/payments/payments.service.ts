import { Injectable } from '@nestjs/common';
import {
  formatNumber,
  type ConfirmPaymentResult,
  type DealMoneyDto,
  type Paginated,
  type PaymentDto,
  type PaymentListQuery,
  type createPaymentSchema,
  type refundPaymentSchema,
} from '@fluggi/contracts';
import { companyDate, sum } from '@fluggi/domain';
import { Prisma, type Deal } from '@fluggi/db';
import type { z } from 'zod';
import type { AuthContext, RequestMeta } from '../../core/auth/auth-context';
import { AuditService } from '../../core/audit/audit.service';
import { businessRule, notFound } from '../../core/http/app.exception';
import { dateOnly, decReq, iso, parseDate } from '../../core/http/serialize';
import { OutboxService } from '../../core/outbox/outbox.service';
import { PrismaService, type Tx } from '../../core/prisma/prisma.service';
import { CommissionEngine } from '../commissions/commission-engine.service';
import { commissionInclude, toCommissionDto } from '../commissions/commissions.service';
import { ActivityService } from '../crm/activity.service';
import { CrmAccessService } from '../crm/crm-access.service';
import { DealsService } from '../deals/deals.service';
import { TemplatesService } from '../projects/templates.service';
import { ExchangeRateService } from '../references/exchange-rate.service';

const include = {
  deal: { select: { id: true, number: true, title: true } },
  client: { select: { id: true, name: true } },
  contract: { select: { id: true, number: true } },
  project: { select: { id: true, number: true, name: true } },
  refundOf: { select: { id: true, number: true } },
  refunds: { where: { status: 'PAID' }, select: { amountUzs: true } },
  confirmedBy: { select: { id: true, fullName: true } },
  createdBy: { select: { id: true, fullName: true } },
} satisfies Prisma.PaymentInclude;
type Row = Prisma.PaymentGetPayload<{ include: typeof include }>;

const pay = (n: number) => formatNumber('PAY', n);

const toDto = (p: Row): PaymentDto => ({
  id: p.id,
  number: pay(p.number),
  deal: { id: p.deal.id, name: p.deal.title, number: formatNumber('D', p.deal.number) },
  client: p.client,
  contract: p.contract
    ? {
        id: p.contract.id,
        name: `ДГ-${String(p.contract.number).padStart(5, '0')}`,
        number: `ДГ-${String(p.contract.number).padStart(5, '0')}`,
      }
    : null,
  project: p.project
    ? { id: p.project.id, name: p.project.name, number: formatNumber('P', p.project.number) }
    : null,
  refundOf: p.refundOf
    ? { id: p.refundOf.id, name: pay(p.refundOf.number), number: pay(p.refundOf.number) }
    : null,
  amount: decReq(p.amount),
  currency: p.currency,
  exchangeRate: p.exchangeRate.toString(),
  amountUzs: decReq(p.amountUzs),
  type: p.type,
  method: p.method,
  status: p.status,
  dueDate: dateOnly(p.dueDate),
  paidAt: iso(p.paidAt),
  comment: p.comment,
  confirmedBy: p.confirmedBy ? { id: p.confirmedBy.id, name: p.confirmedBy.fullName } : null,
  createdBy: { id: p.createdBy.id, name: p.createdBy.fullName },
  createdAt: p.createdAt.toISOString(),
  refundableUzs:
    p.status === 'PAID' && p.type !== 'REFUND'
      ? p.amountUzs.sub(sum(p.refunds.map((r) => r.amountUzs.toString()))).toFixed(2)
      : '0.00',
});

@Injectable()
export class PaymentsService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly access: CrmAccessService,
    private readonly activity: ActivityService,
    private readonly audit: AuditService,
    private readonly outbox: OutboxService,
    private readonly rates: ExchangeRateService,
    private readonly deals: DealsService,
    private readonly commissions: CommissionEngine,
    private readonly templates: TemplatesService,
  ) {}

  async list(
    auth: AuthContext,
    q: PaymentListQuery & { page: number; pageSize: number },
  ): Promise<Paginated<PaymentDto>> {
    const and: Prisma.PaymentWhereInput[] = [{ deal: this.access.dealWhere(auth, 'payment.read') }];
    if (q.status) and.push({ status: q.status });
    if (q.type) and.push({ type: q.type });
    if (q.dealId) and.push({ dealId: q.dealId });
    if (q.clientId) and.push({ clientId: q.clientId });
    if (q.dateFrom)
      and.push({
        OR: [
          { paidAt: { gte: new Date(`${q.dateFrom}T00:00:00+05:00`) } },
          { paidAt: null, createdAt: { gte: new Date(`${q.dateFrom}T00:00:00+05:00`) } },
        ],
      });
    if (q.dateTo) {
      const to = new Date(new Date(`${q.dateTo}T00:00:00+05:00`).getTime() + 86_400_000);
      and.push({ OR: [{ paidAt: { lt: to } }, { paidAt: null, createdAt: { lt: to } }] });
    }
    const where = { AND: and };
    const [items, total] = await Promise.all([
      this.prisma.payment.findMany({
        where,
        include,
        orderBy: { createdAt: 'desc' },
        skip: (q.page - 1) * q.pageSize,
        take: q.pageSize,
      }),
      this.prisma.payment.count({ where }),
    ]);
    return { items: items.map(toDto), total, page: q.page, pageSize: q.pageSize };
  }

  private async find(
    auth: AuthContext,
    id: string,
    code: 'payment.read' | 'payment.create' | 'payment.confirm' | 'payment.refund' = 'payment.read',
  ) {
    const p = await this.prisma.payment.findFirst({
      where: { id, deal: this.access.dealWhere(auth, code) },
    });
    if (!p) throw notFound('Оплата');
    return p;
  }

  async get(auth: AuthContext, id: string): Promise<PaymentDto> {
    await this.find(auth, id);
    return toDto(await this.prisma.payment.findUniqueOrThrow({ where: { id }, include }));
  }

  async create(
    auth: AuthContext,
    input: z.output<typeof createPaymentSchema>,
    meta: RequestMeta,
  ): Promise<PaymentDto> {
    const deal = await this.access.deal(auth, input.dealId, 'payment.create');
    if (!['OPEN', 'WON'].includes(deal.status)) throw businessRule('Сделка закрыта');
    if (input.contractId) {
      const c = await this.prisma.contract.findFirst({
        where: { id: input.contractId, dealId: deal.id },
      });
      if (!c) throw businessRule('Договор не относится к этой сделке');
      if (c.status === 'CANCELLED') throw businessRule('Договор отменён');
    }
    const { rate, amountUzs } = await this.rates.convert(input.amount, input.currency);
    return this.prisma.$transaction(async (tx) => {
      const p = await tx.payment.create({
        data: {
          dealId: deal.id,
          clientId: deal.clientId,
          contractId: input.contractId,
          amount: input.amount,
          currency: input.currency,
          exchangeRate: rate,
          amountUzs,
          type: input.type,
          method: input.method,
          dueDate: parseDate(input.dueDate),
          comment: input.comment,
          createdById: auth.userId,
        },
        include,
      });
      await this.activity.log(tx, {
        type: 'payment.created',
        actorId: auth.userId,
        dealId: deal.id,
        payload: {
          number: pay(p.number),
          amount: input.amount,
          currency: input.currency,
          type: input.type,
        },
      });
      await this.audit.log(tx, {
        actorId: auth.userId,
        action: 'payment.create',
        entityType: 'payment',
        entityId: p.id,
        changes: {
          amount: { old: null, new: input.amount },
          status: { old: null, new: 'PENDING' },
        },
        meta,
      });
      await this.outbox.publish(
        tx,
        'payment.created',
        { paymentId: p.id, dealId: deal.id },
        auth.userId,
      );
      return toDto(p);
    });
  }

  /**
   * Подтверждение оплаты (ТЗ §19, Rule 3, 4, 7). В одной транзакции:
   *  1. платёж → PAID;
   *  2. первый платёж: сделка → WON и этап «Оплачено»;
   *  3. проект создаётся, если его ещё нет (один проект на сделку), РОП назначается;
   *  4. комиссии менеджеру и РОП по правилам.
   * Сделка блокируется FOR UPDATE — два одновременных подтверждения не создадут два проекта.
   */
  async confirm(
    auth: AuthContext,
    id: string,
    paidAt: Date | undefined,
    meta: RequestMeta,
  ): Promise<ConfirmPaymentResult> {
    const p0 = await this.find(auth, id, 'payment.confirm');
    if (p0.status !== 'PENDING') throw businessRule('Оплата уже обработана');
    if (p0.type === 'REFUND') throw businessRule('Возврат подтверждается при создании');

    return this.prisma.$transaction(
      async (tx) => {
        await tx.$queryRaw`SELECT id FROM deals WHERE id = ${p0.dealId}::uuid FOR UPDATE`;
        const current = await tx.payment.findUniqueOrThrow({ where: { id } });
        if (current.status !== 'PENDING') throw businessRule('Оплата уже обработана');
        const deal = await tx.deal.findUniqueOrThrow({ where: { id: p0.dealId } });
        const paidBefore = await tx.payment.count({
          where: { dealId: deal.id, status: 'PAID', type: { not: 'REFUND' } },
        });
        const firstPayment = paidBefore === 0;

        const payment = await tx.payment.update({
          where: { id },
          data: {
            status: 'PAID',
            paidAt: paidAt ?? new Date(),
            confirmedById: auth.userId,
            confirmedAt: new Date(),
          },
        });

        if (deal.status === 'OPEN') {
          await tx.deal.update({
            where: { id: deal.id },
            data: { status: 'WON', wonAt: payment.paidAt, closedAt: payment.paidAt },
          });
          await this.deals.advanceTo(tx, deal.id, 'PAID', auth.userId);
          await this.outbox.publish(
            tx,
            'deal.won',
            {
              dealId: deal.id,
              ownerId: deal.ownerId,
              teamId: deal.teamId,
              amountUzs: deal.amountUzs.toFixed(2),
            },
            auth.userId,
          );
        }

        const { project, created } = await this.ensureProject(tx, deal, auth);
        await tx.payment.update({ where: { id }, data: { projectId: project.id } });
        const commissions = await this.commissions.accrue(tx, payment, deal, firstPayment);

        await this.activity.log(tx, {
          type: 'payment.paid',
          actorId: auth.userId,
          dealId: deal.id,
          clientId: deal.clientId,
          payload: {
            number: pay(payment.number),
            amount: payment.amount.toFixed(2),
            currency: payment.currency,
            project: formatNumber('P', project.number),
            projectCreated: created,
          },
        });
        await this.audit.log(tx, {
          actorId: auth.userId,
          action: 'payment.confirm',
          entityType: 'payment',
          entityId: id,
          changes: {
            status: { old: 'PENDING', new: 'PAID' },
            amountUzs: { old: null, new: payment.amountUzs.toFixed(2) },
          },
          meta,
        });
        await this.outbox.publish(
          tx,
          'payment.paid',
          {
            paymentId: id,
            dealId: deal.id,
            amountUzs: payment.amountUzs.toFixed(2),
            managerId: deal.ownerId,
            teamId: deal.teamId,
            projectId: project.id,
            projectCreated: created,
          },
          auth.userId,
        );

        const full = await tx.payment.findUniqueOrThrow({ where: { id }, include });
        const comm = await tx.commission.findMany({
          where: { id: { in: commissions.map((c) => c.id) } },
          include: commissionInclude,
        });
        return {
          payment: toDto(full),
          project: {
            id: project.id,
            name: project.name,
            number: formatNumber('P', project.number),
          },
          projectCreated: created,
          commissions: comm.map(toCommissionDto),
        };
      },
      { isolationLevel: Prisma.TransactionIsolationLevel.ReadCommitted, timeout: 20_000 },
    );
  }

  /** Rule 1, 3, 4: проект всегда с клиентом и сделкой; РОП — руководитель отдела сделки. */
  private async ensureProject(tx: Tx, deal: Deal, auth: AuthContext) {
    const existing = await tx.project.findUnique({ where: { dealId: deal.id } });
    if (existing) return { project: existing, created: false };
    const team = deal.teamId ? await tx.team.findUnique({ where: { id: deal.teamId } }) : null;
    let ropId = team?.headId ?? null;
    if (!ropId && ['ROP', 'CEO'].includes(auth.roleCode)) ropId = auth.userId;
    if (!ropId) {
      const ceo = await tx.user.findFirst({
        where: { role: { code: 'CEO' }, status: 'ACTIVE', deletedAt: null },
      });
      ropId = ceo?.id ?? null;
    }
    if (!ropId) throw businessRule('Не найден РОП для проекта: назначьте руководителя отдела');
    const signed = await tx.contract.findFirst({
      where: { dealId: deal.id, status: 'SIGNED' },
      orderBy: { signedAt: 'desc' },
    });
    const project = await tx.project.create({
      data: {
        name: deal.title,
        clientId: deal.clientId,
        dealId: deal.id,
        price: signed?.amount ?? deal.amount,
        currency: signed?.currency ?? deal.currency,
        priceUzs: signed?.amountUzs ?? deal.amountUzs,
        ropId,
        managerId: deal.ownerId,
        teamId: deal.teamId,
        startDate: parseDate(companyDate(new Date())),
      },
    });
    await this.activity.log(tx, {
      type: 'project.created',
      actorId: auth.userId,
      dealId: deal.id,
      clientId: deal.clientId,
      projectId: project.id,
      payload: { number: formatNumber('P', project.number), name: project.name },
    });
    // Базовый чек-лист (ТЗ §19 п.6): задачи из шаблона услуги сделки, если он настроен.
    const template = await this.templates.forService(tx, deal.serviceId);
    if (template) await this.templates.apply(tx, project, template.id, auth.userId);
    await this.outbox.publish(
      tx,
      'project.created',
      { projectId: project.id, dealId: deal.id, ropId, managerId: deal.ownerId },
      auth.userId,
    );
    return { project, created: true };
  }

  /** Возврат: отдельная запись REFUND (сразу PAID), сторно комиссий (ТЗ §66, BUSINESS_RULES R13). */
  async refund(
    auth: AuthContext,
    id: string,
    input: z.output<typeof refundPaymentSchema>,
    meta: RequestMeta,
  ): Promise<PaymentDto> {
    const original = await this.find(auth, id, 'payment.refund');
    if (original.status !== 'PAID' || original.type === 'REFUND')
      throw businessRule('Вернуть можно только подтверждённую оплату');
    return this.prisma.$transaction(async (tx) => {
      await tx.$queryRaw`SELECT id FROM deals WHERE id = ${original.dealId}::uuid FOR UPDATE`;
      const refunded = await tx.payment.aggregate({
        where: { refundOfId: id, status: 'PAID' },
        _sum: { amountUzs: true },
      });
      const amountUzs = new Prisma.Decimal(input.amount)
        .mul(original.exchangeRate)
        .toDecimalPlaces(2);
      const available = original.amountUzs.sub(refunded._sum.amountUzs ?? 0);
      if (amountUzs.gt(available))
        throw businessRule(`Можно вернуть не больше ${available.toFixed(2)} UZS`);
      const r = await tx.payment.create({
        data: {
          dealId: original.dealId,
          clientId: original.clientId,
          contractId: original.contractId,
          projectId: original.projectId,
          refundOfId: id,
          amount: input.amount,
          currency: original.currency,
          exchangeRate: original.exchangeRate,
          amountUzs,
          type: 'REFUND',
          method: input.method,
          status: 'PAID',
          paidAt: new Date(),
          comment: input.comment,
          confirmedById: auth.userId,
          confirmedAt: new Date(),
          createdById: auth.userId,
        },
      });
      const deal = await tx.deal.findUniqueOrThrow({ where: { id: original.dealId } });
      await this.commissions.accrue(tx, r, deal, false);
      await this.activity.log(tx, {
        type: 'payment.refunded',
        actorId: auth.userId,
        dealId: deal.id,
        payload: {
          number: pay(r.number),
          amount: input.amount,
          currency: original.currency,
          of: pay(original.number),
        },
      });
      await this.audit.log(tx, {
        actorId: auth.userId,
        action: 'payment.refund',
        entityType: 'payment',
        entityId: r.id,
        changes: {
          refundOf: { old: null, new: id },
          amountUzs: { old: null, new: amountUzs.toFixed(2) },
        },
        meta,
      });
      await this.outbox.publish(
        tx,
        'payment.refunded',
        { paymentId: r.id, dealId: deal.id, amountUzs: amountUzs.toFixed(2) },
        auth.userId,
      );
      return toDto(await tx.payment.findUniqueOrThrow({ where: { id: r.id }, include }));
    });
  }

  async cancel(auth: AuthContext, id: string, meta: RequestMeta): Promise<PaymentDto> {
    const p = await this.find(auth, id, 'payment.create');
    if (p.status !== 'PENDING') throw businessRule('Отменить можно только неподтверждённую оплату');
    return this.prisma.$transaction(async (tx) => {
      const after = await tx.payment.update({
        where: { id },
        data: { status: 'CANCELLED' },
        include,
      });
      await this.audit.log(tx, {
        actorId: auth.userId,
        action: 'payment.cancel',
        entityType: 'payment',
        entityId: id,
        changes: { status: { old: 'PENDING', new: 'CANCELLED' } },
        meta,
      });
      return toDto(after);
    });
  }

  /** Сводка денег по сделке (ТЗ §27): договор, оплачено, возвраты, дебиторка, проект. */
  async dealMoney(auth: AuthContext, dealId: string): Promise<DealMoneyDto> {
    await this.access.deal(auth, dealId, 'payment.read');
    const [contracts, paid, refunds, project] = await Promise.all([
      this.prisma.contract.aggregate({
        where: { dealId, status: 'SIGNED' },
        _sum: { amountUzs: true },
      }),
      this.prisma.payment.aggregate({
        where: { dealId, status: 'PAID', type: { not: 'REFUND' } },
        _sum: { amountUzs: true },
      }),
      this.prisma.payment.aggregate({
        where: { dealId, status: 'PAID', type: 'REFUND' },
        _sum: { amountUzs: true },
      }),
      this.prisma.project.findUnique({ where: { dealId } }),
    ]);
    const c = contracts._sum.amountUzs ?? new Prisma.Decimal(0);
    const p = paid._sum.amountUzs ?? new Prisma.Decimal(0);
    const r = refunds._sum.amountUzs ?? new Prisma.Decimal(0);
    const receivable = Prisma.Decimal.max(0, c.sub(p).add(r));
    return {
      contractUzs: c.toFixed(2),
      paidUzs: p.toFixed(2),
      refundedUzs: r.toFixed(2),
      receivableUzs: receivable.toFixed(2),
      project: project
        ? {
            id: project.id,
            name: project.name,
            number: formatNumber('P', project.number),
            status: project.status,
          }
        : null,
    };
  }
}

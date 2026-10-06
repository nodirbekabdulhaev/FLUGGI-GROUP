import { Injectable } from '@nestjs/common';
import {
  DEAL_STAGE_CODES,
  type closeSchema,
  type createDealSchema,
  type DealDto,
  type DealListQuery,
  type DealStageCode,
  type Paginated,
  type updateDealSchema,
} from '@fluggi/contracts';
import type { Deal, Prisma } from '@fluggi/db';
import type { z } from 'zod';
import type { AuthContext, RequestMeta } from '../../core/auth/auth-context';
import { AuditService, diffFields } from '../../core/audit/audit.service';
import { businessRule, notFound } from '../../core/http/app.exception';
import { parseDate } from '../../core/http/serialize';
import { OutboxService } from '../../core/outbox/outbox.service';
import { PrismaService, type Tx } from '../../core/prisma/prisma.service';
import { ActivityService } from '../crm/activity.service';
import { CrmAccessService } from '../crm/crm-access.service';
import { ExchangeRateService } from '../references/exchange-rate.service';
import { dealInclude, toDealDto } from './deals.mapper';

const TRACKED: (keyof Deal)[] = [
  'title',
  'amount',
  'currency',
  'serviceId',
  'contactId',
  'expectedCloseDate',
  'probabilityOverride',
];

@Injectable()
export class DealsService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly access: CrmAccessService,
    private readonly activity: ActivityService,
    private readonly audit: AuditService,
    private readonly outbox: OutboxService,
    private readonly rates: ExchangeRateService,
  ) {}

  async list(
    auth: AuthContext,
    q: DealListQuery & { page: number; pageSize: number },
  ): Promise<Paginated<DealDto>> {
    const and: Prisma.DealWhereInput[] = [this.access.dealWhere(auth)];
    if (q.q) {
      and.push({
        OR: [
          { title: { contains: q.q, mode: 'insensitive' } },
          { client: { name: { contains: q.q, mode: 'insensitive' } } },
          ...(/^\d+$/.test(q.q.replace(/^D-/i, ''))
            ? [{ number: Number(q.q.replace(/^D-/i, '')) }]
            : []),
        ],
      });
    }
    if (q.stageCode) and.push({ stage: { code: q.stageCode } });
    if (q.status) and.push({ status: q.status });
    if (q.ownerId) and.push({ ownerId: q.ownerId });
    if (q.teamId) and.push({ teamId: q.teamId });
    if (q.clientId) and.push({ clientId: q.clientId });
    if (q.serviceId) and.push({ serviceId: q.serviceId });
    if (q.amountMin !== undefined) and.push({ amountUzs: { gte: q.amountMin } });
    if (q.amountMax !== undefined) and.push({ amountUzs: { lte: q.amountMax } });
    if (q.dateFrom) and.push({ createdAt: { gte: new Date(`${q.dateFrom}T00:00:00+05:00`) } });
    if (q.dateTo)
      and.push({
        createdAt: { lt: new Date(new Date(`${q.dateTo}T00:00:00+05:00`).getTime() + 86_400_000) },
      });
    const where = { AND: and };
    const [items, total] = await Promise.all([
      this.prisma.deal.findMany({
        where,
        include: dealInclude,
        orderBy: { createdAt: 'desc' },
        skip: (q.page - 1) * q.pageSize,
        take: q.pageSize,
      }),
      this.prisma.deal.count({ where }),
    ]);
    return { items: items.map(toDealDto), total, page: q.page, pageSize: q.pageSize };
  }

  async get(auth: AuthContext, id: string): Promise<DealDto> {
    await this.access.deal(auth, id);
    return toDealDto(
      await this.prisma.deal.findUniqueOrThrow({ where: { id }, include: dealInclude }),
    );
  }

  /** Новая сделка у существующего клиента (повторная продажа, ТЗ §63). */
  async create(
    auth: AuthContext,
    input: z.output<typeof createDealSchema>,
    meta: RequestMeta,
  ): Promise<DealDto> {
    const client = await this.access.client(auth, input.clientId);
    const owner = await this.access.assignableOwner(
      auth,
      input.ownerId ?? client.ownerId,
      'deal.create',
    );
    if (input.contactId) await this.assertContact(client.id, input.contactId);
    const { rate, amountUzs } = await this.rates.convert(input.amount, input.currency);
    const stage = await this.prisma.dealStage.findUniqueOrThrow({
      where: { code: 'NEED_DEFINED' },
    });
    const previous = await this.prisma.deal.count({
      where: { clientId: client.id, deletedAt: null },
    });

    return this.prisma.$transaction(async (tx) => {
      const deal = await tx.deal.create({
        data: {
          title: input.title,
          clientId: client.id,
          contactId: input.contactId,
          ownerId: owner.id,
          teamId: owner.teamId,
          serviceId: input.serviceId,
          amount: input.amount,
          currency: input.currency,
          exchangeRate: rate,
          amountUzs,
          stageId: stage.id,
          expectedCloseDate: parseDate(input.expectedCloseDate),
          isRepeat: input.isRepeat ?? previous > 0,
          createdById: auth.userId,
        },
      });
      await this.activity.stageChange(tx, { dealId: deal.id }, null, stage.id, auth.userId);
      await this.activity.log(tx, {
        type: 'deal.created',
        actorId: auth.userId,
        dealId: deal.id,
        clientId: client.id,
        payload: { amount: input.amount, currency: input.currency, client: client.name },
      });
      await this.audit.log(tx, {
        actorId: auth.userId,
        action: 'deal.create',
        entityType: 'deal',
        entityId: deal.id,
        changes: {
          amount: { old: null, new: input.amount },
          clientId: { old: null, new: client.id },
        },
        meta,
      });
      await this.outbox.publish(
        tx,
        'deal.created',
        { dealId: deal.id, ownerId: owner.id, teamId: owner.teamId, amountUzs },
        auth.userId,
      );
      return toDealDto(
        await tx.deal.findUniqueOrThrow({ where: { id: deal.id }, include: dealInclude }),
      );
    });
  }

  async update(
    auth: AuthContext,
    id: string,
    input: z.output<typeof updateDealSchema>,
    meta: RequestMeta,
  ): Promise<DealDto> {
    const before = await this.access.deal(auth, id, 'deal.update');
    if (input.contactId) await this.assertContact(before.clientId, input.contactId);
    const data: Prisma.DealUncheckedUpdateInput = {
      ...input,
      expectedCloseDate: parseDate(input.expectedCloseDate),
    };
    if (input.amount !== undefined || input.currency !== undefined) {
      const { rate, amountUzs } = await this.rates.convert(
        input.amount ?? before.amount.toString(),
        input.currency ?? before.currency,
      );
      data.exchangeRate = rate;
      data.amountUzs = amountUzs;
    }
    return this.prisma.$transaction(async (tx) => {
      const after = await tx.deal.update({ where: { id }, data });
      const changes = diffFields(before, after, TRACKED);
      if (changes) {
        // «Изменена сумма» — отдельное событие таймлайна (ТЗ §12)
        await this.activity.log(tx, {
          type: changes.amount || changes.currency ? 'deal.amount_changed' : 'deal.updated',
          actorId: auth.userId,
          dealId: id,
          payload: { changes },
        });
        await this.audit.log(tx, {
          actorId: auth.userId,
          action: 'deal.update',
          entityType: 'deal',
          entityId: id,
          changes,
          meta,
        });
      }
      return toDealDto(await tx.deal.findUniqueOrThrow({ where: { id }, include: dealInclude }));
    });
  }

  async assign(
    auth: AuthContext,
    id: string,
    ownerId: string,
    meta: RequestMeta,
  ): Promise<DealDto> {
    const before = await this.access.deal(auth, id, 'deal.update');
    // Передать сделку другому менеджеру может тот, кто распределяет лиды (РОП/CEO).
    const owner = await this.access.assignableOwner(auth, ownerId, 'lead.assign');
    return this.prisma.$transaction(async (tx) => {
      await tx.deal.update({ where: { id }, data: { ownerId: owner.id, teamId: owner.teamId } });
      await this.activity.log(tx, {
        type: 'deal.assigned',
        actorId: auth.userId,
        dealId: id,
        payload: { to: owner.fullName },
      });
      await this.audit.log(tx, {
        actorId: auth.userId,
        action: 'deal.assign',
        entityType: 'deal',
        entityId: id,
        changes: { ownerId: { old: before.ownerId, new: owner.id } },
        meta,
      });
      return toDealDto(await tx.deal.findUniqueOrThrow({ where: { id }, include: dealInclude }));
    });
  }

  /**
   * Переход по этапам сделки с проверкой документов (BUSINESS_RULES §3).
   * «Оплачено» ставится только подтверждением оплаты (ТЗ Rule 3). Назад — без проверок.
   */
  async changeStage(
    auth: AuthContext,
    id: string,
    code: DealStageCode,
    meta: RequestMeta,
  ): Promise<DealDto> {
    const deal = await this.access.deal(auth, id, 'deal.change_stage');
    return this.prisma.$transaction(async (tx) => {
      await this.moveStage(tx, deal, code, auth.userId, meta);
      return toDealDto(await tx.deal.findUniqueOrThrow({ where: { id }, include: dealInclude }));
    });
  }

  async moveStage(
    tx: Tx,
    deal: Deal,
    code: DealStageCode,
    actorId: string,
    meta?: RequestMeta,
    auto = false,
  ) {
    if (deal.status !== 'OPEN' && !(auto && code === 'PAID')) {
      throw businessRule('Сделка закрыта. Сначала верните её в работу');
    }
    if (code === 'PAID' && !auto) {
      throw businessRule('Этап «Оплачено» устанавливается автоматически при подтверждении оплаты');
    }
    const target = await tx.dealStage.findUnique({ where: { code } });
    if (!target) throw notFound('Этап');
    if (target.id === deal.stageId) return;
    const from = await tx.dealStage.findUniqueOrThrow({ where: { id: deal.stageId } });
    const forward =
      DEAL_STAGE_CODES.indexOf(code) > DEAL_STAGE_CODES.indexOf(from.code as DealStageCode);

    if (forward && !auto) await this.checkGate(tx, deal, code);

    await tx.deal.update({ where: { id: deal.id }, data: { stageId: target.id } });
    await this.activity.stageChange(tx, { dealId: deal.id }, from.id, target.id, actorId);
    await this.activity.log(tx, {
      type: 'deal.stage_changed',
      actorId,
      dealId: deal.id,
      payload: { from: from.nameRu, to: target.nameRu, auto },
    });
    if (meta) {
      await this.audit.log(tx, {
        actorId,
        action: 'deal.stage_change',
        entityType: 'deal',
        entityId: deal.id,
        changes: { stage: { old: from.code, new: target.code } },
        meta,
      });
    }
    await this.outbox.publish(
      tx,
      'deal.stage_changed',
      { dealId: deal.id, from: from.code, to: target.code },
      actorId,
    );
  }

  /** Двигает сделку вперёд (не назад) — для автоматических переходов от КП, договоров и оплат. */
  async advanceTo(tx: Tx, dealId: string, code: DealStageCode, actorId: string) {
    const deal = await tx.deal.findUniqueOrThrow({
      where: { id: dealId },
      include: { stage: true },
    });
    if (deal.status !== 'OPEN' && code !== 'PAID') return;
    if (
      DEAL_STAGE_CODES.indexOf(deal.stage.code as DealStageCode) >= DEAL_STAGE_CODES.indexOf(code)
    )
      return;
    await this.moveStage(tx, deal, code, actorId, undefined, true);
  }

  private async checkGate(tx: Tx, deal: Deal, code: DealStageCode) {
    const idx = DEAL_STAGE_CODES.indexOf(code);
    if (idx >= DEAL_STAGE_CODES.indexOf('PROPOSAL_SENT')) {
      if (deal.amount.lte(0)) {
        throw businessRule('Укажите сумму сделки', [
          { path: 'amount', message: 'Сумма должна быть больше нуля' },
        ]);
      }
      const sent = await tx.proposal.count({
        where: { dealId: deal.id, status: { in: ['SENT', 'VIEWED', 'ACCEPTED'] } },
      });
      if (!sent) throw businessRule('Сначала отправьте клиенту КП');
    }
    if (idx >= DEAL_STAGE_CODES.indexOf('CONTRACT')) {
      const contracts = await tx.contract.count({
        where: { dealId: deal.id, status: { not: 'CANCELLED' } },
      });
      if (!contracts) throw businessRule('Сначала создайте договор');
    }
    if (idx >= DEAL_STAGE_CODES.indexOf('AWAITING_PAYMENT')) {
      const signed = await tx.contract.count({ where: { dealId: deal.id, status: 'SIGNED' } });
      if (!signed) throw businessRule('Договор ещё не подписан');
    }
  }

  async close(
    auth: AuthContext,
    id: string,
    input: z.output<typeof closeSchema>,
    meta: RequestMeta,
  ): Promise<DealDto> {
    const deal = await this.access.deal(auth, id, 'deal.update');
    if (deal.status !== 'OPEN') throw businessRule('Сделка уже закрыта');
    const reason = input.lossReasonId
      ? await this.prisma.lossReason.findFirst({
          where: { id: input.lossReasonId, isActive: true },
        })
      : null;
    if (input.lossReasonId && !reason) throw businessRule('Причина не найдена');
    if (reason?.requiresComment && !input.comment) {
      throw businessRule('Для этой причины нужен комментарий', [
        { path: 'comment', message: 'Опишите причину' },
      ]);
    }
    return this.prisma.$transaction(async (tx) => {
      await tx.deal.update({
        where: { id },
        data: {
          status: input.status,
          lossReasonId: reason?.id ?? null,
          lossComment: input.comment ?? null,
          closedAt: new Date(),
        },
      });
      await this.activity.log(tx, {
        type: 'deal.closed',
        actorId: auth.userId,
        dealId: id,
        payload: {
          status: input.status,
          reason: reason?.nameRu ?? null,
          comment: input.comment ?? null,
        },
      });
      await this.audit.log(tx, {
        actorId: auth.userId,
        action: 'deal.close',
        entityType: 'deal',
        entityId: id,
        changes: {
          status: { old: deal.status, new: input.status },
          lossReason: { old: null, new: reason?.code ?? null },
        },
        meta,
      });
      if (input.status === 'LOST' || input.status === 'REJECTED') {
        await this.outbox.publish(
          tx,
          'deal.lost',
          {
            dealId: id,
            ownerId: deal.ownerId,
            teamId: deal.teamId,
            amountUzs: deal.amountUzs.toFixed(2),
            reason: reason?.nameRu ?? null,
          },
          auth.userId,
        );
      }
      return toDealDto(await tx.deal.findUniqueOrThrow({ where: { id }, include: dealInclude }));
    });
  }

  async reopen(auth: AuthContext, id: string, meta: RequestMeta): Promise<DealDto> {
    const deal = await this.access.deal(auth, id, 'deal.update');
    if (deal.status === 'OPEN') return this.get(auth, id);
    if (deal.status === 'WON') throw businessRule('Оплаченную сделку нельзя вернуть в работу');
    return this.prisma.$transaction(async (tx) => {
      await tx.deal.update({
        where: { id },
        data: { status: 'OPEN', lossReasonId: null, lossComment: null, closedAt: null },
      });
      await this.activity.log(tx, {
        type: 'deal.reopened',
        actorId: auth.userId,
        dealId: id,
        payload: { from: deal.status },
      });
      await this.audit.log(tx, {
        actorId: auth.userId,
        action: 'deal.reopen',
        entityType: 'deal',
        entityId: id,
        changes: { status: { old: deal.status, new: 'OPEN' } },
        meta,
      });
      return toDealDto(await tx.deal.findUniqueOrThrow({ where: { id }, include: dealInclude }));
    });
  }

  /** Сделки — критичные данные (ТЗ §66): только soft delete и только без оплат. */
  async remove(auth: AuthContext, id: string, meta: RequestMeta): Promise<void> {
    const deal = await this.access.deal(auth, id, 'deal.delete');
    if (deal.status === 'WON') throw businessRule('Оплаченную сделку удалить нельзя');
    await this.prisma.$transaction(async (tx) => {
      await tx.deal.update({ where: { id }, data: { deletedAt: new Date() } });
      await this.audit.log(tx, {
        actorId: auth.userId,
        action: 'deal.delete',
        entityType: 'deal',
        entityId: id,
        changes: { title: { old: deal.title, new: null } },
        meta,
      });
    });
  }

  private async assertContact(clientId: string, contactId: string) {
    const c = await this.prisma.contact.findFirst({ where: { id: contactId, clientId } });
    if (!c)
      throw businessRule('Контакт не принадлежит клиенту', [
        { path: 'contactId', message: 'Выберите контакт клиента' },
      ]);
  }
}

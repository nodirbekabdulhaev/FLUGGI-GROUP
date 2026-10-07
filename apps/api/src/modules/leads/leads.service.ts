import { Injectable } from '@nestjs/common';
import {
  LEAD_STAGE_CODES,
  type closeSchema,
  type convertLeadSchema,
  type createLeadSchema,
  type LeadDto,
  type LeadListQuery,
  type LeadStageCode,
  type Paginated,
  type updateLeadSchema,
} from '@fluggi/contracts';
import { computeLeadScore, toUzs } from '@fluggi/domain';
import type { Lead, Prisma } from '@fluggi/db';
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
import { leadInclude, toLeadDto } from './leads.mapper';

type CreateLead = z.output<typeof createLeadSchema>;
type UpdateLead = z.output<typeof updateLeadSchema>;

const OPEN_MEETING = ['SCHEDULED', 'CONFIRMED', 'RESCHEDULED'] as const;

/** Поля, изменения которых видны в таймлайне и аудите. */
const TRACKED: (keyof Lead)[] = [
  'title',
  'contactName',
  'companyName',
  'phone',
  'telegram',
  'email',
  'sourceId',
  'serviceId',
  'budget',
  'currency',
  'priority',
  'desiredDate',
  'nextContactAt',
  'companySize',
  'interest',
];

@Injectable()
export class LeadsService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly access: CrmAccessService,
    private readonly activity: ActivityService,
    private readonly audit: AuditService,
    private readonly outbox: OutboxService,
    private readonly rates: ExchangeRateService,
  ) {}

  // ─── чтение ───

  async list(
    auth: AuthContext,
    q: LeadListQuery & { page: number; pageSize: number },
  ): Promise<Paginated<LeadDto>> {
    const and: Prisma.LeadWhereInput[] = [this.access.leadWhere(auth)];
    if (q.q) {
      and.push({
        OR: ['title', 'contactName', 'companyName', 'phone', 'telegram', 'email'].map((f) => ({
          [f]: { contains: q.q, mode: 'insensitive' },
        })),
      });
    }
    if (q.stageCode) and.push({ stage: { code: q.stageCode } });
    if (q.status) and.push({ status: q.status });
    if (q.ownerId) and.push({ ownerId: q.ownerId });
    if (q.teamId) and.push({ teamId: q.teamId });
    if (q.sourceId) and.push({ sourceId: q.sourceId });
    if (q.serviceId) and.push({ serviceId: q.serviceId });
    if (q.scoreLevel) and.push({ scoreLevel: q.scoreLevel });
    if (q.dateFrom) and.push({ createdAt: { gte: new Date(`${q.dateFrom}T00:00:00+05:00`) } });
    if (q.dateTo)
      and.push({
        createdAt: { lt: new Date(new Date(`${q.dateTo}T00:00:00+05:00`).getTime() + 86_400_000) },
      });
    const where = { AND: and };
    const [items, total] = await Promise.all([
      this.prisma.lead.findMany({
        where,
        include: leadInclude,
        orderBy: [{ createdAt: 'desc' }],
        skip: (q.page - 1) * q.pageSize,
        take: q.pageSize,
      }),
      this.prisma.lead.count({ where }),
    ]);
    return { items: items.map(toLeadDto), total, page: q.page, pageSize: q.pageSize };
  }

  async get(auth: AuthContext, id: string): Promise<LeadDto> {
    await this.access.lead(auth, id);
    return toLeadDto(
      await this.prisma.lead.findUniqueOrThrow({ where: { id }, include: leadInclude }),
    );
  }

  // ─── создание и изменение ───

  async create(auth: AuthContext, input: CreateLead, meta: RequestMeta): Promise<LeadDto> {
    const owner = await this.access.assignableOwner(auth, input.ownerId, 'lead.create');
    const [service, source, stage] = await Promise.all([
      this.prisma.service.findFirst({ where: { id: input.serviceId, isActive: true } }),
      this.prisma.leadSource.findFirst({ where: { id: input.sourceId, isActive: true } }),
      this.stage('NEW'),
    ]);
    if (!service)
      throw businessRule('Услуга не найдена', [{ path: 'serviceId', message: 'Выберите услугу' }]);
    if (!source)
      throw businessRule('Источник не найден', [
        { path: 'sourceId', message: 'Выберите источник' },
      ]);

    const budgetUzs = input.budget
      ? (await this.rates.convert(input.budget, input.currency)).amountUzs
      : null;
    const title = input.title ?? `${input.companyName ?? input.contactName} — ${service.nameRu}`;

    return this.prisma.$transaction(async (tx) => {
      const lead = await tx.lead.create({
        data: {
          title,
          contactName: input.contactName,
          companyName: input.companyName,
          phone: input.phone,
          telegram: input.telegram,
          whatsapp: input.whatsapp,
          instagram: input.instagram,
          email: input.email,
          website: input.website,
          city: input.city,
          country: input.country,
          sourceId: source.id,
          serviceId: service.id,
          ownerId: owner.id,
          teamId: owner.teamId,
          budget: input.budget,
          currency: input.currency,
          budgetUzs,
          desiredDate: parseDate(input.desiredDate),
          priority: input.priority,
          companySize: input.companySize,
          interest: input.interest,
          nextContactAt: input.nextContactAt ? new Date(input.nextContactAt) : undefined,
          comment: input.comment,
          stageId: stage.id,
          createdById: auth.userId,
        },
      });
      await this.rescore(tx, lead.id);
      await this.activity.stageChange(tx, { leadId: lead.id }, null, stage.id, auth.userId);
      await this.activity.log(tx, {
        type: 'lead.created',
        actorId: auth.userId,
        leadId: lead.id,
        payload: { owner: owner.fullName, source: source.nameRu, service: service.nameRu },
      });
      await this.audit.log(tx, {
        actorId: auth.userId,
        action: 'lead.create',
        entityType: 'lead',
        entityId: lead.id,
        changes: { title: { old: null, new: title }, ownerId: { old: null, new: owner.id } },
        meta,
      });
      await this.outbox.publish(
        tx,
        'lead.created',
        {
          leadId: lead.id,
          ownerId: owner.id,
          teamId: owner.teamId,
          createdById: auth.userId,
          budgetUzs,
        },
        auth.userId,
      );
      return toLeadDto(
        await tx.lead.findUniqueOrThrow({ where: { id: lead.id }, include: leadInclude }),
      );
    });
  }

  /**
   * Лид из внешнего канала (форма сайта, Instagram, таргет): без пользователя-автора,
   * ответственный выбран заранее. Источник — по коду справочника.
   */
  async createInbound(input: {
    title: string;
    contactName?: string | null;
    companyName?: string | null;
    phone?: string | null;
    email?: string | null;
    instagram?: string | null;
    comment?: string | null;
    sourceCode: string;
    sourceId?: string | null;
    serviceId?: string | null;
    ownerId: string;
    channel: string;
  }): Promise<{ id: string; number: number; title: string }> {
    const [source, stage, owner] = await Promise.all([
      input.sourceId
        ? this.prisma.leadSource.findUnique({ where: { id: input.sourceId } })
        : this.prisma.leadSource.findUnique({ where: { code: input.sourceCode } }),
      this.stage('NEW'),
      this.prisma.user.findUniqueOrThrow({ where: { id: input.ownerId } }),
    ]);
    const src =
      source ?? (await this.prisma.leadSource.findFirstOrThrow({ where: { code: 'OTHER' } }));
    return this.prisma.$transaction(async (tx) => {
      const lead = await tx.lead.create({
        data: {
          title: input.title.slice(0, 200),
          contactName: input.contactName ?? null,
          companyName: input.companyName ?? null,
          phone: input.phone ?? null,
          email: input.email ?? null,
          instagram: input.instagram ?? null,
          comment: input.comment ?? null,
          sourceId: src.id,
          serviceId: input.serviceId ?? null,
          ownerId: owner.id,
          teamId: owner.teamId,
          stageId: stage.id,
          createdById: owner.id,
        },
      });
      await this.rescore(tx, lead.id);
      await this.activity.stageChange(tx, { leadId: lead.id }, null, stage.id, owner.id);
      await this.activity.log(tx, {
        type: 'lead.created',
        actorId: null,
        leadId: lead.id,
        payload: { owner: owner.fullName, source: src.nameRu, channel: input.channel },
      });
      await this.outbox.publish(
        tx,
        'lead.created',
        {
          leadId: lead.id,
          ownerId: owner.id,
          teamId: owner.teamId,
          createdById: owner.id,
          budgetUzs: null,
        },
        null,
      );
      return { id: lead.id, number: lead.number, title: lead.title };
    });
  }

  async update(
    auth: AuthContext,
    id: string,
    input: UpdateLead,
    meta: RequestMeta,
  ): Promise<LeadDto> {
    const before = await this.access.lead(auth, id, 'lead.update');
    const data: Prisma.LeadUncheckedUpdateInput = {
      ...input,
      desiredDate: parseDate(input.desiredDate),
      nextContactAt:
        input.nextContactAt === undefined
          ? undefined
          : input.nextContactAt
            ? new Date(input.nextContactAt)
            : null,
    };
    if (input.budget !== undefined || input.currency !== undefined) {
      const budget =
        input.budget === undefined ? (before.budget?.toString() ?? null) : input.budget;
      const currency = input.currency ?? before.currency;
      data.budgetUzs = budget ? (await this.rates.convert(budget, currency)).amountUzs : null;
    }
    if (input.sourceId) {
      const src = await this.prisma.leadSource.findUnique({ where: { id: input.sourceId } });
      if (!src) throw businessRule('Источник не найден');
    }

    return this.prisma.$transaction(async (tx) => {
      const after = await tx.lead.update({ where: { id }, data });
      await this.rescore(tx, id);
      const changes = diffFields(before, after, TRACKED);
      if (changes) {
        await this.activity.log(tx, {
          type: 'lead.updated',
          actorId: auth.userId,
          leadId: id,
          payload: { changes },
        });
        await this.audit.log(tx, {
          actorId: auth.userId,
          action: 'lead.update',
          entityType: 'lead',
          entityId: id,
          changes,
          meta,
        });
      }
      return toLeadDto(await tx.lead.findUniqueOrThrow({ where: { id }, include: leadInclude }));
    });
  }

  async assign(
    auth: AuthContext,
    id: string,
    ownerId: string,
    meta: RequestMeta,
  ): Promise<LeadDto> {
    const before = await this.access.lead(auth, id, 'lead.assign');
    const owner = await this.access.assignableOwner(auth, ownerId, 'lead.assign');
    if (before.ownerId === owner.id) return this.get(auth, id);
    return this.prisma.$transaction(async (tx) => {
      await tx.lead.update({ where: { id }, data: { ownerId: owner.id, teamId: owner.teamId } });
      await this.activity.log(tx, {
        type: 'lead.assigned',
        actorId: auth.userId,
        leadId: id,
        payload: { to: owner.fullName },
      });
      await this.audit.log(tx, {
        actorId: auth.userId,
        action: 'lead.assign',
        entityType: 'lead',
        entityId: id,
        changes: { ownerId: { old: before.ownerId, new: owner.id } },
        meta,
      });
      await this.outbox.publish(
        tx,
        'lead.assigned',
        { leadId: id, ownerId: owner.id, previousOwnerId: before.ownerId },
        auth.userId,
      );
      return toLeadDto(await tx.lead.findUniqueOrThrow({ where: { id }, include: leadInclude }));
    });
  }

  // ─── воронка ───

  async changeStage(
    auth: AuthContext,
    id: string,
    code: LeadStageCode,
    meta: RequestMeta,
  ): Promise<LeadDto> {
    const lead = await this.access.lead(auth, id, 'lead.update');
    return this.prisma.$transaction(async (tx) => {
      await this.moveStage(tx, lead, code, auth.userId, meta);
      return toLeadDto(await tx.lead.findUniqueOrThrow({ where: { id }, include: leadInclude }));
    });
  }

  /**
   * Переход лида по этапам с проверкой обязательных данных (BUSINESS_RULES §3).
   * Используется и встречами: назначение/проведение встречи двигает лид автоматически.
   */
  async moveStage(
    tx: Tx,
    lead: Lead,
    code: LeadStageCode,
    actorId: string,
    meta?: RequestMeta,
    auto = false,
  ) {
    if (lead.status !== 'OPEN') throw businessRule('Лид закрыт. Сначала верните его в работу');
    const target = await this.stage(code, tx);
    if (target.id === lead.stageId) return;

    if (code === 'MEETING_SCHEDULED') {
      const has = await tx.meeting.count({
        where: { leadId: lead.id, status: { in: [...OPEN_MEETING] } },
      });
      if (!has) throw businessRule('Сначала назначьте встречу с клиентом');
    }
    if (code === 'MEETING_DONE') {
      const has = await tx.meeting.count({ where: { leadId: lead.id, status: 'DONE' } });
      if (!has) throw businessRule('Отметьте встречу проведённой и укажите результат');
    }

    const from = await tx.dealStage.findUniqueOrThrow({ where: { id: lead.stageId } });
    await tx.lead.update({
      where: { id: lead.id },
      data: { stageId: target.id, ...(code === 'CONTACTED' ? { lastContactAt: new Date() } : {}) },
    });
    await this.rescore(tx, lead.id);
    await this.activity.stageChange(tx, { leadId: lead.id }, from.id, target.id, actorId);
    await this.activity.log(tx, {
      type: 'lead.stage_changed',
      actorId,
      leadId: lead.id,
      payload: { from: from.nameRu, to: target.nameRu, auto },
    });
    if (meta) {
      await this.audit.log(tx, {
        actorId,
        action: 'lead.stage_change',
        entityType: 'lead',
        entityId: lead.id,
        changes: { stage: { old: from.code, new: target.code } },
        meta,
      });
    }
  }

  /** Двигает лид вперёд (не назад) — для автоматических переходов от встреч. */
  async advanceTo(tx: Tx, leadId: string, code: LeadStageCode, actorId: string) {
    const lead = await tx.lead.findUniqueOrThrow({
      where: { id: leadId },
      include: { stage: true },
    });
    if (lead.status !== 'OPEN') return;
    const current = LEAD_STAGE_CODES.indexOf(lead.stage.code as LeadStageCode);
    if (current >= LEAD_STAGE_CODES.indexOf(code)) return;
    await this.moveStage(tx, lead, code, actorId, undefined, true);
  }

  async close(
    auth: AuthContext,
    id: string,
    input: z.output<typeof closeSchema>,
    meta: RequestMeta,
  ): Promise<LeadDto> {
    const lead = await this.access.lead(auth, id, 'lead.update');
    if (lead.status !== 'OPEN') throw businessRule('Лид уже закрыт');
    const reason = await this.checkReason(input);
    return this.prisma.$transaction(async (tx) => {
      await tx.lead.update({
        where: { id },
        data: {
          status: input.status,
          lossReasonId: reason?.id ?? null,
          lossComment: input.comment ?? null,
          closedAt: new Date(),
        },
      });
      await this.activity.log(tx, {
        type: 'lead.closed',
        actorId: auth.userId,
        leadId: id,
        payload: {
          status: input.status,
          reason: reason?.nameRu ?? null,
          comment: input.comment ?? null,
        },
      });
      await this.audit.log(tx, {
        actorId: auth.userId,
        action: 'lead.close',
        entityType: 'lead',
        entityId: id,
        changes: {
          status: { old: lead.status, new: input.status },
          lossReason: { old: null, new: reason?.code ?? null },
        },
        meta,
      });
      await this.outbox.publish(
        tx,
        'lead.closed',
        { leadId: id, status: input.status },
        auth.userId,
      );
      return toLeadDto(await tx.lead.findUniqueOrThrow({ where: { id }, include: leadInclude }));
    });
  }

  async reopen(auth: AuthContext, id: string, meta: RequestMeta): Promise<LeadDto> {
    const lead = await this.access.lead(auth, id, 'lead.update');
    if (lead.status === 'OPEN') return this.get(auth, id);
    if (lead.status === 'CONVERTED') throw businessRule('Лид уже переведён в сделку');
    return this.prisma.$transaction(async (tx) => {
      await tx.lead.update({
        where: { id },
        data: { status: 'OPEN', lossReasonId: null, lossComment: null, closedAt: null },
      });
      await this.activity.log(tx, {
        type: 'lead.reopened',
        actorId: auth.userId,
        leadId: id,
        payload: { from: lead.status },
      });
      await this.audit.log(tx, {
        actorId: auth.userId,
        action: 'lead.reopen',
        entityType: 'lead',
        entityId: id,
        changes: { status: { old: lead.status, new: 'OPEN' } },
        meta,
      });
      return toLeadDto(await tx.lead.findUniqueOrThrow({ where: { id }, include: leadInclude }));
    });
  }

  /** Квалификация: лид → клиент (новый или существующий) + сделка (BUSINESS_RULES §2). */
  async convert(
    auth: AuthContext,
    id: string,
    input: z.output<typeof convertLeadSchema>,
    meta: RequestMeta,
  ): Promise<{ leadId: string; clientId: string; dealId: string }> {
    const lead = await this.access.lead(auth, id, 'lead.update');
    if (!auth.permissions['deal.create']) throw businessRule('Нет права создавать сделки');
    if (lead.status !== 'OPEN') throw businessRule('Конвертировать можно только лид в работе');
    const existingClient = input.clientId ? await this.access.client(auth, input.clientId) : null;
    const { rate, amountUzs } = await this.rates.convert(input.amount, input.currency);
    const dealStage = await this.stage('NEED_DEFINED');
    const service = lead.serviceId
      ? await this.prisma.service.findUnique({ where: { id: lead.serviceId } })
      : null;

    return this.prisma.$transaction(async (tx) => {
      const client =
        existingClient ??
        (await tx.client.create({
          data: {
            name: input.clientName!,
            type: input.clientType,
            phone: lead.phone,
            email: lead.email,
            telegram: lead.telegram,
            website: lead.website,
            city: lead.city,
            country: lead.country,
            ownerId: lead.ownerId,
            teamId: lead.teamId,
            sourceId: lead.sourceId,
            contacts: lead.contactName
              ? {
                  create: {
                    fullName: lead.contactName,
                    phone: lead.phone,
                    telegram: lead.telegram,
                    whatsapp: lead.whatsapp,
                    instagram: lead.instagram,
                    email: lead.email,
                    isPrimary: true,
                  },
                }
              : undefined,
          },
          include: { contacts: true },
        }));
      const contactId =
        'contacts' in client ? (client.contacts as { id: string }[])[0]?.id : undefined;
      const isRepeat = existingClient
        ? (await tx.deal.count({ where: { clientId: client.id } })) > 0
        : false;

      const deal = await tx.deal.create({
        data: {
          title: input.title ?? `${service?.nameRu ?? lead.title} — ${client.name}`,
          clientId: client.id,
          contactId,
          ownerId: lead.ownerId,
          teamId: lead.teamId,
          serviceId: lead.serviceId,
          amount: input.amount,
          currency: input.currency,
          exchangeRate: rate,
          amountUzs,
          stageId: dealStage.id,
          expectedCloseDate: parseDate(input.expectedCloseDate),
          isRepeat,
          createdById: auth.userId,
        },
      });
      await tx.lead.update({
        where: { id },
        data: {
          status: 'CONVERTED',
          clientId: client.id,
          dealId: deal.id,
          convertedAt: new Date(),
          closedAt: new Date(),
        },
      });
      await this.activity.stageChange(tx, { dealId: deal.id }, null, dealStage.id, auth.userId);
      await this.activity.log(tx, {
        type: 'lead.converted',
        actorId: auth.userId,
        leadId: id,
        dealId: deal.id,
        clientId: client.id,
        payload: {
          client: client.name,
          amount: input.amount,
          currency: input.currency,
          newClient: !existingClient,
        },
      });
      await this.audit.log(tx, {
        actorId: auth.userId,
        action: 'lead.convert',
        entityType: 'lead',
        entityId: id,
        changes: {
          status: { old: 'OPEN', new: 'CONVERTED' },
          dealId: { old: null, new: deal.id },
          clientId: { old: null, new: client.id },
        },
        meta,
      });
      await this.outbox.publish(
        tx,
        'lead.converted',
        { leadId: id, dealId: deal.id, clientId: client.id },
        auth.userId,
      );
      await this.outbox.publish(
        tx,
        'deal.created',
        { dealId: deal.id, ownerId: deal.ownerId, teamId: deal.teamId, amountUzs },
        auth.userId,
      );
      return { leadId: id, clientId: client.id, dealId: deal.id };
    });
  }

  async remove(auth: AuthContext, id: string, meta: RequestMeta): Promise<void> {
    const lead = await this.access.lead(auth, id, 'lead.delete');
    if (lead.status === 'CONVERTED') throw businessRule('Лид переведён в сделку — удалить нельзя');
    await this.prisma.$transaction(async (tx) => {
      await tx.lead.update({ where: { id }, data: { deletedAt: new Date() } });
      await this.audit.log(tx, {
        actorId: auth.userId,
        action: 'lead.delete',
        entityType: 'lead',
        entityId: id,
        changes: { title: { old: lead.title, new: null } },
        meta,
      });
    });
  }

  // ─── вспомогательное ───

  private async stage(code: string, tx: Tx = this.prisma) {
    const stage = await tx.dealStage.findUnique({ where: { code } });
    if (!stage) throw notFound('Этап воронки');
    return stage;
  }

  private async checkReason(input: z.output<typeof closeSchema>) {
    if (!input.lossReasonId) return null;
    const reason = await this.prisma.lossReason.findFirst({
      where: { id: input.lossReasonId, isActive: true },
    });
    if (!reason)
      throw businessRule('Причина не найдена', [
        { path: 'lossReasonId', message: 'Выберите причину' },
      ]);
    if (reason.requiresComment && !input.comment) {
      throw businessRule('Для этой причины нужен комментарий', [
        { path: 'comment', message: 'Опишите причину' },
      ]);
    }
    return reason;
  }

  /** Пересчёт lead score (ТЗ §10) после любых изменений, влияющих на факторы. */
  private async rescore(tx: Tx, id: string) {
    const lead = await tx.lead.findUniqueOrThrow({
      where: { id },
      include: { stage: true, service: true },
    });
    let minPriceUzs: number | null = null;
    if (lead.service?.minPrice) {
      const rate =
        lead.service.currency === 'USD'
          ? (
              await tx.exchangeRate.findFirst({
                where: { currency: 'USD' },
                orderBy: { date: 'desc' },
              })
            )?.rateToUzs
          : 1;
      if (rate)
        minPriceUzs = toUzs(
          lead.service.minPrice.toString(),
          lead.service.currency,
          rate.toString(),
        ).toNumber();
    }
    const days = lead.desiredDate
      ? Math.round((lead.desiredDate.getTime() - Date.now()) / 86_400_000)
      : null;
    const { score, level } = computeLeadScore({
      budgetUzs: lead.budgetUzs ? Number(lead.budgetUzs) : null,
      serviceMinPriceUzs: minPriceUzs,
      hasService: Boolean(lead.serviceId),
      priority: lead.priority,
      daysToDesiredDate: days,
      companySize: lead.companySize,
      interest: lead.interest,
      stageIndex: Math.max(0, LEAD_STAGE_CODES.indexOf(lead.stage.code as LeadStageCode)),
      stageCount: LEAD_STAGE_CODES.length,
    });
    if (score !== lead.score || level !== lead.scoreLevel) {
      await tx.lead.update({ where: { id }, data: { score, scoreLevel: level } });
    }
  }
}

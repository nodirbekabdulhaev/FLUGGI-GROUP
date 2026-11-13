import { Injectable } from '@nestjs/common';
import {
  formatNumber,
  type completeMeetingSchema,
  type createMeetingSchema,
  type MeetingDto,
  type MeetingListQuery,
  type Paginated,
  type updateMeetingSchema,
} from '@fluggi/contracts';
import type { Prisma } from '@fluggi/db';
import type { z } from 'zod';
import type { AuthContext, RequestMeta } from '../../core/auth/auth-context';
import { AuditService, diffFields } from '../../core/audit/audit.service';
import { businessRule, notFound } from '../../core/http/app.exception';
import { OutboxService } from '../../core/outbox/outbox.service';
import { PrismaService } from '../../core/prisma/prisma.service';
import { ActivityService } from '../crm/activity.service';
import { CrmAccessService } from '../crm/crm-access.service';
import { LeadsService } from '../leads/leads.service';

const meetingInclude = {
  lead: { select: { id: true, number: true, title: true } },
  deal: { select: { id: true, number: true, title: true } },
  client: { select: { id: true, name: true } },
  manager: { select: { id: true, fullName: true } },
  rop: { select: { id: true, fullName: true } },
} satisfies Prisma.MeetingInclude;

type MeetingRow = Prisma.MeetingGetPayload<{ include: typeof meetingInclude }>;

const toDto = (m: MeetingRow): MeetingDto => ({
  id: m.id,
  lead: m.lead
    ? { id: m.lead.id, name: m.lead.title, number: formatNumber('L', m.lead.number) }
    : null,
  deal: m.deal
    ? { id: m.deal.id, name: m.deal.title, number: formatNumber('D', m.deal.number) }
    : null,
  client: m.client,
  manager: { id: m.manager.id, name: m.manager.fullName },
  rop: m.rop ? { id: m.rop.id, name: m.rop.fullName } : null,
  startsAt: m.startsAt.toISOString(),
  durationMin: m.durationMin,
  type: m.type,
  link: m.link,
  status: m.status,
  comment: m.comment,
  result: m.result,
  createdAt: m.createdAt.toISOString(),
});

@Injectable()
export class MeetingsService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly access: CrmAccessService,
    private readonly activity: ActivityService,
    private readonly audit: AuditService,
    private readonly outbox: OutboxService,
    private readonly leads: LeadsService,
  ) {}

  async list(
    auth: AuthContext,
    q: MeetingListQuery & { page: number; pageSize: number },
  ): Promise<Paginated<MeetingDto>> {
    const and: Prisma.MeetingWhereInput[] = [this.access.meetingWhere(auth)];
    if (q.status) and.push({ status: q.status });
    if (q.managerId) and.push({ managerId: q.managerId });
    if (q.leadId) and.push({ leadId: q.leadId });
    if (q.dealId) and.push({ dealId: q.dealId });
    if (q.dateFrom) and.push({ startsAt: { gte: new Date(`${q.dateFrom}T00:00:00+05:00`) } });
    if (q.dateTo)
      and.push({
        startsAt: { lt: new Date(new Date(`${q.dateTo}T00:00:00+05:00`).getTime() + 86_400_000) },
      });
    const where = { AND: and };
    const [items, total] = await Promise.all([
      this.prisma.meeting.findMany({
        where,
        include: meetingInclude,
        orderBy: { startsAt: q.status === 'DONE' ? 'desc' : 'asc' },
        skip: (q.page - 1) * q.pageSize,
        take: q.pageSize,
      }),
      this.prisma.meeting.count({ where }),
    ]);
    return { items: items.map(toDto), total, page: q.page, pageSize: q.pageSize };
  }

  private async find(
    auth: AuthContext,
    id: string,
    code: 'meeting.read' | 'meeting.update' = 'meeting.read',
  ) {
    const m = await this.prisma.meeting.findFirst({
      where: { AND: [this.access.meetingWhere(auth, code), { id }] },
    });
    if (!m) throw notFound('Встреча');
    return m;
  }

  /**
   * Встреча по лиду или сделке. Менеджер — ответственный за запись, РОП — руководитель его отдела.
   * Назначение встречи по лиду автоматически переводит лид на этап «Назначена встреча».
   */
  async create(
    auth: AuthContext,
    input: z.output<typeof createMeetingSchema>,
    meta: RequestMeta,
  ): Promise<MeetingDto> {
    const lead = input.leadId ? await this.access.lead(auth, input.leadId) : null;
    const deal = input.dealId ? await this.access.deal(auth, input.dealId) : null;
    const record = (lead ?? deal)!;
    if (record.status !== 'OPEN') throw businessRule('Нельзя назначить встречу по закрытой записи');
    const team = record.teamId
      ? await this.prisma.team.findUnique({ where: { id: record.teamId } })
      : null;

    return this.prisma.$transaction(async (tx) => {
      const m = await tx.meeting.create({
        data: {
          leadId: lead?.id,
          dealId: deal?.id,
          clientId: deal?.clientId ?? lead?.clientId ?? null,
          managerId: record.ownerId,
          ropId: team?.headId ?? null,
          teamId: record.teamId,
          startsAt: new Date(input.startsAt),
          durationMin: input.durationMin,
          type: input.type,
          link: input.link,
          comment: input.comment,
          createdById: auth.userId,
        },
        include: meetingInclude,
      });
      await this.activity.log(tx, {
        type: 'meeting.created',
        actorId: auth.userId,
        leadId: lead?.id,
        dealId: deal?.id,
        meetingId: m.id,
        payload: { startsAt: m.startsAt.toISOString(), type: m.type },
      });
      if (lead) await this.leads.advanceTo(tx, lead.id, 'MEETING_SCHEDULED', auth.userId);
      await this.audit.log(tx, {
        actorId: auth.userId,
        action: 'meeting.create',
        entityType: 'meeting',
        entityId: m.id,
        changes: { startsAt: { old: null, new: m.startsAt.toISOString() } },
        meta,
      });
      await this.outbox.publish(
        tx,
        'meeting.created',
        {
          meetingId: m.id,
          managerId: m.managerId,
          ropId: m.ropId,
          startsAt: m.startsAt.toISOString(),
        },
        auth.userId,
      );
      return toDto(m);
    });
  }

  async update(
    auth: AuthContext,
    id: string,
    input: z.output<typeof updateMeetingSchema>,
    meta: RequestMeta,
  ): Promise<MeetingDto> {
    const before = await this.find(auth, id, 'meeting.update');
    if (before.status === 'DONE') throw businessRule('Проведённую встречу изменить нельзя');
    const startsAt = input.startsAt ? new Date(input.startsAt) : undefined;
    const status =
      input.status ??
      (startsAt && startsAt.getTime() !== before.startsAt.getTime() ? 'RESCHEDULED' : undefined);
    return this.prisma.$transaction(async (tx) => {
      const after = await tx.meeting.update({
        where: { id },
        data: { ...input, startsAt, status },
        include: meetingInclude,
      });
      const changes = diffFields(before, after, [
        'startsAt',
        'durationMin',
        'type',
        'link',
        'status',
        'comment',
      ]);
      if (changes) {
        await this.activity.log(tx, {
          type: 'meeting.updated',
          actorId: auth.userId,
          leadId: after.leadId,
          dealId: after.dealId,
          meetingId: id,
          payload: { changes },
        });
        await this.audit.log(tx, {
          actorId: auth.userId,
          action: 'meeting.update',
          entityType: 'meeting',
          entityId: id,
          changes,
          meta,
        });
      }
      return toDto(after);
    });
  }

  /** «Встреча проведена» + результат; лид переходит на этап «Встреча проведена». */
  async complete(
    auth: AuthContext,
    id: string,
    input: z.output<typeof completeMeetingSchema>,
    meta: RequestMeta,
  ): Promise<MeetingDto> {
    const before = await this.find(auth, id, 'meeting.update');
    if (before.status === 'DONE') throw businessRule('Встреча уже отмечена проведённой');
    if (before.status === 'CANCELLED') throw businessRule('Встреча отменена');
    return this.prisma.$transaction(async (tx) => {
      const m = await tx.meeting.update({
        where: { id },
        data: { status: 'DONE', result: input.result },
        include: meetingInclude,
      });
      await this.activity.log(tx, {
        type: 'meeting.completed',
        actorId: auth.userId,
        leadId: m.leadId,
        dealId: m.dealId,
        meetingId: id,
        payload: { result: input.result.slice(0, 500) },
      });
      if (m.leadId) {
        await tx.lead.update({ where: { id: m.leadId }, data: { lastContactAt: new Date() } });
        await this.leads.advanceTo(tx, m.leadId, 'MEETING_DONE', auth.userId);
      }
      await this.audit.log(tx, {
        actorId: auth.userId,
        action: 'meeting.complete',
        entityType: 'meeting',
        entityId: id,
        changes: { status: { old: before.status, new: 'DONE' } },
        meta,
      });
      await this.outbox.publish(
        tx,
        'meeting.completed',
        { meetingId: id, managerId: m.managerId, ropId: m.ropId },
        auth.userId,
      );
      return toDto(m);
    });
  }
}

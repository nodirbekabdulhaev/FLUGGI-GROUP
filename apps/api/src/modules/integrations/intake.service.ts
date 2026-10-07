import { Injectable } from '@nestjs/common';
import { integrationSettingsSchema, type IntegrationSettings } from '@fluggi/contracts';
import type { Prisma } from '@fluggi/db';
import { PrismaService } from '../../core/prisma/prisma.service';
import { ActivityService } from '../crm/activity.service';
import { LeadsService } from '../leads/leads.service';
import { NotificationsService } from '../notifications/notifications.service';

const SETTINGS_KEY = 'integrations';

/** Телефон для сравнения: последние 9 цифр (+998 90 123-45-67 → 901234567). */
export const phoneKey = (v: string | null | undefined) => {
  const d = (v ?? '').replace(/\D/g, '');
  return d.length >= 7 ? d.slice(-9) : null;
};

/** Instagram для сравнения: без @, ссылки и регистра. */
export const igKey = (v: string | null | undefined) =>
  (v ?? '')
    .trim()
    .replace(/^https?:\/\/(www\.)?instagram\.com\//i, '')
    .replace(/^@/, '')
    .replace(/\/.*$/, '')
    .toLowerCase() || null;

export interface InboundLead {
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
  /** Куда направить: конкретный сотрудник или отдел (по очереди) */
  ownerId?: string | null;
  teamId?: string | null;
  channel: string;
}

/**
 * Приём заявок из внешних каналов (форма сайта, Instagram, таргет):
 * поиск дубля среди открытых лидов, выбор ответственного, создание лида.
 */
@Injectable()
export class IntakeService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly leads: LeadsService,
    private readonly activity: ActivityService,
    private readonly notifications: NotificationsService,
  ) {}

  async settings(): Promise<IntegrationSettings> {
    const row = await this.prisma.setting.findUnique({ where: { key: SETTINGS_KEY } });
    const parsed = integrationSettingsSchema.safeParse(row?.value ?? {});
    return parsed.success ? parsed.data : integrationSettingsSchema.parse({});
  }

  async saveSettings(value: IntegrationSettings, userId: string) {
    const json = value as unknown as Prisma.InputJsonValue;
    await this.prisma.setting.upsert({
      where: { key: SETTINGS_KEY },
      update: { value: json, updatedById: userId },
      create: { key: SETTINGS_KEY, value: json, updatedById: userId },
    });
    return this.settings();
  }

  /**
   * Ответственный: указанный сотрудник (если активен), иначе менеджер отдела
   * с наименьшим числом открытых лидов, иначе РОП отдела, иначе CEO.
   */
  async pickOwner(ownerId?: string | null, teamId?: string | null): Promise<string> {
    if (ownerId) {
      const u = await this.prisma.user.findFirst({
        where: { id: ownerId, status: 'ACTIVE', deletedAt: null },
      });
      if (u) return u.id;
    }
    const managers = await this.prisma.user.findMany({
      where: {
        status: 'ACTIVE',
        deletedAt: null,
        role: { code: 'MANAGER' },
        ...(teamId ? { teamId } : {}),
      },
      select: {
        id: true,
        _count: { select: { ownedLeads: { where: { status: 'OPEN', deletedAt: null } } } },
      },
    });
    if (managers.length) {
      managers.sort(
        (a, b) => a._count.ownedLeads - b._count.ownedLeads || a.id.localeCompare(b.id),
      );
      return managers[0]!.id;
    }
    if (teamId) {
      const team = await this.prisma.team.findUnique({ where: { id: teamId } });
      if (team?.headId) return team.headId;
    }
    const ceo = await this.prisma.user.findFirstOrThrow({
      where: { status: 'ACTIVE', deletedAt: null, role: { code: 'CEO' } },
      orderBy: { createdAt: 'asc' },
    });
    return ceo.id;
  }

  /** Открытый лид с тем же телефоном или Instagram. */
  async findDuplicate(phone?: string | null, instagram?: string | null) {
    const p = phoneKey(phone);
    const ig = igKey(instagram);
    if (!p && !ig) return null;
    // Сравнение в БД по цифрам: «+998 90 123-45-67» и «901234567» — один номер
    const ids = await this.prisma.$queryRaw<{ id: string }[]>`
      SELECT "id" FROM "leads"
      WHERE "status" = 'OPEN' AND "deleted_at" IS NULL AND (
        (${p}::text IS NOT NULL AND right(regexp_replace(coalesce("phone", ''), '[^0-9]', '', 'g'), 9) = ${p})
        OR (${ig}::text IS NOT NULL AND lower(regexp_replace(coalesce("instagram", ''), '^@', '')) = ${ig})
      )
      ORDER BY "created_at" DESC
      LIMIT 20`;
    if (!ids.length) return null;
    const candidates = await this.prisma.lead.findMany({
      where: { id: { in: ids.map((r) => r.id) } },
      orderBy: { createdAt: 'desc' },
    });
    return (
      candidates.find((l) => (p && phoneKey(l.phone) === p) || (ig && igKey(l.instagram) === ig)) ??
      null
    );
  }

  /** Новый лид или отметка «повторная заявка» в найденном. */
  async intake(input: InboundLead): Promise<{
    leadId: string;
    duplicate: boolean;
  }> {
    const dup = await this.findDuplicate(input.phone, input.instagram);
    if (dup) {
      await this.prisma.$transaction((tx) =>
        this.activity.log(tx, {
          type: 'lead.inbound_repeat',
          actorId: null,
          leadId: dup.id,
          payload: { channel: input.channel, text: input.comment?.slice(0, 1000) ?? null },
        }),
      );
      await this.notifications.notify(
        [dup.ownerId],
        {
          type: 'lead.inbound_repeat',
          title: 'Повторная заявка',
          body: `${dup.title}: ${input.channel}${input.comment ? ` — ${input.comment.slice(0, 200)}` : ''}`,
          link: `/sales/leads/${dup.id}`,
        },
        null,
      );
      return { leadId: dup.id, duplicate: true };
    }
    const ownerId = await this.pickOwner(input.ownerId, input.teamId);
    const lead = await this.leads.createInbound({ ...input, ownerId });
    return { leadId: lead.id, duplicate: false };
  }
}

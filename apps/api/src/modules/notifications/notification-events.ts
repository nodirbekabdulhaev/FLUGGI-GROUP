import { Injectable, OnModuleInit } from '@nestjs/common';
import { formatNumber } from '@fluggi/contracts';
import { OutboxDispatcher } from '../../core/outbox/outbox.dispatcher';
import { PrismaService } from '../../core/prisma/prisma.service';
import { NotificationsService } from './notifications.service';

/** Порог «крупного» лида/сделки, UZS. Станет настройкой в Phase 7 вместе с Telegram. */
export const LARGE_AMOUNT_UZS = 50_000_000;

const fmt = (uzs: string | number) => `${Math.round(Number(uzs)).toLocaleString('ru-RU')} UZS`;

/**
 * Подписчики событий → in-app уведомления (ТЗ §14). Telegram-канал подключится в Phase 7
 * к этим же событиям.
 */
@Injectable()
export class NotificationEvents implements OnModuleInit {
  constructor(
    private readonly dispatcher: OutboxDispatcher,
    private readonly notifications: NotificationsService,
    private readonly prisma: PrismaService,
  ) {}

  private async teamHead(teamId: string | null) {
    if (!teamId) return null;
    return (await this.prisma.team.findUnique({ where: { id: teamId } }))?.headId ?? null;
  }

  private async ceoIds() {
    const ceos = await this.prisma.user.findMany({
      where: { role: { code: 'CEO' }, status: 'ACTIVE', deletedAt: null },
    });
    return ceos.map((u) => u.id);
  }

  onModuleInit() {
    this.dispatcher.on('lead.created', async (e, meta) => {
      const lead = await this.prisma.lead.findUnique({ where: { id: e.leadId } });
      if (!lead) return;
      const link = `/sales/leads/${lead.id}`;
      await this.notifications.notify(
        [e.ownerId],
        { type: 'lead.created', title: 'Новый лид', body: lead.title, link },
        meta.actorId,
      );
      if (e.budgetUzs && Number(e.budgetUzs) >= LARGE_AMOUNT_UZS) {
        await this.notifications.notify(
          [await this.teamHead(e.teamId), ...(await this.ceoIds())],
          {
            type: 'lead.large',
            title: 'Крупный лид',
            body: `${lead.title} — ${fmt(e.budgetUzs)}`,
            link,
          },
          meta.actorId,
        );
      }
    });

    this.dispatcher.on('lead.assigned', async (e, meta) => {
      const lead = await this.prisma.lead.findUnique({ where: { id: e.leadId } });
      if (!lead) return;
      await this.notifications.notify(
        [e.ownerId],
        {
          type: 'lead.assigned',
          title: 'Вам назначен лид',
          body: lead.title,
          link: `/sales/leads/${lead.id}`,
        },
        meta.actorId,
      );
    });

    this.dispatcher.on('deal.created', async (e, meta) => {
      const deal = await this.prisma.deal.findUnique({ where: { id: e.dealId } });
      if (!deal) return;
      const large = Number(e.amountUzs) >= LARGE_AMOUNT_UZS;
      await this.notifications.notify(
        [await this.teamHead(e.teamId), ...(large ? await this.ceoIds() : [])],
        {
          type: large ? 'deal.large' : 'deal.created',
          title: large ? 'Крупная сделка' : 'Новая сделка',
          body: `${formatNumber('D', deal.number)} ${deal.title} — ${fmt(e.amountUzs)}`,
          link: `/sales/deals/${deal.id}`,
        },
        meta.actorId,
      );
    });

    this.dispatcher.on('deal.lost', async (e, meta) => {
      if (Number(e.amountUzs) < LARGE_AMOUNT_UZS) return;
      const deal = await this.prisma.deal.findUnique({
        where: { id: e.dealId },
        include: { client: true },
      });
      if (!deal) return;
      await this.notifications.notify(
        [await this.teamHead(e.teamId), ...(await this.ceoIds())],
        {
          type: 'deal.large_lost',
          title: 'Потеря крупного клиента',
          body: `${deal.client.name}: ${fmt(e.amountUzs)}${e.reason ? ` — ${e.reason}` : ''}`,
          link: `/sales/deals/${deal.id}`,
        },
        meta.actorId,
      );
    });

    this.dispatcher.on('proposal.approval_requested', async (e, meta) => {
      const p = await this.prisma.proposal.findUnique({ where: { id: e.proposalId } });
      if (!p) return;
      await this.notifications.notify(
        [await this.teamHead(e.teamId)],
        {
          type: 'proposal.approval',
          title: 'КП на согласование',
          body: `${p.title} — ${fmt(p.totalUzs.toString())}`,
          link: `/sales/deals/${p.dealId}`,
        },
        meta.actorId,
      );
    });

    this.dispatcher.on('contract.signed', async (e, meta) => {
      const deal = await this.prisma.deal.findUnique({
        where: { id: e.dealId },
        include: { client: true },
      });
      if (!deal) return;
      await this.notifications.notify(
        [e.managerId, await this.teamHead(e.teamId)],
        {
          type: 'contract.signed',
          title: 'Договор подписан',
          body: `${deal.client.name} · ${deal.title}`,
          link: `/sales/deals/${deal.id}`,
        },
        meta.actorId,
      );
    });

    // Оплата: менеджеру, РОП и CEO (ТЗ §14).
    this.dispatcher.on('payment.paid', async (e, meta) => {
      const deal = await this.prisma.deal.findUnique({
        where: { id: e.dealId },
        include: { client: true },
      });
      if (!deal) return;
      await this.notifications.notify(
        [e.managerId, await this.teamHead(e.teamId), ...(await this.ceoIds())],
        {
          type: 'payment.paid',
          title: 'Оплата получена',
          body: `${deal.client.name}: ${fmt(e.amountUzs)}`,
          link: `/sales/deals/${deal.id}`,
        },
        meta.actorId,
      );
    });

    this.dispatcher.on('project.created', async (e, meta) => {
      const project = await this.prisma.project.findUnique({ where: { id: e.projectId } });
      if (!project) return;
      await this.notifications.notify(
        [e.ropId, e.managerId],
        {
          type: 'project.created',
          title: 'Новый проект',
          body: `${formatNumber('P', project.number)} ${project.name} — назначьте исполнителей`,
          link: `/sales/deals/${project.dealId}`,
        },
        meta.actorId,
      );
    });

    // Шаг 6 сценария приёмки: РОП получает уведомление о новой встрече.
    this.dispatcher.on('meeting.created', async (e, meta) => {
      const m = await this.prisma.meeting.findUnique({
        where: { id: e.meetingId },
        include: { lead: true, deal: true, manager: true },
      });
      if (!m) return;
      const subject = m.lead?.title ?? m.deal?.title ?? '';
      const when = m.startsAt.toLocaleString('ru-RU', {
        timeZone: 'Asia/Tashkent',
        dateStyle: 'short',
        timeStyle: 'short',
      });
      await this.notifications.notify(
        [e.ropId, e.managerId],
        {
          type: 'meeting.created',
          title: 'Новая встреча',
          body: `${when} · ${subject} · ${m.manager.fullName}`,
          link: m.leadId ? `/sales/leads/${m.leadId}` : `/sales/deals/${m.dealId}`,
        },
        meta.actorId,
      );
    });
  }
}

import { Injectable, OnModuleInit } from '@nestjs/common';
import { formatNumber } from '@fluggi/contracts';
import { OutboxDispatcher } from '../../core/outbox/outbox.dispatcher';
import { PrismaService } from '../../core/prisma/prisma.service';
import { SettingsService } from '../../core/settings/settings.service';
import { NotificationsService } from './notifications.service';

const fmt = (uzs: string | number) => `${Math.round(Number(uzs)).toLocaleString('ru-RU')} UZS`;

/**
 * Подписчики событий → уведомления (ТЗ §14, §54): in-app и Telegram через
 * NotificationsService с учётом личных настроек. Порог «крупного» — из настроек.
 */
@Injectable()
export class NotificationEvents implements OnModuleInit {
  constructor(
    private readonly dispatcher: OutboxDispatcher,
    private readonly notifications: NotificationsService,
    private readonly prisma: PrismaService,
    private readonly settings: SettingsService,
  ) {}

  private async large() {
    return (await this.settings.automation()).largeAmountUzs;
  }

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
      if (e.budgetUzs && Number(e.budgetUzs) >= (await this.large())) {
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
      const large = Number(e.amountUzs) >= (await this.large());
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
      if (Number(e.amountUzs) < (await this.large())) return;
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
          link: `/projects/${project.id}`,
        },
        meta.actorId,
      );
    });

    // Проекты и задачи (ТЗ §14, §21–24). Telegram подключится к этим же событиям в Phase 7.
    this.dispatcher.on('project.member_added', async (e, meta) => {
      const project = await this.prisma.project.findUnique({ where: { id: e.projectId } });
      if (!project) return;
      await this.notifications.notify(
        [e.userId],
        {
          type: 'project.member_added',
          title: 'Вас добавили в проект',
          body: `${formatNumber('P', project.number)} ${project.name}`,
          link: `/projects/${project.id}`,
        },
        meta.actorId,
      );
    });

    this.dispatcher.on('project.status_changed', async (e, meta) => {
      if (e.to !== 'COMPLETED' && e.to !== 'CANCELLED') return;
      const project = await this.prisma.project.findUnique({ where: { id: e.projectId } });
      if (!project) return;
      await this.notifications.notify(
        [project.managerId, project.ropId, ...(await this.ceoIds())],
        {
          type: `project.${e.to === 'COMPLETED' ? 'completed' : 'cancelled'}`,
          title: e.to === 'COMPLETED' ? 'Проект завершён' : 'Проект отменён',
          body: `${formatNumber('P', project.number)} ${project.name}`,
          link: `/projects/${project.id}`,
        },
        meta.actorId,
      );
    });

    this.dispatcher.on('task.assigned', async (e, meta) => {
      const task = await this.prisma.task.findUnique({
        where: { id: e.taskId },
        include: { project: true },
      });
      if (!task || task.assigneeId !== e.assigneeId) return;
      await this.notifications.notify(
        [e.assigneeId],
        {
          type: 'task.assigned',
          title: 'Новая задача',
          body: `${task.title} · ${task.project.name}`,
          link: `/projects/${task.projectId}?task=${task.id}`,
        },
        meta.actorId,
      );
    });

    // На проверку — автору задачи и РОП проекта; принято/возвращено — исполнителю.
    this.dispatcher.on('task.status_changed', async (e, meta) => {
      const task = await this.prisma.task.findUnique({
        where: { id: e.taskId },
        include: { project: true },
      });
      if (!task) return;
      const link = `/projects/${task.projectId}?task=${task.id}`;
      if (e.to === 'REVIEW') {
        await this.notifications.notify(
          [task.creatorId, task.project.ropId],
          { type: 'task.review', title: 'Задача на проверке', body: task.title, link },
          meta.actorId,
        );
      } else if (e.from === 'REVIEW') {
        const accepted = e.to === 'DONE';
        await this.notifications.notify(
          [task.assigneeId],
          {
            type: accepted ? 'task.accepted' : 'task.returned',
            title: accepted ? 'Задача принята' : 'Задача возвращена на доработку',
            body: task.title,
            link,
          },
          meta.actorId,
        );
      }
    });

    // Исполнителю: изменение дедлайна (ТЗ §14)
    this.dispatcher.on('task.deadline_changed', async (e, meta) => {
      const task = await this.prisma.task.findUnique({
        where: { id: e.taskId },
        include: { project: true },
      });
      if (!task) return;
      const when = task.deadline
        ? task.deadline.toLocaleString('ru-RU', {
            timeZone: 'Asia/Tashkent',
            dateStyle: 'short',
            timeStyle: 'short',
          })
        : 'без срока';
      await this.notifications.notify(
        [task.assigneeId],
        {
          type: 'task.deadline_changed',
          title: 'Изменён дедлайн задачи',
          body: `${task.title}: ${when}`,
          link: `/projects/${task.projectId}?task=${task.id}`,
        },
        meta.actorId,
      );
    });

    this.dispatcher.on('task.overdue', async (e) => {
      const task = await this.prisma.task.findUnique({
        where: { id: e.taskId },
        include: { project: true },
      });
      if (!task) return;
      const d = e.overdueDays;
      const word =
        d % 10 === 1 && d % 100 !== 11
          ? 'день'
          : [2, 3, 4].includes(d % 10) && ![12, 13, 14].includes(d % 100)
            ? 'дня'
            : 'дней';
      await this.notifications.notify(
        [task.assigneeId, task.project.ropId],
        {
          type: 'task.overdue',
          title: `Просрочено ${d} ${word}`,
          body: `${task.title} · ${task.project.name}`,
          link: `/projects/${task.projectId}?task=${task.id}`,
        },
        null,
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

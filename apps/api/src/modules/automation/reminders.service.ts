import { Injectable, Logger } from '@nestjs/common';
import { ACTIVE_PROJECT_STATUSES, formatNumber } from '@fluggi/contracts';
import { addDays, companyDate, companyDayStart, tashkentTime } from '@fluggi/domain';
import { Prisma } from '@fluggi/db';
import { parseDate } from '../../core/http/serialize';
import { PrismaService } from '../../core/prisma/prisma.service';
import {
  NotificationsService,
  type NotificationInput,
} from '../notifications/notifications.service';

const DAY = 86_400_000;
const MIN = 60_000;

/**
 * Умные напоминания (ТЗ §41). Каждое напоминание записывается в reminder_log с уникальным
 * ключом — повторный запуск (или второй worker) не отправит то же самое ещё раз.
 */
@Injectable()
export class RemindersService {
  private readonly logger = new Logger(RemindersService.name);

  constructor(
    private readonly prisma: PrismaService,
    private readonly notifications: NotificationsService,
  ) {}

  /** true — ключ новый, напоминание нужно отправить. */
  private async once(kind: string, key: string): Promise<boolean> {
    try {
      await this.prisma.reminderLog.create({ data: { kind, dedupeKey: `${kind}:${key}` } });
      return true;
    } catch (err) {
      if (err instanceof Prisma.PrismaClientKnownRequestError && err.code === 'P2002') return false;
      throw err;
    }
  }

  /** Отправить один раз по ключу (для событийных напоминаний). */
  sendOnce(kind: string, key: string, to: (string | null)[], n: NotificationInput) {
    return this.send(kind, key, to, n);
  }

  private async send(kind: string, key: string, to: (string | null)[], n: NotificationInput) {
    if (!(await this.once(kind, key))) return 0;
    await this.notifications.notify(to, n, null);
    return 1;
  }

  private async teamHead(teamId: string | null) {
    if (!teamId) return null;
    return (await this.prisma.team.findUnique({ where: { id: teamId } }))?.headId ?? null;
  }

  private async ceoIds() {
    const rows = await this.prisma.user.findMany({
      where: { role: { code: 'CEO' }, status: 'ACTIVE', deletedAt: null },
      select: { id: true },
    });
    return rows.map((r) => r.id);
  }

  async run(now = new Date()): Promise<Record<string, number>> {
    const today = companyDate(now);
    const out: Record<string, number> = {};
    const add = (k: string, n: number) => {
      if (n) out[k] = (out[k] ?? 0) + n;
    };

    // Встреча завтра — один раз в день по каждой встрече
    const tomorrow = companyDayStart(addDays(today, 1));
    const meetingsTomorrow = await this.prisma.meeting.findMany({
      where: {
        status: { in: ['SCHEDULED', 'CONFIRMED'] },
        startsAt: { gte: tomorrow, lt: new Date(tomorrow.getTime() + DAY) },
      },
      include: { lead: true, deal: true },
    });
    for (const m of meetingsTomorrow)
      add(
        'meeting.tomorrow',
        await this.send('meeting.tomorrow', m.id, [m.managerId], {
          type: 'meeting.reminder',
          title: `Встреча завтра в ${tashkentTime(m.startsAt)}`,
          body: m.lead?.title ?? m.deal?.title ?? '',
          link: m.leadId ? `/sales/leads/${m.leadId}` : `/sales/deals/${m.dealId}`,
        }),
      );

    // Встреча через 30 минут
    const soon = await this.prisma.meeting.findMany({
      where: {
        status: { in: ['SCHEDULED', 'CONFIRMED'] },
        startsAt: { gt: now, lte: new Date(now.getTime() + 30 * MIN) },
      },
      include: { lead: true, deal: true },
    });
    for (const m of soon)
      add(
        'meeting.soon',
        await this.send('meeting.soon', m.id, [m.managerId, m.ropId], {
          type: 'meeting.reminder',
          title: `Встреча через ${Math.max(1, Math.round((m.startsAt.getTime() - now.getTime()) / MIN))} мин`,
          body: `${tashkentTime(m.startsAt)} · ${m.lead?.title ?? m.deal?.title ?? ''}`,
          link: m.leadId ? `/sales/leads/${m.leadId}` : `/sales/deals/${m.dealId}`,
        }),
      );

    // Клиенту не звонили 3 дня (лиды в работе) — не чаще раза в 3 дня
    const stale = await this.prisma.lead.findMany({
      where: {
        status: 'OPEN',
        deletedAt: null,
        OR: [
          { lastContactAt: { lt: new Date(now.getTime() - 3 * DAY) } },
          { lastContactAt: null, createdAt: { lt: new Date(now.getTime() - 3 * DAY) } },
        ],
      },
      select: { id: true, title: true, ownerId: true },
      take: 500,
    });
    const bucket = Math.floor(now.getTime() / (3 * DAY));
    for (const l of stale)
      add(
        'lead.no_contact',
        await this.send('lead.no_contact', `${l.id}:${bucket}`, [l.ownerId], {
          type: 'client.no_contact',
          title: 'Клиенту не звонили 3 дня',
          body: l.title,
          link: `/sales/leads/${l.id}`,
        }),
      );

    // Клиент не отвечает: по открытой сделке нет активности 7 дней — раз в неделю
    const openDeals = await this.prisma.deal.findMany({
      where: {
        status: 'OPEN',
        deletedAt: null,
        createdAt: { lt: new Date(now.getTime() - 7 * DAY) },
      },
      select: { id: true, title: true, ownerId: true },
      take: 500,
    });
    if (openDeals.length) {
      const last = await this.prisma.activity.groupBy({
        by: ['dealId'],
        where: { dealId: { in: openDeals.map((d) => d.id) } },
        _max: { createdAt: true },
      });
      const week = Math.floor(now.getTime() / (7 * DAY));
      for (const d of openDeals) {
        const at = last.find((x) => x.dealId === d.id)?._max.createdAt;
        if (at && at > new Date(now.getTime() - 7 * DAY)) continue;
        add(
          'deal.silent',
          await this.send('deal.silent', `${d.id}:${week}`, [d.ownerId], {
            type: 'client.no_contact',
            title: 'Клиент не отвечает 7 дней',
            body: d.title,
            link: `/sales/deals/${d.id}`,
          }),
        );
      }
    }

    // КП отправлено 2 дня назад и без ответа
    const proposals = await this.prisma.proposal.findMany({
      where: {
        status: { in: ['SENT', 'VIEWED'] },
        sentAt: { lt: new Date(now.getTime() - 2 * DAY) },
      },
      select: { id: true, number: true, title: true, dealId: true, managerId: true },
    });
    for (const p of proposals)
      add(
        'proposal.waiting',
        await this.send('proposal.waiting', p.id, [p.managerId], {
          type: 'proposal.reminder',
          title: 'КП отправлено 2 дня назад — нет ответа',
          body: `${formatNumber('KP', p.number)} ${p.title}`,
          link: `/sales/deals/${p.dealId}`,
        }),
      );

    // Договор не подписан 3 дня
    const contracts = await this.prisma.contract.findMany({
      where: {
        status: { in: ['DRAFT', 'SENT', 'IN_APPROVAL'] },
        createdAt: { lt: new Date(now.getTime() - 3 * DAY) },
      },
      include: { deal: true },
    });
    for (const c of contracts)
      add(
        'contract.unsigned',
        await this.send(
          'contract.unsigned',
          c.id,
          [c.deal.ownerId, await this.teamHead(c.deal.teamId)],
          {
            type: 'contract.reminder',
            title: 'Договор не подписан',
            body: `${c.deal.title}`,
            link: `/sales/deals/${c.dealId}`,
          },
        ),
      );

    // Оплата просрочена (ожидаемая дата прошла)
    const payments = await this.prisma.payment.findMany({
      where: { status: 'PENDING', dueDate: { lt: parseDate(today)! } },
      include: { deal: true },
    });
    for (const p of payments)
      add(
        'payment.overdue',
        await this.send(
          'payment.overdue',
          p.id,
          [p.deal.ownerId, await this.teamHead(p.deal.teamId)],
          {
            type: 'payment.overdue',
            title: 'Оплата просрочена',
            body: `${p.deal.title}: ${Math.round(Number(p.amount)).toLocaleString('ru-RU')} ${p.currency}`,
            link: `/sales/deals/${p.dealId}`,
          },
        ),
      );

    // Проект заканчивается через 3 дня
    const ending = await this.prisma.project.findMany({
      where: {
        deletedAt: null,
        status: { in: [...ACTIVE_PROJECT_STATUSES] },
        deadline: parseDate(addDays(today, 3))!,
      },
    });
    for (const p of ending)
      add(
        'project.ending',
        await this.send('project.ending', p.id, [p.managerId, p.ropId], {
          type: 'project.ending',
          title: 'Проект заканчивается через 3 дня',
          body: `${formatNumber('P', p.number)} ${p.name}`,
          link: `/projects/${p.id}`,
        }),
      );

    // Просроченный проект — РОП и CEO, один раз
    const overdue = await this.prisma.project.findMany({
      where: {
        deletedAt: null,
        status: { in: [...ACTIVE_PROJECT_STATUSES] },
        deadline: { lt: parseDate(today)! },
      },
    });
    const ceos = overdue.length ? await this.ceoIds() : [];
    for (const p of overdue)
      add(
        'project.overdue',
        await this.send('project.overdue', p.id, [p.ropId, ...ceos], {
          type: 'project.overdue',
          title: 'Просроченный проект',
          body: `${formatNumber('P', p.number)} ${p.name}`,
          link: `/projects/${p.id}`,
        }),
      );

    if (Object.keys(out).length) this.logger.log(`Reminders: ${JSON.stringify(out)}`);
    return out;
  }
}

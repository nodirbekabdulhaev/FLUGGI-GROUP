import { Injectable, OnModuleInit } from '@nestjs/common';
import { OutboxDispatcher } from '../../core/outbox/outbox.dispatcher';
import { PrismaService } from '../../core/prisma/prisma.service';
import { NotificationsService } from '../notifications/notifications.service';
import { FollowUpsService } from './follow-ups.service';
import { RemindersService } from './reminders.service';
import { ReportsService } from './reports.service';

/** Автоматизации по событиям (ТЗ §54): follow-up после проекта, достижение плана. */
@Injectable()
export class AutomationEvents implements OnModuleInit {
  constructor(
    private readonly dispatcher: OutboxDispatcher,
    private readonly prisma: PrismaService,
    private readonly followUps: FollowUpsService,
    private readonly reports: ReportsService,
    private readonly notifications: NotificationsService,
    private readonly reminders: RemindersService,
  ) {}

  onModuleInit() {
    // Rule 8: после завершения проекта — follow-up на 30/60/90 дней (интервалы — в настройках)
    this.dispatcher.on('project.status_changed', async (e) => {
      if (e.to !== 'COMPLETED') return;
      await this.prisma.$transaction((tx) => this.followUps.createForProject(tx, e.projectId));
    });

    // ТЗ §14: CEO (и РОП по отделу) — «Достижение месячного плана», один раз за месяц
    this.dispatcher.on('payment.paid', async (e) => {
      const company = await this.reports.planProgress({});
      if (company.plan.gt(0) && company.collected.gte(company.plan)) {
        const ceos = await this.prisma.user.findMany({
          where: { role: { code: 'CEO' }, status: 'ACTIVE', deletedAt: null },
          select: { id: true },
        });
        await this.reminders.sendOnce(
          'plan.achieved',
          `company:${company.period}`,
          ceos.map((c) => c.id),
          {
            type: 'plan.achieved',
            title: '🎉 Месячный план выполнен',
            body: `${company.period}: ${Math.round(Number(company.collected)).toLocaleString('ru-RU')} из ${Math.round(Number(company.plan)).toLocaleString('ru-RU')} UZS`,
            link: '/finance/revenue',
          },
        );
      }
      if (!e.teamId) return;
      const team = await this.reports.planProgress({ teamIds: [e.teamId] });
      if (team.plan.gt(0) && team.collected.gte(team.plan)) {
        const head =
          (await this.prisma.team.findUnique({ where: { id: e.teamId } }))?.headId ?? null;
        await this.reminders.sendOnce('plan.achieved', `team:${e.teamId}:${team.period}`, [head], {
          type: 'plan.achieved',
          title: '🎉 План отдела выполнен',
          body: `${team.period}: ${Math.round(Number(team.collected)).toLocaleString('ru-RU')} из ${Math.round(Number(team.plan)).toLocaleString('ru-RU')} UZS`,
          link: '/dashboard',
        });
      }
    });
  }
}

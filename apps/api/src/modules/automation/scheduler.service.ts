import { Injectable, Logger, OnApplicationBootstrap, OnApplicationShutdown } from '@nestjs/common';
import { ATTENDANCE_ROLES, type PermissionMap } from '@fluggi/contracts';
import { addDays, companyDate, isoWeekday, tashkentTime } from '@fluggi/domain';
import { Prisma } from '@fluggi/db';
import { loadEnv } from '../../config/env';
import type { AuthContext } from '../../core/auth/auth-context';
import { parseDate } from '../../core/http/serialize';
import { PrismaService } from '../../core/prisma/prisma.service';
import { SettingsService } from '../../core/settings/settings.service';
import { ClientInsightsService } from '../analytics/client-insights.service';
import { NotificationsService } from '../notifications/notifications.service';
import { RecurringTodosService } from '../todos/recurring.service';
import { PeopleService } from '../people/people.service';
import { OverdueScanner } from '../projects/overdue.runner';
import { backgroundEnabled } from '../telegram/telegram.runners';
import { FollowUpsService } from './follow-ups.service';
import { RemindersService } from './reminders.service';
import { ReportsService } from './reports.service';

type Due = (now: Date) => string | null;

/** Каждые N минут: слот — дата + номер интервала. */
const every =
  (minutes: number): Due =>
  (now) => {
    const t = tashkentTime(now);
    const m = Number(t.slice(0, 2)) * 60 + Number(t.slice(3));
    return `${companyDate(now)}#${Math.floor(m / minutes)}`;
  };
/** Ежедневно после HH:MM (Ташкент); пропущенный запуск догоняется в тот же день. */
const dailyAt =
  (hhmm: string): Due =>
  (now) =>
    tashkentTime(now) >= hhmm ? companyDate(now) : null;
const weeklyAt =
  (weekday: number, hhmm: string): Due =>
  (now) =>
    isoWeekday(companyDate(now)) === weekday && tashkentTime(now) >= hhmm ? companyDate(now) : null;
const monthlyAt =
  (hhmm: string): Due =>
  (now) =>
    companyDate(now).endsWith('-01') && tashkentTime(now) >= hhmm
      ? companyDate(now).slice(0, 7)
      : null;

export interface JobDef {
  name: string;
  label: string;
  schedule: string;
  due: Due;
  run: (now: Date) => Promise<unknown>;
}

const TICK_MS = 30_000;

/**
 * Планировщик (ТЗ §55). Запуск каждой задачи фиксируется в job_runs с уникальным слотом,
 * поэтому даже при нескольких worker'ах задача выполняется один раз.
 */
@Injectable()
export class SchedulerService implements OnApplicationBootstrap, OnApplicationShutdown {
  private readonly logger = new Logger(SchedulerService.name);
  private timer: NodeJS.Timeout | undefined;
  private running = false;
  readonly jobs: JobDef[];

  constructor(
    private readonly prisma: PrismaService,
    private readonly settings: SettingsService,
    private readonly notifications: NotificationsService,
    private readonly reminders: RemindersService,
    private readonly reports: ReportsService,
    private readonly followUps: FollowUpsService,
    private readonly overdue: OverdueScanner,
    private readonly people: PeopleService,
    private readonly clients: ClientInsightsService,
    private readonly recurring: RecurringTodosService,
  ) {
    this.jobs = [
      {
        name: 'overdue-tasks',
        label: 'Проверка просроченных задач',
        schedule: 'каждый час',
        due: every(60),
        run: (now) => this.overdue.scan(now),
      },
      {
        name: 'smart-reminders',
        label: 'Умные напоминания',
        schedule: 'каждые 15 минут',
        due: every(15),
        run: (now) => this.reminders.run(now),
      },
      {
        name: 'follow-ups',
        label: 'Повторные контакты на сегодня',
        schedule: 'ежедневно 09:00',
        due: dailyAt('09:00'),
        run: (now) => this.followUpsDue(now),
      },
      {
        name: 'daily-report',
        label: 'Ежедневный отчёт руководителю',
        schedule: 'ежедневно 18:00',
        due: dailyAt('18:00'),
        run: (now) => this.dailyReport(now),
      },
      {
        name: 'absences',
        label: 'Отметка отсутствующих',
        schedule: 'ежедневно 20:00',
        due: dailyAt('20:00'),
        run: (now) => this.absences(now),
      },
      {
        name: 'weekly-report',
        label: 'Еженедельный отчёт CEO',
        schedule: 'понедельник 09:00',
        due: weeklyAt(1, '09:00'),
        run: (now) => this.weeklyReport(now),
      },
      {
        name: 'recurring-todos',
        label: 'Регулярные дела (налоговый календарь)',
        schedule: 'ежедневно 07:00',
        due: dailyAt('07:00'),
        run: (now) => this.recurring.generate(now),
      },
      {
        name: 'client-health',
        label: 'Здоровье клиентов',
        schedule: 'ежедневно 06:00',
        due: dailyAt('06:00'),
        run: (now) => this.clientHealth(now),
      },
      {
        name: 'payroll',
        label: 'Предварительный расчёт зарплаты',
        schedule: '1-го числа 09:00',
        due: monthlyAt('09:00'),
        run: (now) => this.payroll(now),
      },
    ];
  }

  onApplicationBootstrap() {
    if (!backgroundEnabled() || !loadEnv().SCHEDULER_ENABLED) return;
    this.timer = setInterval(() => void this.tick(), TICK_MS);
    this.timer.unref();
    void this.tick();
  }

  onApplicationShutdown() {
    if (this.timer) clearInterval(this.timer);
  }

  async tick(now = new Date()) {
    if (this.running) return;
    this.running = true;
    try {
      for (const job of this.jobs) {
        const slot = job.due(now);
        if (slot) await this.execute(job, slot, now);
      }
    } finally {
      this.running = false;
    }
  }

  /** Выполнить задачу в слоте один раз. false — слот уже был выполнен. */
  async execute(job: JobDef, slot: string, now = new Date()): Promise<boolean> {
    let runId: string;
    try {
      runId = (await this.prisma.jobRun.create({ data: { job: job.name, slot } })).id;
    } catch (err) {
      if (err instanceof Prisma.PrismaClientKnownRequestError && err.code === 'P2002') return false;
      throw err;
    }
    try {
      const result = await job.run(now);
      await this.prisma.jobRun.update({
        where: { id: runId },
        data: { finishedAt: new Date(), result: (result ?? null) as Prisma.InputJsonValue },
      });
    } catch (err) {
      this.logger.error(err, `Job ${job.name} failed`);
      await this.prisma.jobRun.update({
        where: { id: runId },
        data: { finishedAt: new Date(), error: String((err as Error).message).slice(0, 1000) },
      });
    }
    return true;
  }

  /** Ручной запуск из настроек (отдельный слот, чтобы не мешать расписанию). */
  async runNow(name: string, now = new Date()) {
    const job = this.jobs.find((j) => j.name === name);
    if (!job) return null;
    await this.execute(job, `manual:${now.toISOString()}`, now);
    return this.prisma.jobRun.findFirst({ where: { job: name }, orderBy: { startedAt: 'desc' } });
  }

  private async users(role: 'CEO' | 'ROP') {
    return this.prisma.user.findMany({
      where: { role: { code: role }, status: 'ACTIVE', deletedAt: null },
      include: { headedTeams: { where: { deletedAt: null }, select: { id: true } } },
    });
  }

  // ─────────────────────────── Задачи ───────────────────────────

  private async followUpsDue(now: Date) {
    const today = parseDate(companyDate(now))!;
    const due = await this.prisma.followUp.findMany({
      where: { status: 'PENDING', notifiedAt: null, dueDate: { lte: today } },
      include: { client: true },
    });
    for (const f of due) {
      await this.notifications.notify([f.ownerId], {
        type: 'followup.due',
        title: FollowUpsService.title(f.kind),
        body: f.client.name,
        link: '/sales/follow-ups',
      });
      await this.prisma.followUp.update({ where: { id: f.id }, data: { notifiedAt: now } });
    }
    return { notified: due.length };
  }

  private async dailyReport(now: Date) {
    if (!(await this.settings.automation()).dailyReport) return { skipped: true };
    const company = await this.reports.daily({}, now);
    const ceos = await this.users('CEO');
    await this.notifications.notify(
      ceos.map((u) => u.id),
      {
        type: 'report.daily',
        title: 'Ежедневный отчёт',
        body: company.replace(/<[^>]+>/g, ''),
        link: '/dashboard',
      },
    );
    const rops = await this.users('ROP');
    for (const r of rops.filter((x) => x.headedTeams.length)) {
      const text = await this.reports.daily({ teamIds: r.headedTeams.map((t) => t.id) }, now);
      await this.notifications.notify([r.id], {
        type: 'report.daily',
        title: 'Ежедневный отчёт отдела',
        body: text.replace(/<[^>]+>/g, ''),
        link: '/dashboard',
      });
    }
    return { ceo: ceos.length, rop: rops.length };
  }

  private async weeklyReport(now: Date) {
    if (!(await this.settings.automation()).weeklyReport) return { skipped: true };
    const text = await this.reports.weekly({}, now);
    const ceos = await this.users('CEO');
    await this.notifications.notify(
      ceos.map((u) => u.id),
      {
        type: 'report.weekly',
        title: 'Еженедельный отчёт',
        body: text.replace(/<[^>]+>/g, ''),
        link: '/finance/revenue',
      },
    );
    return { ceo: ceos.length };
  }

  /** Сотрудники с рабочим днём по графику и без отметки — «Отсутствовал» (ТЗ §35). */
  private async absences(now: Date) {
    const date = companyDate(now);
    const weekday = isoWeekday(date);
    const users = await this.prisma.user.findMany({
      // Посещаемость отмечают только менеджеры и РОП
      where: { status: 'ACTIVE', deletedAt: null, role: { code: { in: [...ATTENDANCE_ROLES] } } },
      include: { role: true, employee: { include: { schedule: true } } },
    });
    const schedules = await this.prisma.workSchedule.findMany({
      where: { isActive: true, roleCode: { not: null } },
    });
    const marked = new Set(
      (
        await this.prisma.attendance.findMany({
          where: { date: parseDate(date)! },
          select: { userId: true },
        })
      ).map((a) => a.userId),
    );
    let created = 0;
    for (const u of users) {
      if (marked.has(u.id)) continue;
      const own = u.employee?.schedule?.isActive ? u.employee.schedule : null;
      const schedule = own ?? schedules.find((s) => s.roleCode === u.role.code);
      if (!schedule || !(schedule.workDays as number[]).includes(weekday)) continue;
      await this.prisma.attendance.create({
        data: {
          userId: u.id,
          date: parseDate(date)!,
          status: 'ABSENT',
          comment: 'Нет отметки (автоматически)',
        },
      });
      created += 1;
    }
    return { absent: created };
  }

  /** Пересчёт «здоровья» клиентов (ТЗ §43); перешедшие в «Риск» — уведомление менеджеру. */
  private async clientHealth(now: Date) {
    const r = await this.clients.refreshAll(now);
    for (const c of r.toRisk)
      await this.notifications.notify([c.ownerId], {
        type: 'client.risk',
        title: 'Клиент в зоне риска',
        body: `${c.name}: ${c.reasons.join(', ')}`,
        link: `/clients/${c.id}`,
      });
    return { changed: r.changed, risk: r.toRisk.length };
  }

  /** 1-го числа: черновик зарплаты за прошлый месяц и уведомление CEO (ТЗ §55). */
  private async payroll(now: Date) {
    const prev = addDays(`${companyDate(now).slice(0, 7)}-01`, -1).slice(0, 7);
    const ceo = (await this.users('CEO'))[0];
    if (!ceo) return { skipped: 'no CEO' };
    const all: PermissionMap = {
      'payroll.manage': 'ALL',
      'payroll.read': 'ALL',
      'kpi.read': 'ALL',
    };
    const system: AuthContext = {
      sessionId: 'system',
      userId: ceo.id,
      email: ceo.email,
      fullName: 'Система',
      locale: 'ru',
      roleCode: 'CEO',
      roleName: 'CEO',
      teamId: null,
      teamName: null,
      headedTeamIds: [],
      directionIds: [],
      permissions: all,
      telegramLinked: false,
    };
    const rows = await this.people.calculate(system, prev, {
      ip: null,
      userAgent: 'scheduler',
      sessionId: null,
    });
    await this.notifications.notify([ceo.id], {
      type: 'payroll.calculated',
      title: `Предварительный расчёт зарплаты за ${prev}`,
      body: `Начислений: ${rows.length}. Проверьте бонусы и штрафы и утвердите.`,
      link: '/finance/payroll',
    });
    return { period: prev, entries: rows.length };
  }
}

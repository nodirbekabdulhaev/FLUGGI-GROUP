import { createServer, type IncomingMessage, type Server } from 'node:http';
import type { AddressInfo } from 'node:net';
import type { INestApplication } from '@nestjs/common';
import request from 'supertest';
import { afterAll, beforeAll, beforeEach, describe, expect, it } from 'vitest';
import { resetEnvCache } from '../../config/env';
import { OutboxDispatcher } from '../../core/outbox/outbox.dispatcher';
import { OutboxService } from '../../core/outbox/outbox.service';
import type { PrismaService } from '../../core/prisma/prisma.service';
import { SettingsService } from '../../core/settings/settings.service';
import { Client, createTestApp, resetDatabase } from '../../test/helpers';
import { TelegramRunner, TelegramSender } from '../telegram/telegram.runners';
import { RemindersService } from './reminders.service';
import { ReportsService } from './reports.service';
import { SchedulerService } from './scheduler.service';

/** Заглушка Telegram Bot API: запоминает вызовы, отвечает ok или ошибкой. */
const calls: { method: string; body: Record<string, unknown> }[] = [];
let failNext: {
  status: number;
  description: string;
  retry_after?: number;
  method?: string;
} | null = null;
let stub: Server;

const readBody = (req: IncomingMessage) =>
  new Promise<string>((resolve) => {
    let s = '';
    req.on('data', (c) => (s += c));
    req.on('end', () => resolve(s));
  });

let app: INestApplication;
let prisma: PrismaService;
let fx: Awaited<ReturnType<typeof resetDatabase>>;

beforeAll(async () => {
  stub = createServer(async (req, res) => {
    const method = req.url!.split('/').pop()!;
    const body = JSON.parse((await readBody(req)) || '{}');
    calls.push({ method, body });
    res.setHeader('Content-Type', 'application/json');
    if (failNext && method === (failNext.method ?? 'sendMessage')) {
      const f = failNext;
      failNext = null;
      res.statusCode = f.status;
      res.end(
        JSON.stringify({
          ok: false,
          description: f.description,
          parameters: f.retry_after ? { retry_after: f.retry_after } : undefined,
        }),
      );
      return;
    }
    const result = method === 'getMe' ? { id: 1, username: 'fluggi_test_bot' } : { message_id: 1 };
    res.end(JSON.stringify({ ok: true, result }));
  });
  await new Promise<void>((r) => stub.listen(0, '127.0.0.1', r));
  process.env.TELEGRAM_BOT_TOKEN = '123:test';
  process.env.TELEGRAM_API_BASE = `http://127.0.0.1:${(stub.address() as AddressInfo).port}`;
  process.env.TELEGRAM_WEBHOOK_SECRET = 'webhook-secret-123';
  resetEnvCache();
  ({ app, prisma } = await createTestApp());
});
afterAll(async () => {
  await app.close();
  stub.close();
  delete process.env.TELEGRAM_BOT_TOKEN;
  delete process.env.TELEGRAM_API_BASE;
  delete process.env.TELEGRAM_WEBHOOK_SECRET;
  resetEnvCache();
});
beforeEach(async () => {
  fx = await resetDatabase(prisma);
  calls.length = 0;
  failNext = null;
  app.get(SettingsService).invalidate();
  // Фоновый процесс бота проверил токен (в тестах фоновые циклы выключены)
  await app.get(TelegramRunner).check();
  calls.length = 0;
});

const drain = async () => {
  while ((await app.get(OutboxDispatcher).processBatch()) > 0);
};
const webhook = (text: string, chatId = 777) =>
  request(app.getHttpServer())
    .post('/api/v1/telegram/webhook')
    .set('x-telegram-bot-api-secret-token', 'webhook-secret-123')
    .send({
      update_id: 1,
      message: {
        message_id: 1,
        text,
        chat: { id: chatId, type: 'private' },
        from: { id: chatId, username: 'aziz' },
      },
    });

/** Подключить Telegram пользователю через код из CRM и /start в боте. */
async function link(c: Client, chatId = 777) {
  const l = await c.post('/api/v1/me/telegram/link');
  expect(l.status, JSON.stringify(l.body)).toBe(200);
  expect((await webhook(l.body.command, chatId)).status).toBe(200);
  return l.body;
}

describe('Telegram-бот (ТЗ §53)', () => {
  it('привязка по одноразовому коду, отписка, проверка секрета webhook', async () => {
    const manager = await Client.login(app, 'manager@test.uz');
    let s = (await manager.get('/api/v1/me/telegram')).body;
    expect(s).toEqual({
      botConfigured: true,
      botUsername: 'fluggi_test_bot',
      linked: false,
      username: null,
      problem: null,
    });

    const l = await link(manager);
    expect(l.deepLink).toMatch(/^https:\/\/t\.me\/fluggi_test_bot\?start=[\w-]+$/);
    s = (await manager.get('/api/v1/me/telegram')).body;
    expect(s).toMatchObject({ linked: true, username: 'aziz' });
    expect(calls.find((c) => c.method === 'sendMessage')?.body).toMatchObject({ chat_id: '777' });
    // Код одноразовый: второй раз не сработает, и чат не перепривяжется к другому аккаунту
    const rop = await Client.login(app, 'rop@test.uz');
    await webhook(l.command, 778);
    expect(
      (await prisma.user.findUniqueOrThrow({ where: { id: fx.users.manager.id } })).telegramChatId,
    ).toBe('777');
    expect(String(calls.at(-1)!.body.text)).toContain('недействителен');
    // Один чат — один аккаунт: РОП подключает тот же чат → у менеджера привязка снимается
    await link(rop, 777);
    expect(
      (await prisma.user.findUniqueOrThrow({ where: { id: fx.users.manager.id } })).telegramChatId,
    ).toBeNull();

    // Без секрета или с чужим — 403, данные не меняются
    const bad = await request(app.getHttpServer())
      .post('/api/v1/telegram/webhook')
      .set('x-telegram-bot-api-secret-token', 'wrong')
      .send({
        update_id: 2,
        message: { message_id: 2, text: '/stop', chat: { id: 777, type: 'private' } },
      });
    expect(bad.status).toBe(403);
    expect(
      (await request(app.getHttpServer()).post('/api/v1/telegram/webhook').send({})).status,
    ).toBe(403);
    expect((await rop.get('/api/v1/me/telegram')).body.linked).toBe(true);

    await webhook('/stop');
    expect((await rop.get('/api/v1/me/telegram')).body.linked).toBe(false);
    await link(rop);
    expect((await rop.delete('/api/v1/me/telegram')).status).toBe(204);
    expect((await rop.get('/api/v1/me/telegram')).body.linked).toBe(false);
  });

  it('бот не работает: CRM показывает причину и не выдаёт код', async () => {
    const manager = await Client.login(app, 'manager@test.uz');
    const runner = app.get(TelegramRunner);
    const problem = async () => (await manager.get('/api/v1/me/telegram')).body.problem as string;

    // Неверный токен
    failNext = { status: 401, description: 'Unauthorized', method: 'getMe' };
    expect(await runner.check()).toBe(false);
    expect(await problem()).toContain('Токен бота недействителен');
    const l = await manager.post('/api/v1/me/telegram/link');
    expect(l.status).toBe(422);
    expect(l.body.error.message).toContain('TELEGRAM_BOT_TOKEN');

    // Токен исправлен — проблема уходит
    expect(await runner.check()).toBe(true);
    expect(await problem()).toBeNull();

    // Имя бота в .env не совпадает с ботом токена — ссылка вела бы не в того бота
    process.env.TELEGRAM_BOT_USERNAME = '@other_bot';
    resetEnvCache();
    try {
      expect(await runner.check()).toBe(false);
      expect(await problem()).toContain('не совпадает');
    } finally {
      delete process.env.TELEGRAM_BOT_USERNAME;
      resetEnvCache();
    }
    expect(await runner.check()).toBe(true);
    // Ссылка — на настоящего бота из getMe
    expect((await manager.post('/api/v1/me/telegram/link')).body.deepLink).toContain(
      't.me/fluggi_test_bot?',
    );

    // Фоновый процесс давно не отзывался (API не перезапущен, worker не запущен) — «бот не запущен»
    await prisma.setting.update({
      where: { key: 'telegram.health' },
      data: {
        value: {
          at: new Date(Date.now() - 10 * 60_000).toISOString(),
          problem: null,
          bot: 'fluggi_test_bot',
        },
      },
    });
    expect(await problem()).toContain('Бот не запущен');
    expect((await manager.post('/api/v1/me/telegram/link')).status).toBe(422);
  });

  it('доставка: очередь, повтор после ошибки, личные настройки каналов', async () => {
    const manager = await Client.login(app, 'manager@test.uz');
    expect((await manager.post('/api/v1/me/telegram/test')).status).toBe(422);
    await link(manager);
    calls.length = 0;

    // Тестовое сообщение: только Telegram, без in-app
    expect((await manager.post('/api/v1/me/telegram/test')).status).toBe(200);
    expect(await prisma.notification.count({ where: { userId: fx.users.manager.id } })).toBe(0);
    failNext = { status: 429, description: 'Too Many Requests', retry_after: 1 };
    const sender = app.get(TelegramSender);
    expect(await sender.processBatch()).toBe(1);
    let d = await prisma.notificationDelivery.findFirstOrThrow();
    expect(d).toMatchObject({ status: 'PENDING', attempts: 1 });
    expect(d.lastError).toContain('Too Many Requests');
    // Повтор — после паузы
    expect(await sender.processBatch()).toBe(0);
    await prisma.notificationDelivery.update({
      where: { id: d.id },
      data: { nextAttemptAt: new Date() },
    });
    expect(await sender.processBatch()).toBe(1);
    d = await prisma.notificationDelivery.findFirstOrThrow();
    expect(d).toMatchObject({ status: 'SENT', attempts: 2 });
    const sent = calls.filter((c) => c.method === 'sendMessage').at(-1)!.body;
    expect(sent).toMatchObject({ chat_id: '777', parse_mode: 'HTML' });
    expect(String(sent.text)).toContain('<b>Проверка связи</b>');
    expect(String(sent.text)).toContain('http://localhost:3000/profile');

    // Бот заблокирован (403) — без повторов
    await manager.post('/api/v1/me/telegram/test');
    failNext = { status: 403, description: 'Forbidden: bot was blocked by the user' };
    await sender.processBatch();
    expect(
      (await prisma.notificationDelivery.findFirstOrThrow({ orderBy: { createdAt: 'desc' } }))
        .status,
    ).toBe('FAILED');

    // Настройки: у менеджера нет CEO-отчётов; выключаем Telegram для «Новый лид»
    const list = (await manager.get('/api/v1/notifications/settings')).body;
    expect(list.find((x: { type: string }) => x.type === 'lead.created')).toMatchObject({
      inApp: true,
      telegram: true,
    });
    expect(list.some((x: { type: string }) => x.type === 'report.weekly')).toBe(false);
    const saved = await manager.put('/api/v1/notifications/settings', {
      settings: [{ eventType: 'lead.created', channel: 'TELEGRAM', enabled: false }],
    });
    expect(saved.status, JSON.stringify(saved.body)).toBe(200);
    expect(saved.body.find((x: { type: string }) => x.type === 'lead.created')).toMatchObject({
      inApp: true,
      telegram: false,
    });
    await prisma.notificationDelivery.deleteMany();
    const refs = (await manager.get('/api/v1/references')).body;
    const rop = await Client.login(app, 'rop@test.uz');
    await rop.post('/api/v1/leads', {
      contactName: 'Bek',
      phone: '+998901234567',
      sourceId: refs.sources[0].id,
      serviceId: refs.services[0].id,
      ownerId: fx.users.manager.id,
    });
    await drain();
    expect(
      await prisma.notification.count({ where: { userId: fx.users.manager.id } }),
    ).toBeGreaterThan(0);
    expect(
      await prisma.notificationDelivery.count({
        where: { userId: fx.users.manager.id, type: 'lead.created' },
      }),
    ).toBe(0);
  });
});

describe('Умные напоминания и планировщик (ТЗ §41, §55)', () => {
  it('напоминания приходят один раз; задача в слоте выполняется один раз', async () => {
    const manager = await Client.login(app, 'manager@test.uz');
    const refs = (await manager.get('/api/v1/references')).body;
    const lead = await manager.post('/api/v1/leads', {
      contactName: 'Old',
      phone: '+998907777777',
      sourceId: refs.sources[0].id,
      serviceId: refs.services[0].id,
    });
    await prisma.lead.update({
      where: { id: lead.body.id },
      data: { createdAt: new Date(Date.now() - 5 * 86_400_000), lastContactAt: null },
    });
    const soon = new Date(Date.now() + 20 * 60_000);
    const meeting = await manager.post('/api/v1/meetings', {
      leadId: lead.body.id,
      type: 'OFFLINE',
      startsAt: soon.toISOString(),
      durationMin: 60,
    });
    expect(meeting.status, JSON.stringify(meeting.body)).toBe(201);
    await drain();
    await prisma.notification.deleteMany();

    const reminders = app.get(RemindersService);
    const first = await reminders.run();
    expect(first).toMatchObject({ 'lead.no_contact': 1, 'meeting.soon': 1 });
    const titles = (
      await prisma.notification.findMany({ where: { userId: fx.users.manager.id } })
    ).map((n) => n.title);
    expect(titles).toContain('Клиенту не звонили 3 дня');
    expect(titles.some((t) => t.startsWith('Встреча через'))).toBe(true);
    // Повторный запуск ничего не дублирует
    expect(await reminders.run()).toEqual({});

    // Планировщик: один и тот же слот — один запуск
    const scheduler = app.get(SchedulerService);
    const job = scheduler.jobs.find((j) => j.name === 'smart-reminders')!;
    expect(await scheduler.execute(job, 'test-slot')).toBe(true);
    expect(await scheduler.execute(job, 'test-slot')).toBe(false);
    expect(await prisma.jobRun.count({ where: { job: 'smart-reminders' } })).toBe(1);
  });

  it('ручной запуск, отчёты CEO и РОП, права на настройки', async () => {
    const ceo = await Client.login(app, 'ceo@test.uz');
    const rop = await Client.login(app, 'rop@test.uz');
    const manager = await Client.login(app, 'manager@test.uz');

    const jobs = (await ceo.get('/api/v1/automation/jobs')).body;
    expect(jobs.map((j: { name: string }) => j.name)).toEqual([
      'overdue-tasks',
      'smart-reminders',
      'follow-ups',
      'daily-report',
      'absences',
      'weekly-report',
      'payroll',
    ]);
    expect((await rop.get('/api/v1/automation/jobs')).status).toBe(403);
    expect((await manager.post('/api/v1/automation/jobs/daily-report/run')).status).toBe(403);
    expect((await ceo.post('/api/v1/automation/jobs/unknown/run')).status).toBe(404);

    const run = await ceo.post('/api/v1/automation/jobs/daily-report/run');
    expect(run.status, JSON.stringify(run.body)).toBe(200);
    expect(run.body).toMatchObject({
      job: 'daily-report',
      error: null,
      result: { ceo: 1, rop: 1 },
    });
    const report = await prisma.notification.findFirstOrThrow({
      where: { userId: fx.users.ceo.id, type: 'report.daily' },
    });
    expect(report.body).toContain('Новые лиды: 0');
    expect(report.body).toContain('План месяца: цель не задана');
    expect(
      await prisma.notification.count({ where: { userId: fx.users.rop.id, type: 'report.daily' } }),
    ).toBe(1);

    const preview = await ceo.get('/api/v1/reports/preview?kind=weekly');
    expect(preview.body.text).toContain('НЕДЕЛЬНЫЙ ОТЧЁТ');
    expect((await rop.get('/api/v1/reports/preview')).body.text).toContain('ОТЧЁТ ЗА');
    expect((await manager.get('/api/v1/reports/preview')).status).toBe(403);

    // Отчёты можно выключить
    const cur = (await ceo.get('/api/v1/settings/automation')).body;
    expect(cur.largeAmountUzs).toBe(50_000_000);
    const upd = await ceo.put('/api/v1/settings/automation', {
      ...cur,
      dailyReport: false,
      largeAmountUzs: 1_000_000,
    });
    expect(upd.status, JSON.stringify(upd.body)).toBe(200);
    expect((await rop.put('/api/v1/settings/automation', cur)).status).toBe(403);
    expect((await ceo.post('/api/v1/automation/jobs/daily-report/run')).body.result).toEqual({
      skipped: true,
    });
    expect(await prisma.auditLog.count({ where: { action: 'settings.automation' } })).toBe(1);
  });

  it('отметка отсутствующих по графику', async () => {
    const schedule = await prisma.workSchedule.create({
      data: {
        name: 'Все дни',
        startTime: '09:00',
        endTime: '18:00',
        workDays: [1, 2, 3, 4, 5, 6, 7],
      },
    });
    await prisma.employee.update({
      where: { userId: fx.users.manager.id },
      data: { scheduleId: schedule.id },
    });
    await prisma.employee.update({
      where: { userId: fx.users.rop.id },
      data: { scheduleId: schedule.id },
    });
    const rop = await Client.login(app, 'rop@test.uz');
    expect((await rop.post('/api/v1/attendance/check-in', {})).status).toBeLessThan(300);
    const ceo = await Client.login(app, 'ceo@test.uz');
    const r = await ceo.post('/api/v1/automation/jobs/absences/run');
    expect(r.body.result.absent).toBeGreaterThanOrEqual(1);
    const rows = await prisma.attendance.findMany();
    expect(rows.find((a) => a.userId === fx.users.manager.id)?.status).toBe('ABSENT');
    expect(rows.find((a) => a.userId === fx.users.rop.id)?.status).not.toBe('ABSENT');
    // CEO не отмечается; повторный запуск не создаёт дублей
    expect(rows.some((a) => a.userId === fx.users.ceo.id)).toBe(false);
    expect((await ceo.post('/api/v1/automation/jobs/absences/run')).body.result).toEqual({
      absent: 0,
    });
  });
});

describe('Повторные продажи и план (ТЗ §38, Rule 8, §14)', () => {
  async function completedProject() {
    const manager = await Client.login(app, 'manager@test.uz');
    const refs = (await manager.get('/api/v1/references')).body;
    const client = await prisma.client.create({
      data: { name: 'Repeat LLC', ownerId: fx.users.manager.id, teamId: fx.teams.team1.id },
    });
    const deal = await manager.post('/api/v1/deals', {
      clientId: client.id,
      title: 'SMM',
      serviceId: refs.services[0].id,
      amount: '3000000',
    });
    expect(deal.status, JSON.stringify(deal.body)).toBe(201);
    const last = await prisma.project.findFirst({ orderBy: { number: 'desc' } });
    const project = await prisma.project.create({
      data: {
        number: (last?.number ?? 0) + 1,
        name: 'Repeat LLC — SMM',
        clientId: client.id,
        dealId: deal.body.id,
        managerId: fx.users.manager.id,
        ropId: fx.users.rop.id,
        teamId: fx.teams.team1.id,
        status: 'COMPLETED',
        completedAt: new Date(),
        price: 3000000,
        currency: 'UZS',
        priceUzs: 3000000,
      },
    });
    await prisma.$transaction((tx) =>
      app
        .get(OutboxService)
        .publish(
          tx,
          'project.status_changed',
          { projectId: project.id, from: 'IN_PROGRESS', to: 'COMPLETED' },
          null,
        ),
    );
    await drain();
    return { manager, client, project };
  }

  it('после завершения проекта — follow-up 30/60/90, выполнение с созданием сделки', async () => {
    const { manager, client, project } = await completedProject();
    const list = (await manager.get('/api/v1/follow-ups')).body;
    expect(list.total).toBe(3);
    expect(list.items.map((f: { kind: string }) => f.kind)).toEqual([
      'CONTACT',
      'NEW_PROJECT',
      'REPEAT_SALE',
    ]);
    expect(list.items[0].project.id).toBe(project.id);
    // Повторное событие не создаёт дубликатов
    await prisma.$transaction((tx) =>
      app
        .get(OutboxService)
        .publish(
          tx,
          'project.status_changed',
          { projectId: project.id, from: 'IN_PROGRESS', to: 'COMPLETED' },
          null,
        ),
    );
    await drain();
    expect(await prisma.followUp.count()).toBe(3);
    // Чужой менеджер не видит follow-up клиента
    const other = await Client.login(app, 'manager2@test.uz');
    expect((await other.get('/api/v1/follow-ups')).body.total).toBe(0);
    expect(
      (await other.post(`/api/v1/follow-ups/${list.items[0].id}/complete`, { status: 'DONE' }))
        .status,
    ).toBe(404);

    // Пришёл срок — уведомление менеджеру (задача follow-ups)
    await prisma.followUp.update({
      where: { id: list.items[0].id },
      data: { dueDate: new Date('2020-01-01') },
    });
    const ceo = await Client.login(app, 'ceo@test.uz');
    expect((await ceo.post('/api/v1/automation/jobs/follow-ups/run')).body.result).toEqual({
      notified: 1,
    });
    expect(
      await prisma.notification.count({
        where: { userId: fx.users.manager.id, type: 'followup.due' },
      }),
    ).toBe(1);
    expect(
      (await manager.get('/api/v1/follow-ups?due=true')).body.items[0].overdueDays,
    ).toBeGreaterThan(0);

    const done = await manager.post(`/api/v1/follow-ups/${list.items[2].id}/complete`, {
      status: 'DONE',
      result: 'Хотят продлить на 3 месяца',
      createDeal: true,
    });
    expect(done.status, JSON.stringify(done.body)).toBe(200);
    expect(done.body).toMatchObject({ status: 'DONE', result: 'Хотят продлить на 3 месяца' });
    const deal = await prisma.deal.findUniqueOrThrow({ where: { id: done.body.resultDeal.id } });
    expect(deal).toMatchObject({
      clientId: client.id,
      isRepeat: true,
      ownerId: fx.users.manager.id,
    });
    expect(
      (await manager.post(`/api/v1/follow-ups/${list.items[2].id}/complete`, { status: 'SKIPPED' }))
        .status,
    ).toBe(422);
    expect(
      (
        await manager.post(`/api/v1/follow-ups/${list.items[1].id}/complete`, {
          status: 'SKIPPED',
          createDeal: true,
        })
      ).status,
    ).toBe(422);
  });

  it('интервалы follow-up берутся из настроек; план компании — уведомление один раз', async () => {
    const ceo = await Client.login(app, 'ceo@test.uz');
    const cur = (await ceo.get('/api/v1/settings/automation')).body;
    await ceo.put('/api/v1/settings/automation', {
      ...cur,
      followUps: [{ days: 14, kind: 'CONTACT' }],
    });
    await completedProject();
    const rows = await prisma.followUp.findMany();
    expect(rows).toHaveLength(1);

    const reports = app.get(ReportsService);
    const period = (await reports.planProgress({})).period;
    await prisma.kpiTarget.create({
      data: {
        userId: fx.users.manager.id,
        period,
        metric: 'REVENUE',
        targetValue: 1000,
        currency: 'UZS',
        setById: fx.users.ceo.id,
      },
    });
    const deal = await prisma.deal.findFirstOrThrow();
    await prisma.payment.create({
      data: {
        dealId: deal.id,
        clientId: deal.clientId,
        type: 'FULL',
        method: 'BANK',
        status: 'PAID',
        amount: 5000,
        currency: 'UZS',
        amountUzs: 5000,
        paidAt: new Date(),
        createdById: fx.users.manager.id,
      },
    });
    const publish = () =>
      prisma.$transaction((tx) =>
        app.get(OutboxService).publish(
          tx,
          'payment.paid',
          {
            paymentId: deal.id,
            dealId: deal.id,
            amountUzs: '5000',
            managerId: fx.users.manager.id,
            teamId: fx.teams.team1.id,
            projectId: deal.id,
            projectCreated: false,
          },
          null,
        ),
      );
    await publish();
    await publish();
    await drain();
    expect(
      await prisma.notification.count({
        where: { userId: fx.users.ceo.id, type: 'plan.achieved' },
      }),
    ).toBe(1);
    expect(
      await prisma.notification.count({
        where: { userId: fx.users.rop.id, type: 'plan.achieved' },
      }),
    ).toBe(1);
  });
});

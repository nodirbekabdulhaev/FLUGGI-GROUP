import type { INestApplication } from '@nestjs/common';
import { afterAll, beforeAll, beforeEach, describe, expect, it } from 'vitest';
import type { PrismaService } from '../../core/prisma/prisma.service';
import { Client, createTestApp, resetDatabase } from '../../test/helpers';
import { RemindersService } from '../automation/reminders.service';
import { SchedulerService } from '../automation/scheduler.service';
import { RecurringTodosService } from './recurring.service';

let app: INestApplication;
let prisma: PrismaService;
let fx: Awaited<ReturnType<typeof resetDatabase>>;

beforeAll(async () => ({ app, prisma } = await createTestApp()));
afterAll(() => app.close());
beforeEach(async () => (fx = await resetDatabase(prisma)));

describe('Личные дела («Список дел»)', () => {
  it('дело себе, поручение отделу, связь с клиентом, выполнение, права', async () => {
    const manager = await Client.login(app, 'manager@test.uz');
    const rop = await Client.login(app, 'rop@test.uz');
    const executor = await Client.login(app, 'executor@test.uz');

    // Любой сотрудник ведёт дела себе — даже без прав на проектные задачи
    const own = await executor.post('/api/v1/todos', { title: 'Купить SSD', kind: 'TASK' });
    expect(own.status, JSON.stringify(own.body)).toBe(201);
    expect(own.body).toMatchObject({
      status: 'OPEN',
      owner: { id: fx.users.executor.id },
      can: { update: true },
    });
    expect(own.body.number).toMatch(/^TD-\d{5}$/);

    // РОП поручает менеджеру своего отдела, с клиентом; чужому отделу — нельзя
    const client = await prisma.client.create({
      data: { name: 'Alfa', ownerId: fx.users.manager.id, teamId: fx.teams.team1.id },
    });
    const due = new Date(Date.now() + 86_400_000).toISOString();
    const t = await rop.post('/api/v1/todos', {
      title: 'Позвонить клиенту',
      kind: 'CALL',
      priority: 'HIGH',
      dueAt: due,
      ownerId: fx.users.manager.id,
      clientId: client.id,
    });
    expect(t.status, JSON.stringify(t.body)).toBe(201);
    expect(t.body).toMatchObject({ client: { id: client.id, name: 'Alfa' }, kind: 'CALL' });
    expect(
      await prisma.notification.count({
        where: { userId: fx.users.manager.id, type: 'todo.assigned' },
      }),
    ).toBe(1);
    expect(
      (await rop.post('/api/v1/todos', { title: 'x', ownerId: fx.users.otherManager.id })).status,
    ).toBe(403);
    expect(
      (await manager.post('/api/v1/todos', { title: 'x', ownerId: fx.users.rop.id })).status,
    ).toBe(403);
    // Клиент, которого не видишь, не привязать
    const foreign = await prisma.client.create({
      data: { name: 'Beta', ownerId: fx.users.otherManager.id, teamId: fx.teams.team2.id },
    });
    expect((await manager.post('/api/v1/todos', { title: 'x', clientId: foreign.id })).status).toBe(
      404,
    );

    // «Список дел» менеджера: только личные дела (задачи проектов там не показываются)
    const dock = (await manager.get('/api/v1/todos/dock')).body;
    expect(dock.todos.map((x: { title: string }) => x.title)).toEqual(['Позвонить клиенту']);
    expect(dock).not.toHaveProperty('projectTasks');
    // Чужое дело не видно и не изменить
    expect((await executor.patch(`/api/v1/todos/${t.body.id}`, { title: 'hack' })).status).toBe(
      404,
    );
    expect((await manager.get('/api/v1/todos?view=all')).status).toBe(403);
    // «Все» у РОП — только сотрудники его отдела
    const ceoForOther = await Client.login(app, 'ceo@test.uz');
    await ceoForOther.post('/api/v1/todos', {
      title: 'Дело другого отдела',
      ownerId: fx.users.otherManager.id,
    });
    const ropAll = (await rop.get('/api/v1/todos?view=all')).body;
    expect(ropAll.items.map((x: { title: string }) => x.title)).toContain('Позвонить клиенту');
    expect(ropAll.items.map((x: { title: string }) => x.title)).not.toContain(
      'Дело другого отдела',
    );

    // Выполнение — РОП получает уведомление; повторно — 422; удалить может только автор
    const done = await manager.post(`/api/v1/todos/${t.body.id}/complete`);
    expect(done.body).toMatchObject({ status: 'DONE' });
    expect(done.body.completedAt).toBeTruthy();
    expect(
      await prisma.notification.count({
        where: { userId: fx.users.rop.id, type: 'todo.assigned' },
      }),
    ).toBe(1);
    expect((await manager.post(`/api/v1/todos/${t.body.id}/complete`)).status).toBe(422);
    expect((await manager.delete(`/api/v1/todos/${t.body.id}`)).status).toBe(403);
    expect((await rop.get('/api/v1/todos?view=assigned')).body.total).toBe(1);
    expect((await rop.delete(`/api/v1/todos/${t.body.id}`)).status).toBe(204);
    expect((await rop.get('/api/v1/todos?view=assigned')).body.total).toBe(0);

    // CEO видит все дела компании
    const ceo = await Client.login(app, 'ceo@test.uz');
    expect((await ceo.get('/api/v1/todos?view=all')).body.total).toBe(2);
  });

  it('напоминания: сегодня срок и просрочено — один раз', async () => {
    const manager = await Client.login(app, 'manager@test.uz');
    const overdue = await manager.post('/api/v1/todos', {
      title: 'Отправить счёт',
      dueAt: new Date(Date.now() - 3_600_000).toISOString(),
    });
    expect(overdue.status).toBe(201);
    const reminders = app.get(RemindersService);
    // 10:00 по Ташкенту
    const at10 = new Date(
      `${new Date(Date.now() + 5 * 3_600_000).toISOString().slice(0, 10)}T05:00:00Z`,
    );
    const first = await reminders.run(new Date(Math.max(at10.getTime(), Date.now())));
    expect(first['todo.overdue']).toBe(1);
    expect(
      (await reminders.run(new Date(Math.max(at10.getTime(), Date.now()))))['todo.overdue'],
    ).toBeUndefined();
    const n = await prisma.notification.findFirstOrThrow({ where: { type: 'todo.overdue' } });
    expect(n.title).toBe('⏰ Просрочено: Отправить счёт');
  });
});

describe('Регулярные дела (налоговый календарь)', () => {
  it('календарь IT-Park одним нажатием, дела за 3 дня до срока, без дублей', async () => {
    const ceo = await Client.login(app, 'ceo@test.uz');
    const cal = await ceo.post('/api/v1/recurring-todos/tax-calendar');
    expect(cal.status, JSON.stringify(cal.body)).toBe(200);
    expect(cal.body).toHaveLength(8);
    // Повторно — не дублируется
    expect((await ceo.post('/api/v1/recurring-todos/tax-calendar')).body).toHaveLength(8);
    const turnover = cal.body.find((r: { title: string }) => r.title.startsWith('Налог с оборота'));
    expect(turnover).toMatchObject({
      frequency: 'MONTHLY',
      dayOfMonth: 4,
      remindDaysBefore: 3,
      kind: 'PAYMENT',
    });
    expect(turnover.nextTitle).toMatch(/^Налог с оборота за [а-я]+ \d{4}$/);

    const svc = app.get(RecurringTodosService);
    // 1 октября: до 4-го 3 дня → создаются 3 ежемесячных дела на 4-е + квартальные на 4 октября
    const oct1 = new Date('2026-10-01T03:00:00Z');
    const r1 = await svc.generate(oct1);
    expect(r1.created).toBe(5);
    const todos = await prisma.todo.findMany({
      where: { ownerId: fx.users.ceo.id },
      orderBy: { title: 'asc' },
    });
    expect(todos.map((t) => t.title)).toEqual([
      'Квартальный отчёт IT-Park за III квартал 2026',
      'Налог на доходы работников (НДФЛ) за сентябрь 2026',
      'Налог с оборота за сентябрь 2026',
      'Отчёт IT-Park об обороте за сентябрь 2026',
      'Статотчёт за III квартал 2026',
    ]);
    // Срок — 4 октября 18:00 по Ташкенту
    expect(todos[0]!.dueAt!.toISOString()).toBe('2026-10-04T13:00:00.000Z');
    expect(
      await prisma.notification.count({
        where: { userId: fx.users.ceo.id, type: 'todo.recurring' },
      }),
    ).toBe(5);
    // Повторный запуск в тот же период — без дублей; 7-го — ИНПС (10-е)
    expect((await svc.generate(oct1)).created).toBe(0);
    expect((await svc.generate(new Date('2026-10-07T03:00:00Z'))).created).toBe(1);
    // Декабрь: баланс за текущий год
    await svc.generate(new Date('2026-11-28T03:00:00Z'));
    expect(
      await prisma.todo.count({
        where: { title: 'Баланс (форма №1) и отчёт о финансовых результатах (форма №2) за 2026' },
      }),
    ).toBe(1);

    // Своё правило; выключенное — не создаёт дел; чужие правила не видны
    const own = await ceo.post('/api/v1/recurring-todos', {
      title: 'Оплатить аренду за {месяц}',
      kind: 'PAYMENT',
      frequency: 'MONTHLY',
      dayOfMonth: 25,
      remindDaysBefore: 2,
    });
    expect(own.status, JSON.stringify(own.body)).toBe(201);
    const off = await ceo.put(`/api/v1/recurring-todos/${own.body.id}`, {
      ...own.body,
      ownerId: undefined,
      isActive: false,
    });
    expect(off.status, JSON.stringify(off.body)).toBe(200);
    expect((await svc.generate(new Date('2026-10-23T03:00:00Z'))).created).toBe(0);
    const manager = await Client.login(app, 'manager@test.uz');
    expect((await manager.get('/api/v1/recurring-todos')).body).toEqual([]);
    expect((await manager.delete(`/api/v1/recurring-todos/${own.body.id}`)).status).toBe(404);
    expect(
      (
        await ceo.post('/api/v1/recurring-todos', {
          title: 'x',
          frequency: 'QUARTERLY',
          dayOfMonth: 4,
          month: 5,
        })
      ).status,
    ).toBe(422);
    // Задача планировщика
    const run = await app.get(SchedulerService).runNow('recurring-todos');
    expect(run?.error).toBeNull();
  });
});

describe('Чат сотрудников', () => {
  it('личная переписка, непрочитанные, Telegram — один раз до прочтения', async () => {
    const manager = await Client.login(app, 'manager@test.uz');
    const rop = await Client.login(app, 'rop@test.uz');
    const executor = await Client.login(app, 'executor@test.uz');
    await prisma.user.update({ where: { id: fx.users.rop.id }, data: { telegramChatId: '555' } });
    process.env.TELEGRAM_BOT_TOKEN = '1:x';
    const { resetEnvCache } = await import('../../config/env');
    resetEnvCache();
    try {
      const contacts = (await manager.get('/api/v1/chats/contacts')).body;
      expect(contacts.some((c: { id: string }) => c.id === fx.users.manager.id)).toBe(false);
      expect(contacts.some((c: { id: string }) => c.id === fx.users.rop.id)).toBe(true);
      expect(
        (await manager.post('/api/v1/chats/direct', { userId: fx.users.manager.id })).status,
      ).toBe(422);

      const c = await manager.post('/api/v1/chats/direct', { userId: fx.users.rop.id });
      expect(c.status).toBe(200);
      // Та же переписка с другой стороны
      expect(
        (await rop.post('/api/v1/chats/direct', { userId: fx.users.manager.id })).body.id,
      ).toBe(c.body.id);

      expect(
        (await manager.post(`/api/v1/chats/${c.body.id}/messages`, { body: 'Привет!' })).status,
      ).toBe(201);
      await manager.post(`/api/v1/chats/${c.body.id}/messages`, { body: 'Как дела по Alfa?' });
      expect((await rop.get('/api/v1/chats/unread')).body).toEqual({ count: 2 });
      expect((await manager.get('/api/v1/chats/unread')).body).toEqual({ count: 0 });
      // Telegram РОП — одно уведомление на два сообщения, в CRM-колокольчик не дублируется
      expect(
        await prisma.notificationDelivery.count({
          where: { userId: fx.users.rop.id, type: 'chat.message' },
        }),
      ).toBe(1);
      expect(await prisma.notification.count({ where: { type: 'chat.message' } })).toBe(0);

      const list = (await rop.get('/api/v1/chats')).body;
      expect(list[0]).toMatchObject({
        peer: { id: fx.users.manager.id },
        unread: 2,
        lastMessage: { body: 'Как дела по Alfa?', mine: false },
      });
      const msgs = (await rop.get(`/api/v1/chats/${c.body.id}/messages`)).body;
      expect(msgs.map((m: { body: string }) => m.body)).toEqual(['Привет!', 'Как дела по Alfa?']);
      expect((await rop.post(`/api/v1/chats/${c.body.id}/read`)).status).toBe(204);
      expect((await rop.get('/api/v1/chats/unread')).body).toEqual({ count: 0 });
      // После прочтения — новое уведомление на следующее сообщение
      await manager.post(`/api/v1/chats/${c.body.id}/messages`, { body: 'Ещё вопрос' });
      expect(
        await prisma.notificationDelivery.count({
          where: { userId: fx.users.rop.id, type: 'chat.message' },
        }),
      ).toBe(2);

      // Посторонний не читает чужую переписку
      expect((await executor.get(`/api/v1/chats/${c.body.id}/messages`)).status).toBe(404);
      expect(
        (await executor.post(`/api/v1/chats/${c.body.id}/messages`, { body: 'x' })).status,
      ).toBe(404);
      expect((await executor.get('/api/v1/chats')).body).toEqual([]);
    } finally {
      delete process.env.TELEGRAM_BOT_TOKEN;
      resetEnvCache();
    }
  });
});

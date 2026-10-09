import type { INestApplication } from '@nestjs/common';
import request from 'supertest';
import { afterAll, beforeAll, beforeEach, describe, expect, it } from 'vitest';
import { OutboxDispatcher } from '../../core/outbox/outbox.dispatcher';
import type { PrismaService } from '../../core/prisma/prisma.service';
import { Client, createTestApp, resetDatabase } from '../../test/helpers';
import { OverdueScanner } from './overdue.runner';

let app: INestApplication;
let prisma: PrismaService;
let fx: Awaited<ReturnType<typeof resetDatabase>>;

beforeAll(async () => ({ app, prisma } = await createTestApp()));
afterAll(() => app.close());
beforeEach(async () => (fx = await resetDatabase(prisma)));

const drain = async () => {
  while ((await app.get(OutboxDispatcher).processBatch()) > 0);
};

/**
 * Полный путь до оплаты: лид (услуга SMM) → сделка → КП → договор → оплата → подтверждение РОП.
 * Возвращает id проекта, созданного автоматически (ТЗ §19).
 */
async function paidProject(manager: Client, rop: Client) {
  const refs = (await manager.get('/api/v1/references')).body;
  const smm = refs.services.find((s: { code: string }) => s.code === 'SMM');
  const lead = await manager.post('/api/v1/leads', {
    contactName: 'Aziz',
    companyName: 'Aziz Market',
    phone: '+998901112233',
    sourceId: refs.sources[0].id,
    serviceId: smm.id,
  });
  const conv = await rop.post(`/api/v1/leads/${lead.body.id}/convert`, {
    clientName: 'Aziz Market',
    amount: '6000000',
  });
  const dealId = conv.body.dealId as string;
  const kp = await manager.post('/api/v1/proposals', {
    dealId,
    title: 'SMM',
    items: [{ serviceId: smm.id, description: 'SMM', quantity: 1, unitPrice: '6000000' }],
  });
  await manager.post(`/api/v1/proposals/${kp.body.id}/send`);
  await manager.post(`/api/v1/proposals/${kp.body.id}/accept`);
  const contract = await manager.post('/api/v1/contracts', {
    dealId,
    proposalId: kp.body.id,
    contractDate: '2026-10-06',
    amount: '6000000',
  });
  await manager.post(`/api/v1/contracts/${contract.body.id}/sign`);
  const pay = await manager.post('/api/v1/payments', {
    dealId,
    amount: '6000000',
    type: 'FULL',
    method: 'BANK',
  });
  const confirmed = await rop.post(`/api/v1/payments/${pay.body.id}/confirm`, {});
  expect(confirmed.status, JSON.stringify(confirmed.body)).toBe(200);
  return confirmed.body.project.id as string;
}

type TaskRow = { id: string; title: string; status: string; assignee: { id: string } };

describe('Проект из оплаты, команда и задачи (§84, шаги 16–21)', () => {
  it('шаблон услуги → назначение исполнителя → Kanban → проверка → завершение проекта', async () => {
    const manager = await Client.login(app, 'manager@test.uz');
    const rop = await Client.login(app, 'rop@test.uz');
    const projectId = await paidProject(manager, rop);

    // ТЗ §19 п.6: базовый чек-лист из шаблона SMM, задачи ждут исполнителей
    let p = (await rop.get(`/api/v1/projects/${projectId}`)).body;
    expect(p.status).toBe('NEW');
    expect(p.template.name).toBe('SMM-проект');
    expect(p.tasks).toEqual({ total: 8, done: 0, overdue: 0 });
    expect(p.can).toEqual({ canUpdate: true, canAssign: true, canCreateTasks: true });
    expect(p.price).toBe('6000000.00');
    // ТЗ §77, Rule 5: исполнитель есть всегда — пока роли нет в команде, задача у РОП
    const initial = (await rop.get(`/api/v1/tasks?projectId=${projectId}`)).body.items;
    expect(new Set(initial.map((t: TaskRow) => t.assignee.id))).toEqual(new Set([fx.users.rop.id]));
    expect(initial.every((t: { waitingForRole: boolean }) => t.waitingForRole)).toBe(true);
    expect((await rop.post('/api/v1/tasks', { projectId, title: 'Без исполнителя' })).status).toBe(
      422,
    );

    // Шаг 17: РОП назначает исполнителя SMM → его задачи из шаблона переходят к нему
    const add = await rop.post(`/api/v1/projects/${projectId}/members`, {
      userId: fx.users.executor.id,
      role: 'SMM',
      workloadPct: 50,
    });
    expect(add.status, JSON.stringify(add.body)).toBe(201);
    expect(add.body.status).toBe('PLANNING');
    expect(add.body.members[0]).toMatchObject({ role: 'SMM', workloadPct: 50, status: 'ACTIVE' });
    expect(add.body.members[0].tasks.total).toBe(3);
    expect(
      (await rop.post(`/api/v1/projects/${projectId}/members`, { userId: fx.users.executor.id }))
        .status,
    ).toBe(422);

    // Шаг 19: исполнитель получает уведомление
    await drain();
    const exNotes = await prisma.notification.findMany({ where: { userId: fx.users.executor.id } });
    expect(exNotes.map((n) => n.type)).toContain('project.member_added');

    // Исполнитель видит проект, но не деньги и не сделку; видит только свои задачи
    const ex = await Client.login(app, 'executor@test.uz');
    const exProject = (await ex.get(`/api/v1/projects/${projectId}`)).body;
    expect(exProject.price).toBeNull();
    expect(exProject.deal).toBeNull();
    expect(exProject.can).toEqual({ canUpdate: false, canAssign: false, canCreateTasks: false });
    const exTasks: TaskRow[] = (await ex.get(`/api/v1/tasks?projectId=${projectId}`)).body.items;
    expect(exTasks.map((t) => t.title).sort()).toEqual(['Контент-план', 'Отчёт', 'Публикация']);
    expect((await ex.get(`/api/v1/deals/${p.deal.id}`)).status).toBe(403);
    expect((await ex.post('/api/v1/tasks', { projectId, title: 'Своя задача' })).status).toBe(403);

    // Шаг 18: менеджер проекта создаёт задачу исполнителю
    const created = await manager.post('/api/v1/tasks', {
      projectId,
      title: 'Согласовать рубрики',
      assigneeId: fx.users.executor.id,
      priority: 'HIGH',
    });
    expect(created.status, JSON.stringify(created.body)).toBe(201);
    expect(created.body.number).toMatch(/^T-\d{5}$/);
    // Ответственный — только из команды проекта
    expect(
      (
        await manager.post('/api/v1/tasks', {
          projectId,
          title: 'x',
          assigneeId: fx.users.otherManager.id,
        })
      ).status,
    ).toBe(422);
    await drain();
    expect(
      await prisma.notification.count({
        where: { userId: fx.users.executor.id, type: 'task.assigned' },
      }),
    ).toBe(1);

    // Шаг 20: Kanban — исполнитель берёт задачу в работу → проект «В работе»
    const plan = exTasks.find((t) => t.title === 'Контент-план')!;
    const moved = await ex.post(`/api/v1/tasks/${plan.id}/move`, { status: 'IN_PROGRESS' });
    expect(moved.status, JSON.stringify(moved.body)).toBe(200);
    expect(moved.body.startedAt).not.toBeNull();
    expect((await rop.get(`/api/v1/projects/${projectId}`)).body.status).toBe('IN_PROGRESS');
    // На проверку → РОП возвращает → переделка
    await ex.post(`/api/v1/tasks/${plan.id}/move`, { status: 'REVIEW' });
    await drain();
    expect(
      await prisma.notification.count({ where: { userId: fx.users.rop.id, type: 'task.review' } }),
    ).toBe(1);
    const back = await rop.post(`/api/v1/tasks/${plan.id}/move`, { status: 'IN_PROGRESS' });
    expect(back.body.reworkCount).toBe(1);
    // Исполнитель не может отменить задачу, менять чужую задачу и поля задачи
    expect((await ex.post(`/api/v1/tasks/${plan.id}/move`, { status: 'CANCELLED' })).status).toBe(
      422,
    );
    const foreign = (await rop.get(`/api/v1/tasks?projectId=${projectId}`)).body.items.find(
      (t: TaskRow) => t.title === 'Съёмка',
    );
    expect((await ex.post(`/api/v1/tasks/${foreign.id}/move`, { status: 'DONE' })).status).toBe(
      404,
    );
    expect((await ex.patch(`/api/v1/tasks/${plan.id}`, { title: 'Иначе' })).status).toBe(403);
    expect((await ex.patch(`/api/v1/tasks/${plan.id}`, { progressPct: 60 })).body.progressPct).toBe(
      60,
    );
    // Комментарий и история статусов
    expect(
      (await ex.post(`/api/v1/tasks/${plan.id}/comments`, { body: 'Готово к проверке' })).status,
    ).toBe(201);
    const detail = (await ex.get(`/api/v1/tasks/${plan.id}`)).body;
    expect(detail.comments[0].body).toBe('Готово к проверке');
    expect(detail.history.map((h: { to: string }) => h.to)).toEqual([
      'IN_PROGRESS',
      'REVIEW',
      'IN_PROGRESS',
      'TODO',
    ]);

    // Порядок в колонке: новая задача встаёт перед «Контент-план»
    const second = await rop.post('/api/v1/tasks', {
      projectId,
      title: 'Срочная правка',
      assigneeId: fx.users.rop.id,
    });
    await rop.post(`/api/v1/tasks/${second.body.id}/move`, {
      status: 'TODO',
      beforeId: (await rop.get(`/api/v1/tasks?projectId=${projectId}&status=TODO`)).body.items[0]
        .id,
    });
    const todo: TaskRow[] = (await rop.get(`/api/v1/tasks?projectId=${projectId}&status=TODO`)).body
      .items;
    expect(todo[0]!.title).toBe('Срочная правка');

    // Шаг 21: завершить проект можно, только когда открытых задач нет
    const blocked = await rop.post(`/api/v1/projects/${projectId}/status`, { status: 'COMPLETED' });
    expect(blocked.status).toBe(422);
    expect(blocked.body.error.message).toMatch(/открытых задач/);
    const all: TaskRow[] = (await rop.get(`/api/v1/tasks?projectId=${projectId}&pageSize=100`)).body
      .items;
    for (const t of all) {
      const r = await rop.post(`/api/v1/tasks/${t.id}/move`, { status: 'DONE' });
      expect(r.status, `${t.title}: ${JSON.stringify(r.body)}`).toBe(200);
    }
    p = (await rop.post(`/api/v1/projects/${projectId}/status`, { status: 'COMPLETED' })).body;
    expect(p.status).toBe('COMPLETED');
    expect(p.completedAt).not.toBeNull();
    expect(p.members[0].status).toBe('DONE');
    // Закрытый проект: задачи не меняются
    expect(
      (await rop.post('/api/v1/tasks', { projectId, title: 'Ещё', assigneeId: fx.users.rop.id }))
        .status,
    ).toBe(422);
    await drain();
    expect(
      await prisma.notification.count({
        where: { userId: fx.users.manager.id, type: 'project.completed' },
      }),
    ).toBe(1);

    // Таймлайн проекта
    const timeline = (await rop.get(`/api/v1/projects/${projectId}/timeline`)).body;
    const types = timeline.map((a: { type: string }) => a.type);
    expect(types).toEqual(
      expect.arrayContaining([
        'project.created',
        'project.template_applied',
        'project.member_added',
        'task.status_changed',
        'task.created',
      ]),
    );
  });

  it('разграничение: чужой отдел не видит проект, менеджер не меняет статус, отмена с причиной', async () => {
    const manager = await Client.login(app, 'manager@test.uz');
    const rop = await Client.login(app, 'rop@test.uz');
    const projectId = await paidProject(manager, rop);

    const other = await Client.login(app, 'manager2@test.uz');
    expect((await other.get(`/api/v1/projects/${projectId}`)).status).toBe(404);
    expect((await other.get('/api/v1/projects')).body.total).toBe(0);
    expect((await other.get(`/api/v1/tasks?projectId=${projectId}`)).body.total).toBe(0);

    expect(
      (await manager.post(`/api/v1/projects/${projectId}/status`, { status: 'PAUSED' })).status,
    ).toBe(403);
    expect(
      (
        await manager.post(`/api/v1/projects/${projectId}/members`, {
          userId: fx.users.executor.id,
        })
      ).status,
    ).toBe(403);
    // Сменить РОП проекта может только CEO
    expect(
      (await rop.patch(`/api/v1/projects/${projectId}`, { ropId: fx.users.ceo.id })).status,
    ).toBe(403);
    const ceo = await Client.login(app, 'ceo@test.uz');
    expect(
      (await ceo.patch(`/api/v1/projects/${projectId}`, { ropId: fx.users.ceo.id })).status,
    ).toBe(200);
    const bad = await ceo.patch(`/api/v1/projects/${projectId}`, {
      startDate: '2026-10-10',
      deadline: '2026-10-01',
    });
    expect(bad.status).toBe(422);

    expect(
      (await ceo.post(`/api/v1/projects/${projectId}/status`, { status: 'CANCELLED' })).status,
    ).toBe(422);
    const cancelled = await ceo.post(`/api/v1/projects/${projectId}/status`, {
      status: 'CANCELLED',
      comment: 'Клиент отказался',
    });
    expect(cancelled.body.status).toBe('CANCELLED');
    expect((await ceo.get('/api/v1/projects?view=active')).body.total).toBe(0);
    const audit = await prisma.auditLog.findFirst({ where: { action: 'project.status' } });
    expect(audit?.entityId).toBe(projectId);
  });

  it('просроченные задачи: представления и уведомления на 1, 3 и 7 день (ТЗ §24)', async () => {
    const manager = await Client.login(app, 'manager@test.uz');
    const rop = await Client.login(app, 'rop@test.uz');
    const projectId = await paidProject(manager, rop);
    await rop.post(`/api/v1/projects/${projectId}/members`, { userId: fx.users.executor.id });

    const deadline = new Date(Date.now() - 2 * 3_600_000).toISOString();
    const t = await rop.post('/api/v1/tasks', {
      projectId,
      title: 'Просроченная',
      assigneeId: fx.users.executor.id,
      deadline,
    });
    expect(t.body.overdueDays).toBe(1);
    // Остальные задачи (из шаблона) — с далёким дедлайном, чтобы не мешали подсчёту
    await prisma.task.updateMany({
      where: { projectId, id: { not: t.body.id } },
      data: { deadline: new Date(Date.now() + 60 * 86_400_000) },
    });

    const ex = await Client.login(app, 'executor@test.uz');
    const overdue = (await ex.get('/api/v1/tasks?view=overdue')).body.items;
    expect(overdue.map((x: TaskRow) => x.title)).toEqual(['Просроченная']);
    expect((await ex.get('/api/v1/tasks?view=today')).body.total).toBeGreaterThanOrEqual(1);
    expect((await rop.get('/api/v1/projects?view=overdue')).body.total).toBe(1);
    expect((await rop.get(`/api/v1/projects/${projectId}`)).body.tasks.overdue).toBe(1);

    const scanner = app.get(OverdueScanner);
    const now = new Date();
    expect(await scanner.scan(now)).toBe(1);
    expect(await scanner.scan(now)).toBe(0); // без дублей
    expect(await scanner.scan(new Date(now.getTime() + 86_400_000))).toBe(0); // 2-й день — ещё нет
    expect(await scanner.scan(new Date(now.getTime() + 2 * 86_400_000))).toBe(1); // 3-й день
    await drain();
    const notes = await prisma.notification.findMany({
      where: { userId: fx.users.executor.id, type: 'task.overdue' },
      orderBy: { createdAt: 'asc' },
    });
    expect(notes.map((n) => n.title)).toEqual(['Просрочено 1 день', 'Просрочено 3 дня']);
    expect(
      await prisma.notification.count({ where: { userId: fx.users.rop.id, type: 'task.overdue' } }),
    ).toBe(2);

    // Выполненная задача больше не просрочена
    await ex.post(`/api/v1/tasks/${t.body.id}/move`, { status: 'DONE' });
    expect((await ex.get('/api/v1/tasks?view=overdue')).body.total).toBe(0);
    await scanner.scan(new Date(now.getTime() + 7 * 86_400_000));
    await drain();
    expect(
      await prisma.notification.count({
        where: { userId: fx.users.executor.id, type: 'task.overdue' },
      }),
    ).toBe(2);
  });

  it('шаблоны: CEO создаёт, РОП применяет; файлы задачи загружает исполнитель', async () => {
    const manager = await Client.login(app, 'manager@test.uz');
    const rop = await Client.login(app, 'rop@test.uz');
    const projectId = await paidProject(manager, rop);
    const ceo = await Client.login(app, 'ceo@test.uz');

    expect(
      (await rop.post('/api/v1/project-templates', { name: 'x', tasks: [{ title: 'a' }] })).status,
    ).toBe(403);
    const tpl = await ceo.post('/api/v1/project-templates', {
      name: 'Фотосессия',
      tasks: [
        { title: 'Съёмка', role: 'PHOTOGRAPHER', startOffsetDays: 1, durationDays: 1 },
        { title: 'Ретушь', role: 'DESIGNER', startOffsetDays: 2, durationDays: 3 },
      ],
    });
    expect(tpl.status, JSON.stringify(tpl.body)).toBe(201);
    const list = (await rop.get('/api/v1/project-templates')).body;
    expect(list.map((t: { name: string }) => t.name)).toContain('Фотосессия');
    expect((await manager.get('/api/v1/project-templates')).status).toBe(403);

    const applied = await rop.post(`/api/v1/projects/${projectId}/apply-template`, {
      templateId: tpl.body.id,
    });
    expect(applied.status, JSON.stringify(applied.body)).toBe(200);
    expect(applied.body.tasks.total).toBe(10);
    const retouch = await prisma.task.findFirstOrThrow({ where: { title: 'Ретушь' } });
    const project = await prisma.project.findUniqueOrThrow({ where: { id: projectId } });
    const start = project.startDate!.getTime();
    expect(retouch.startDate!.getTime() - start).toBe(2 * 86_400_000);
    // дедлайн: старт + 2 + (3 − 1) дня, 18:00 по Ташкенту (13:00 UTC)
    expect(retouch.deadline!.getTime() - start).toBe(4 * 86_400_000 + 13 * 3_600_000);

    // Файл к задаче: исполнитель-ответственный загружает, чужой менеджер не видит
    await rop.post(`/api/v1/projects/${projectId}/members`, {
      userId: fx.users.executor.id,
      role: 'DESIGNER',
    });
    const ex = await Client.login(app, 'executor@test.uz');
    const cookies = (ex as unknown as { cookies: Map<string, string> }).cookies;
    const png = Buffer.from('89504e470d0a1a0a0000000d4948445200000001000000010806000000', 'hex');
    const up = await request(app.getHttpServer())
      .post('/api/v1/files')
      .set('Cookie', [...cookies].map(([k, v]) => `${k}=${v}`).join('; '))
      .set('x-csrf-token', cookies.get('fluggi_csrf')!)
      .field('taskId', retouch.id)
      .field('category', 'DESIGN')
      .attach('file', png, { filename: 'макет.png', contentType: 'image/png' });
    expect(up.status, JSON.stringify(up.body)).toBe(201);
    expect((await ex.get(`/api/v1/files?taskId=${retouch.id}`)).body).toHaveLength(1);
    expect((await rop.get(`/api/v1/files/${up.body.id}/download`)).status).toBe(200);
    const other = await Client.login(app, 'manager2@test.uz');
    expect((await other.get(`/api/v1/files/${up.body.id}/download`)).status).toBe(404);

    // Задача шаблона перешла к дизайнеру; исключили из команды — вернулась к РОП
    expect((await prisma.task.findUniqueOrThrow({ where: { id: retouch.id } })).assigneeId).toBe(
      fx.users.executor.id,
    );
    const team = (await rop.get(`/api/v1/projects/${projectId}`)).body.members;
    const removed = await rop.patch(`/api/v1/projects/${projectId}/members/${team[0].id}`, {
      status: 'REMOVED',
    });
    expect(removed.status, JSON.stringify(removed.body)).toBe(200);
    expect((await prisma.task.findUniqueOrThrow({ where: { id: retouch.id } })).assigneeId).toBe(
      fx.users.rop.id,
    );
    // Исключённый участник больше не видит проект и его задачи
    expect((await ex.get(`/api/v1/projects/${projectId}`)).status).toBe(404);
    expect((await ex.get(`/api/v1/tasks?projectId=${projectId}`)).body.total).toBe(0);
  });
});

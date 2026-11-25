import type { INestApplication } from '@nestjs/common';
import { afterAll, beforeAll, beforeEach, describe, expect, it } from 'vitest';
import type { PrismaService } from '../../core/prisma/prisma.service';
import { Client, createTestApp, resetDatabase } from '../../test/helpers';

let app: INestApplication;
let prisma: PrismaService;
let fx: Awaited<ReturnType<typeof resetDatabase>>;

beforeAll(async () => ({ app, prisma } = await createTestApp()));
afterAll(() => app.close());
beforeEach(async () => (fx = await resetDatabase(prisma)));

/** Проект с услугой заданного кода (направление — из услуги). */
async function projectOf(serviceCode: string, name: string) {
  const service = await prisma.service.findUniqueOrThrow({ where: { code: serviceCode } });
  const stage = await prisma.dealStage.findFirstOrThrow({ where: { entity: 'DEAL' } });
  const client = await prisma.client.create({
    data: { name: `${name} LLC`, ownerId: fx.users.manager.id, teamId: fx.teams.team1.id },
  });
  const deal = await prisma.deal.create({
    data: {
      title: name,
      clientId: client.id,
      ownerId: fx.users.manager.id,
      teamId: fx.teams.team1.id,
      serviceId: service.id,
      amount: 1000000,
      amountUzs: 1000000,
      stageId: stage.id,
      createdById: fx.users.manager.id,
    },
  });
  return prisma.project.create({
    data: {
      name,
      clientId: client.id,
      dealId: deal.id,
      price: 1000000,
      priceUzs: 1000000,
      ropId: fx.users.rop.id,
      managerId: fx.users.manager.id,
      teamId: fx.teams.team1.id,
      directionId: service.directionId,
    },
  });
}

describe('Направления бизнеса и проект-менеджер', () => {
  it('проект-менеджер «Медиа» видит и ведёт только медиа-проекты', async () => {
    const ceo = await Client.login(app, 'ceo@test.uz');
    const refs = (await ceo.get('/api/v1/references')).body;
    const media = refs.directions.find((d: { code: string }) => d.code === 'MEDIA');
    expect(media).toBeTruthy();
    expect(refs.services.find((s: { code: string }) => s.code === 'SMM').directionId).toBe(
      media.id,
    );

    const created = await ceo.post('/api/v1/users', {
      email: 'pm@test.uz',
      fullName: 'Проект Менеджер',
      roleCode: 'PROJECT_MANAGER',
      directionIds: [media.id],
      password: 'TestPass2026!',
    });
    expect(created.status, JSON.stringify(created.body)).toBe(201);
    expect(created.body.user.directions).toEqual([{ id: media.id, name: media.name }]);

    const smm = await projectOf('SMM', 'SMM для кафе');
    const site = await projectOf('WEBSITE', 'Сайт для клиники');

    const pm = await Client.login(app, 'pm@test.uz', 'TestPass2026!');
    const list = (await pm.get('/api/v1/projects')).body;
    expect(list.items.map((p: { name: string }) => p.name)).toEqual(['SMM для кафе']);
    expect(list.items[0].direction).toEqual({ id: media.id, name: media.name });
    expect((await pm.get(`/api/v1/projects/${site.id}`)).status).toBe(404);

    // Ведёт проект: меняет дедлайн, ставит задачи; направление сменить не может
    const upd = await pm.patch(`/api/v1/projects/${smm.id}`, { deadline: '2026-12-31' });
    expect(upd.status, JSON.stringify(upd.body)).toBe(200);
    expect((await pm.patch(`/api/v1/projects/${smm.id}`, { directionId: null })).status).toBe(403);
    const member = await pm.post(`/api/v1/projects/${smm.id}/members`, {
      userId: fx.users.executor.id,
    });
    expect(member.status, JSON.stringify(member.body)).toBe(201);
    const task = await pm.post('/api/v1/tasks', {
      projectId: smm.id,
      title: 'Контент-план',
      assigneeId: fx.users.executor.id,
    });
    expect(task.status, JSON.stringify(task.body)).toBe(201);
    expect(
      (
        await pm.post('/api/v1/tasks', {
          projectId: site.id,
          title: 'Вёрстка',
          assigneeId: fx.users.executor.id,
        })
      ).status,
    ).not.toBe(201);

    // Дашборд проект-менеджера
    const dash = (await pm.get('/api/v1/dashboard')).body;
    expect(dash.projects).toMatchObject({ directions: [media.name], active: 1, tasksOpen: 1 });

    // CEO переводит сайт в «Медиа» — проект появляется у проект-менеджера
    expect((await ceo.patch(`/api/v1/projects/${site.id}`, { directionId: media.id })).status).toBe(
      200,
    );
    expect((await pm.get('/api/v1/projects')).body.total).toBe(2);
  });

  it('цену тарифа в КП меняет только РОП; себестоимость исполнителей — по тарифу', async () => {
    const manager = await Client.login(app, 'manager@test.uz');
    const rop = await Client.login(app, 'rop@test.uz');
    const refs = (await manager.get('/api/v1/references')).body;
    const smm = (await manager.get('/api/v1/tariffs')).body.find(
      (t: { service: { name: string }; name: string }) => t.name === 'Эконом',
    );
    const lead = await manager.post('/api/v1/leads', {
      contactName: 'Price',
      phone: '+998901112244',
      sourceId: refs.sources[0].id,
      serviceId: smm.service.id,
    });
    const conv = await rop.post(`/api/v1/leads/${lead.body.id}/convert`, {
      clientName: 'Price LLC',
      amount: '8222500',
    });
    const dealId = conv.body.dealId as string;
    const item = (unitPrice: string, discountPct = 0) => ({
      tariffId: smm.id,
      description: 'SMM — Эконом',
      quantity: 1,
      unitPrice,
      discountPct,
    });
    // 650 USD × 12 650 = 8 222 500 сум — по тарифу можно
    const ok = await manager.post('/api/v1/proposals', {
      dealId,
      title: 'SMM',
      items: [item('8222500')],
    });
    expect(ok.status, JSON.stringify(ok.body)).toBe(201);
    for (const bad of [item('7000000'), item('8222500', 10)]) {
      const r = await manager.post('/api/v1/proposals', { dealId, title: 'SMM', items: [bad] });
      expect(r.status).toBe(422);
      expect(r.body.error.message).toBe('Цену тарифа меняет РОП');
    }
    // РОП меняет цену
    const byRop = await rop.post('/api/v1/proposals', {
      dealId,
      title: 'SMM -10%',
      items: [item('7400000')],
    });
    expect(byRop.status, JSON.stringify(byRop.body)).toBe(201);
    expect(byRop.body.total).toBe('7400000.00');
  });
});

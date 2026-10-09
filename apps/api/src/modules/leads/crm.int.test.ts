import type { INestApplication } from '@nestjs/common';
import { afterAll, beforeAll, beforeEach, describe, expect, it } from 'vitest';
import { OutboxDispatcher } from '../../core/outbox/outbox.dispatcher';
import type { PrismaService } from '../../core/prisma/prisma.service';
import { Client, createTestApp, resetDatabase } from '../../test/helpers';

let app: INestApplication;
let prisma: PrismaService;
let fx: Awaited<ReturnType<typeof resetDatabase>>;

beforeAll(async () => ({ app, prisma } = await createTestApp()));
afterAll(() => app.close());
beforeEach(async () => (fx = await resetDatabase(prisma)));

async function refs(c: Client) {
  const r = await c.get('/api/v1/references');
  const id = (list: { id: string; code: string }[], code: string) =>
    list.find((x) => x.code === code)!.id;
  return {
    source: id(r.body.sources, 'INSTAGRAM'),
    service: id(r.body.services, 'SMM'),
    lossOther: id(r.body.lossReasons, 'OTHER'),
    lossPrice: id(r.body.lossReasons, 'TOO_EXPENSIVE'),
  };
}

async function newLead(c: Client, extra: object = {}) {
  const r = await refs(c);
  const res = await c.post('/api/v1/leads', {
    contactName: 'Hamza',
    companyName: 'Hamza Textile',
    phone: '+998901234567',
    sourceId: r.source,
    serviceId: r.service,
    budget: '9000000',
    ...extra,
  });
  expect(res.status, JSON.stringify(res.body)).toBe(201);
  return res.body;
}

const processEvents = () => app.get(OutboxDispatcher).processBatch();

describe('Lead → Deal (сценарий §84, шаги 3–8)', () => {
  it('менеджер создаёт лид, назначает встречу, РОП получает уведомление, квалифицирует лид в сделку', async () => {
    const manager = await Client.login(app, 'manager@test.uz');
    const lead = await newLead(manager);
    expect(lead.number).toBe('L-00001');
    expect(lead.stage.code).toBe('NEW');
    expect(lead.owner.id).toBe(fx.users.manager.id);
    expect(lead.title).toBe('Hamza Textile — SMM');
    expect(['LOW', 'MEDIUM', 'HIGH', 'HOT']).toContain(lead.scoreLevel);

    // Шаг 4: лид в воронке
    const pipeline = await manager.get('/api/v1/pipeline');
    const newCol = pipeline.body.columns.find(
      (c: { stage: { code: string } }) => c.stage.code === 'NEW',
    );
    expect(newCol.items.map((i: { id: string }) => i.id)).toContain(lead.id);

    // Нельзя «перепрыгнуть» на «Назначена встреча» без встречи
    const jump = await manager.post(`/api/v1/leads/${lead.id}/stage`, {
      stageCode: 'MEETING_SCHEDULED',
    });
    expect(jump.status).toBe(422);

    // Шаг 5–6: встреча → лид на этапе «Назначена встреча», РОП получает уведомление
    const meeting = await manager.post('/api/v1/meetings', {
      leadId: lead.id,
      startsAt: new Date(Date.now() + 86_400_000).toISOString(),
      type: 'OFFLINE',
    });
    expect(meeting.status, JSON.stringify(meeting.body)).toBe(201);
    expect(meeting.body.rop.id).toBe(fx.users.rop.id);
    expect((await manager.get(`/api/v1/leads/${lead.id}`)).body.stage.code).toBe(
      'MEETING_SCHEDULED',
    );

    await processEvents();
    const rop = await Client.login(app, 'rop@test.uz');
    const notes = await rop.get('/api/v1/notifications');
    expect(notes.body.items.map((n: { type: string }) => n.type)).toContain('meeting.created');
    expect((await rop.get('/api/v1/notifications/unread-count')).body.count).toBeGreaterThan(0);

    // Шаг 7: встреча проведена → лид на этапе «Встреча проведена»
    const done = await manager.post(`/api/v1/meetings/${meeting.body.id}/complete`, {
      result: 'Нужен SMM на 3 месяца',
    });
    expect(done.status).toBe(200);
    expect((await manager.get(`/api/v1/leads/${lead.id}`)).body.stage.code).toBe('MEETING_DONE');

    // Шаг 8: РОП квалифицирует → клиент + сделка
    const conv = await rop.post(`/api/v1/leads/${lead.id}/convert`, {
      clientName: 'Hamza Textile',
      amount: '9000000',
      currency: 'UZS',
    });
    expect(conv.status, JSON.stringify(conv.body)).toBe(200);
    const deal = (await manager.get(`/api/v1/deals/${conv.body.dealId}`)).body;
    expect(deal.stage.code).toBe('NEED_DEFINED');
    expect(deal.owner.id).toBe(fx.users.manager.id);
    expect(deal.amountUzs).toBe('9000000.00');
    expect(deal.leadId).toBe(lead.id);

    const client = (await manager.get(`/api/v1/clients/${conv.body.clientId}`)).body;
    expect(client.contacts[0].fullName).toBe('Hamza');
    expect(client.deals).toHaveLength(1);

    const convertedLead = (await manager.get(`/api/v1/leads/${lead.id}`)).body;
    expect(convertedLead.status).toBe('CONVERTED');

    // Таймлайн сделки включает историю лида; история этапов сохранена
    const timeline = await manager.get(`/api/v1/timeline?dealId=${deal.id}`);
    const types = timeline.body.map((a: { type: string }) => a.type);
    expect(types).toEqual(
      expect.arrayContaining([
        'lead.created',
        'meeting.created',
        'meeting.completed',
        'lead.converted',
      ]),
    );
    const history = await manager.get(`/api/v1/stage-history?leadId=${lead.id}`);
    expect(history.body.map((h: { to: { code: string } }) => h.to.code)).toEqual([
      'MEETING_DONE',
      'MEETING_SCHEDULED',
      'NEW',
    ]);
  });
});

describe('Разграничение данных CRM (ТЗ §65)', () => {
  it('менеджер видит только свои лиды; РОП — свой отдел; CEO — все', async () => {
    const m1 = await Client.login(app, 'manager@test.uz');
    const m2 = await Client.login(app, 'manager2@test.uz');
    const own = await newLead(m1);
    const other = await newLead(m2, { companyName: 'Other Co' });

    const l1 = await m1.get('/api/v1/leads');
    expect(l1.body.items.map((l: { id: string }) => l.id)).toEqual([own.id]);
    expect((await m1.get(`/api/v1/leads/${other.id}`)).status).toBe(404);
    expect((await m1.patch(`/api/v1/leads/${other.id}`, { title: 'hack' })).status).toBe(404);
    expect((await m1.get(`/api/v1/timeline?leadId=${other.id}`)).status).toBe(404);

    const rop = await Client.login(app, 'rop@test.uz');
    expect((await rop.get('/api/v1/leads')).body.items.map((l: { id: string }) => l.id)).toEqual([
      own.id,
    ]);

    const ceo = await Client.login(app, 'ceo@test.uz');
    expect((await ceo.get('/api/v1/leads')).body.total).toBe(2);
  });

  it('менеджер не может создать лид на другого менеджера, РОП может — в своём отделе', async () => {
    const m1 = await Client.login(app, 'manager@test.uz');
    const r = await refs(m1);
    const body = {
      contactName: 'X',
      phone: '+998900000000',
      sourceId: r.source,
      serviceId: r.service,
    };
    expect(
      (await m1.post('/api/v1/leads', { ...body, ownerId: fx.users.otherManager.id })).status,
    ).toBe(404);
    const rop = await Client.login(app, 'rop@test.uz');
    const ok = await rop.post('/api/v1/leads', { ...body, ownerId: fx.users.manager.id });
    expect(ok.status).toBe(201);
    expect(
      (await rop.post('/api/v1/leads', { ...body, ownerId: fx.users.otherManager.id })).status,
    ).toBe(404);
  });

  it('исполнитель не видит CRM', async () => {
    const ex = await Client.login(app, 'executor@test.uz');
    expect((await ex.get('/api/v1/leads')).status).toBe(403);
    expect((await ex.get('/api/v1/deals')).status).toBe(403);
    expect((await ex.get('/api/v1/clients')).status).toBe(403);
    expect((await ex.get('/api/v1/pipeline')).status).toBe(403);
  });
});

describe('Бизнес-правила', () => {
  it('при закрытии «Потеряно» причина обязательна, для «Другая причина» — комментарий (ТЗ §39)', async () => {
    const m = await Client.login(app, 'manager@test.uz');
    const lead = await newLead(m);
    const r = await refs(m);
    expect((await m.post(`/api/v1/leads/${lead.id}/close`, { status: 'LOST' })).status).toBe(422);
    expect(
      (
        await m.post(`/api/v1/leads/${lead.id}/close`, {
          status: 'LOST',
          lossReasonId: r.lossOther,
        })
      ).status,
    ).toBe(422);
    const ok = await m.post(`/api/v1/leads/${lead.id}/close`, {
      status: 'LOST',
      lossReasonId: r.lossPrice,
    });
    expect(ok.status).toBe(200);
    expect(ok.body.lossReason.name).toBe('Слишком дорого');
    // Пауза без причины допустима
    const lead2 = await newLead(m, { companyName: 'Pause Co' });
    expect((await m.post(`/api/v1/leads/${lead2.id}/close`, { status: 'PAUSED' })).status).toBe(
      200,
    );
  });

  it('создание лида требует минимум полей (ТЗ §78)', async () => {
    const m = await Client.login(app, 'manager@test.uz');
    const r = await refs(m);
    const res = await m.post('/api/v1/leads', { sourceId: r.source, serviceId: r.service });
    expect(res.status).toBe(422);
    expect(res.body.error.details.map((d: { path: string }) => d.path)).toEqual(
      expect.arrayContaining(['contactName', 'phone']),
    );
  });

  it('USD конвертируется по курсу, изменение суммы сделки попадает в таймлайн и аудит', async () => {
    const m = await Client.login(app, 'manager@test.uz');
    const lead = await newLead(m, { budget: '1000', currency: 'USD' });
    expect(lead.budgetUzs).toBe('12650000.00');
    const rop = await Client.login(app, 'rop@test.uz');
    const conv = await rop.post(`/api/v1/leads/${lead.id}/convert`, {
      clientName: 'USD Co',
      amount: '1000',
      currency: 'USD',
    });
    const upd = await m.patch(`/api/v1/deals/${conv.body.dealId}`, { amount: '1200' });
    expect(upd.body.amountUzs).toBe('15180000.00');
    const tl = await m.get(`/api/v1/timeline?dealId=${conv.body.dealId}`);
    expect(tl.body[0].type).toBe('deal.amount_changed');
    const audit = await prisma.auditLog.findFirstOrThrow({ where: { action: 'deal.update' } });
    expect(audit.changes).toMatchObject({ amount: { old: '1000', new: '1200' } });
  });

  it('этап «Оплачено» нельзя поставить вручную', async () => {
    const m = await Client.login(app, 'manager@test.uz');
    const lead = await newLead(m);
    const rop = await Client.login(app, 'rop@test.uz');
    const conv = await rop.post(`/api/v1/leads/${lead.id}/convert`, {
      clientName: 'C',
      amount: '100',
    });
    const res = await m.post(`/api/v1/deals/${conv.body.dealId}/stage`, { stageCode: 'PAID' });
    expect(res.status).toBe(422);
    // Вперёд по этапам — только с документами: без отправленного КП нельзя (BUSINESS_RULES §3)
    expect(
      (await m.post(`/api/v1/deals/${conv.body.dealId}/stage`, { stageCode: 'PROPOSAL_SENT' }))
        .status,
    ).toBe(422);
  });

  it('история этапов и таймлайн неизменяемы в БД', async () => {
    const m = await Client.login(app, 'manager@test.uz');
    await newLead(m);
    const h = await prisma.stageHistory.findFirstOrThrow();
    await expect(prisma.stageHistory.delete({ where: { id: h.id } })).rejects.toThrow();
    const a = await prisma.activity.findFirstOrThrow();
    await expect(
      prisma.activity.update({ where: { id: a.id }, data: { type: 'x' } }),
    ).rejects.toThrow();
  });

  it('повторный POST с тем же Idempotency-Key не создаёт дубль', async () => {
    const m = await Client.login(app, 'manager@test.uz');
    const r = await refs(m);
    const body = {
      contactName: 'Dup',
      phone: '+998901111111',
      sourceId: r.source,
      serviceId: r.service,
    };
    const first = await m.post('/api/v1/leads', body, {
      headers: { 'Idempotency-Key': 'key-12345678' },
    });
    const second = await m.post('/api/v1/leads', body, {
      headers: { 'Idempotency-Key': 'key-12345678' },
    });
    expect(second.body.id).toBe(first.body.id);
    expect(await prisma.lead.count()).toBe(1);
  });
});

describe('Ответственный за лид — менеджер или РОП', () => {
  it('CEO создаёт лид → назначается менеджер, CEO получает уведомление; CEO ответственным не назначить', async () => {
    const ceo = await Client.login(app, 'ceo@test.uz');
    const refs = (await ceo.get('/api/v1/references')).body;
    const base = {
      contactName: 'Лид от CEO',
      phone: '+998901234500',
      sourceId: refs.sources[0].id,
      serviceId: refs.services[0].id,
    };
    const lead = await ceo.post('/api/v1/leads', base);
    expect(lead.status, JSON.stringify(lead.body)).toBe(201);
    expect([fx.users.manager.id, fx.users.otherManager.id]).toContain(lead.body.owner.id);

    // CEO и HR ответственными быть не могут
    for (const ownerId of [fx.users.ceo.id, fx.users.hr.id]) {
      const r = await ceo.post('/api/v1/leads', { ...base, phone: '+998901234501', ownerId });
      expect(r.status).toBe(422);
      expect(r.body.error.details[0].path).toBe('ownerId');
    }
    expect(
      (await ceo.post(`/api/v1/leads/${lead.body.id}/assign`, { ownerId: fx.users.ceo.id })).status,
    ).toBe(422);
    // РОП — можно
    const toRop = await ceo.post(`/api/v1/leads/${lead.body.id}/assign`, {
      ownerId: fx.users.rop.id,
    });
    expect(toRop.status, JSON.stringify(toRop.body)).toBe(200);

    // Лид от менеджера: CEO получает уведомление «Новый лид → ответственный»
    const manager = await Client.login(app, 'manager@test.uz');
    await manager.post('/api/v1/leads', { ...base, phone: '+998901234502' });
    while ((await app.get(OutboxDispatcher).processBatch()) > 0);
    const n = await prisma.notification.findFirst({
      where: { userId: fx.users.ceo.id, type: 'lead.created' },
    });
    expect(n?.body).toContain('→');
  });
});

import type { INestApplication } from '@nestjs/common';
import ExcelJS from 'exceljs';
import { afterAll, beforeAll, beforeEach, describe, expect, it } from 'vitest';
import type { PrismaService } from '../../core/prisma/prisma.service';
import { Client, createTestApp, resetDatabase } from '../../test/helpers';
import { SchedulerService } from '../automation/scheduler.service';
import { parseNumber } from './search.service';

let app: INestApplication;
let prisma: PrismaService;
let fx: Awaited<ReturnType<typeof resetDatabase>>;

beforeAll(async () => ({ app, prisma } = await createTestApp()));
afterAll(() => app.close());
beforeEach(async () => (fx = await resetDatabase(prisma)));

const today = () => new Date(Date.now() + 5 * 3_600_000).toISOString().slice(0, 10);

/** Полная продажа: лид → сделка → КП → договор → оплата → подтверждение. */
async function sale(manager: Client, rop: Client, name: string, amount: string, sourceIdx = 0) {
  const refs = (await manager.get('/api/v1/references')).body;
  const service = refs.services[0];
  const lead = await manager.post('/api/v1/leads', {
    contactName: name,
    companyName: name,
    phone: '+998901234500',
    sourceId: refs.sources[sourceIdx].id,
    serviceId: service.id,
  });
  const conv = await rop.post(`/api/v1/leads/${lead.body.id}/convert`, {
    clientName: name,
    amount,
  });
  const dealId = conv.body.dealId as string;
  await manager.post('/api/v1/meetings', {
    dealId,
    type: 'ONLINE',
    startsAt: new Date(Date.now() - 3_600_000).toISOString(),
  });
  const meeting = await prisma.meeting.findFirstOrThrow({ where: { dealId } });
  await prisma.meeting.update({ where: { id: meeting.id }, data: { status: 'DONE' } });
  const kp = await manager.post('/api/v1/proposals', {
    dealId,
    title: name,
    items: [{ serviceId: service.id, description: name, quantity: 1, unitPrice: amount }],
  });
  await manager.post(`/api/v1/proposals/${kp.body.id}/send`);
  await manager.post(`/api/v1/proposals/${kp.body.id}/accept`);
  const contract = await manager.post('/api/v1/contracts', {
    dealId,
    proposalId: kp.body.id,
    contractDate: today(),
    amount,
  });
  await manager.post(`/api/v1/contracts/${contract.body.id}/sign`);
  const pay = await manager.post('/api/v1/payments', {
    dealId,
    amount,
    type: 'FULL',
    method: 'BANK',
  });
  const c = await rop.post(`/api/v1/payments/${pay.body.id}/confirm`, {});
  expect(c.status, JSON.stringify(c.body)).toBe(200);
  return { dealId, clientId: conv.body.clientId as string, leadId: lead.body.id as string };
}

describe('Аналитика (ТЗ §39–43)', () => {
  it('продажи, воронка, источники, потери и права по ролям', async () => {
    const manager = await Client.login(app, 'manager@test.uz');
    const other = await Client.login(app, 'manager2@test.uz');
    const rop = await Client.login(app, 'rop@test.uz');
    const ceo = await Client.login(app, 'ceo@test.uz');
    const refs = (await manager.get('/api/v1/references')).body;

    await sale(manager, rop, 'Alfa', '5000000', 0);
    // Лид без продвижения и потерянный лид — у менеджера
    await manager.post('/api/v1/leads', {
      contactName: 'Beta',
      phone: '+998901111111',
      sourceId: refs.sources[1].id,
      serviceId: refs.services[0].id,
    });
    const lost = await manager.post('/api/v1/leads', {
      contactName: 'Gamma',
      phone: '+998902222222',
      sourceId: refs.sources[1].id,
      serviceId: refs.services[0].id,
    });
    const closed = await manager.post(`/api/v1/leads/${lost.body.id}/close`, {
      status: 'LOST',
      lossReasonId: refs.lossReasons[0].id,
    });
    expect(closed.status, JSON.stringify(closed.body)).toBe(200);
    // Лид другого отдела
    await other.post('/api/v1/leads', {
      contactName: 'Other',
      phone: '+998903333333',
      sourceId: refs.sources[0].id,
      serviceId: refs.services[0].id,
    });

    // Продажи за месяц: выручка = подписанный договор, оплачено = подтверждённая оплата
    const s = (await ceo.get('/api/v1/analytics/sales?period=month')).body;
    expect(s.granularity).toBe('day');
    expect(s.totals).toMatchObject({
      revenue: '5000000.00',
      collected: '5000000.00',
      won: 1,
      leads: 4,
      avgCheck: '5000000.00',
    });
    const day = s.points.find((p: { key: string }) => p.key === today());
    expect(day).toMatchObject({ revenue: '5000000.00', collected: '5000000.00', won: 1, leads: 4 });
    expect(s.change.revenue).toBeNull();

    // Воронка: 4 лида → 1 квалифицирован → встреча → КП → договор → оплата
    const funnel = (await ceo.get('/api/v1/analytics/funnel?period=month')).body;
    expect(funnel.map((f: { count: number }) => f.count)).toEqual([4, 1, 1, 1, 1, 1]);
    expect(funnel[1]).toMatchObject({ fromPrev: 25, fromStart: 25 });
    expect(funnel[5]).toMatchObject({ key: 'paid', fromPrev: 100, fromStart: 25 });

    // Источники: у первого — 2 лида (наш + другой отдел), 1 оплачен
    const sources = (await ceo.get('/api/v1/analytics/sources?period=month')).body;
    expect(sources[0]).toMatchObject({
      name: refs.sources[0].name,
      leads: 2,
      won: 1,
      conversion: 50,
      revenue: '5000000.00',
    });
    expect(sources.find((r: { id: string }) => r.id === refs.sources[1].id)).toMatchObject({
      leads: 2,
      won: 0,
    });

    // Потери
    const losses = (await ceo.get('/api/v1/analytics/losses?period=month')).body;
    expect(losses).toEqual([
      {
        id: refs.lossReasons[0].id,
        name: refs.lossReasons[0].name,
        leads: 1,
        deals: 0,
        amount: '0.00',
      },
    ]);

    // Права: менеджер видит только своё, РОП — свой отдел; без права — 403
    expect((await manager.get('/api/v1/analytics/sales?period=month')).body.totals.leads).toBe(3);
    expect((await other.get('/api/v1/analytics/sales?period=month')).body.totals).toMatchObject({
      leads: 1,
      revenue: '0.00',
    });
    expect((await rop.get('/api/v1/analytics/funnel?period=month')).body[0].count).toBe(3);
    // Фильтр по сотруднику не расширяет область видимости
    expect(
      (await manager.get(`/api/v1/analytics/sales?period=month&userId=${fx.users.otherManager.id}`))
        .body.totals.leads,
    ).toBe(0);
    const executor = await Client.login(app, 'executor@test.uz');
    expect((await executor.get('/api/v1/analytics/sales')).status).toBe(403);
  });

  it('прогноз: получено + ожидаемые оплаты + взвешенная воронка, без двойного счёта', async () => {
    const manager = await Client.login(app, 'manager@test.uz');
    const rop = await Client.login(app, 'rop@test.uz');
    const ceo = await Client.login(app, 'ceo@test.uz');
    await sale(manager, rop, 'Alfa', '5000000');
    const client = await prisma.client.findFirstOrThrow();
    // Открытая сделка 10 млн с вероятностью 40% (переопределена)
    const deal = await manager.post('/api/v1/deals', {
      clientId: client.id,
      title: 'Доп. услуги',
      serviceId: (await manager.get('/api/v1/references')).body.services[0].id,
      amount: '10000000',
    });
    expect(deal.status, JSON.stringify(deal.body)).toBe(201);
    await prisma.deal.update({ where: { id: deal.body.id }, data: { probabilityOverride: 40 } });
    // Сделка с ожидаемой оплатой 3 млн — в воронку не входит, только в «ожидаемые оплаты»
    const deal2 = await manager.post('/api/v1/deals', {
      clientId: client.id,
      title: 'Сайт',
      amount: '3000000',
    });
    await manager.post('/api/v1/payments', {
      dealId: deal2.body.id,
      amount: '3000000',
      type: 'PREPAYMENT',
      method: 'BANK',
    });
    const f = (await ceo.get('/api/v1/analytics/forecast')).body;
    expect(f.months).toHaveLength(3);
    expect(f.months[0]).toMatchObject({
      collected: '5000000.00',
      scheduled: '3000000.00',
      weighted: '4000000.00',
      total: '12000000.00',
      plan: null,
    });
    expect(f.months[1]).toMatchObject({ collected: '0.00', scheduled: '0.00', weighted: '0.00' });
    expect(f).toMatchObject({ pipeline: '13000000.00', openDeals: 2 });
  });

  it('LTV и здоровье клиентов; пересчёт и уведомление о риске', async () => {
    const manager = await Client.login(app, 'manager@test.uz');
    const rop = await Client.login(app, 'rop@test.uz');
    const ceo = await Client.login(app, 'ceo@test.uz');
    const { clientId } = await sale(manager, rop, 'Alfa', '5000000');
    const silent = await prisma.client.create({
      data: {
        name: 'Silent LLC',
        ownerId: fx.users.manager.id,
        teamId: fx.teams.team1.id,
        createdAt: new Date(Date.now() - 400 * 86_400_000),
      },
    });
    // Клиент с просроченной оплатой и без контакта 70 дней
    const risky = await prisma.client.create({
      data: {
        name: 'Risky LLC',
        ownerId: fx.users.manager.id,
        teamId: fx.teams.team1.id,
        createdAt: new Date(Date.now() - 70 * 86_400_000),
      },
    });
    // Сделка и просроченная оплата без активностей (журнал активностей нельзя менять задним числом)
    const stage = await prisma.dealStage.findFirstOrThrow({ orderBy: { sort: 'asc' } });
    const d = await prisma.deal.create({
      data: {
        title: 'Risky',
        clientId: risky.id,
        ownerId: fx.users.manager.id,
        teamId: fx.teams.team1.id,
        stageId: stage.id,
        amount: 1000000,
        amountUzs: 1000000,
        createdById: fx.users.manager.id,
        createdAt: risky.createdAt,
      },
    });
    await prisma.payment.create({
      data: {
        dealId: d.id,
        clientId: risky.id,
        amount: 1000000,
        amountUzs: 1000000,
        type: 'PREPAYMENT',
        method: 'BANK',
        dueDate: new Date('2020-01-01'),
        createdById: fx.users.manager.id,
      },
    });

    const list = (await ceo.get('/api/v1/analytics/clients?sort=health')).body;
    expect(list.total).toBe(3);
    const by = (id: string) => list.items.find((c: { id: string }) => c.id === id);
    expect(by(risky.id).health.level).toBe('RISK');
    expect(by(risky.id).health.reasons).toEqual(['Нет контакта 70 дн.', 'Просрочено оплат: 1']);
    expect(list.items[0].id).toBe(risky.id);
    expect(by(silent.id).health.level).toBe('LOST');
    expect(by(clientId)).toMatchObject({ ltv: '5000000.00', paidDeals: 1, avgCheck: '5000000.00' });
    expect(by(clientId).health.level).toBe('HEALTHY');
    expect(list.summary).toMatchObject({
      clients: 3,
      avgLtv: '5000000.00',
      repeatRate: 0,
      byHealth: { HEALTHY: 1, ATTENTION: 0, RISK: 1, LOST: 1 },
    });
    expect((await ceo.get('/api/v1/analytics/clients?health=RISK')).body.total).toBe(1);
    expect((await manager.get(`/api/v1/clients/${clientId}/insight`)).body.ltv).toBe('5000000.00');
    const other = await Client.login(app, 'manager2@test.uz');
    expect((await other.get(`/api/v1/clients/${clientId}/insight`)).status).toBe(404);
    expect((await other.get('/api/v1/analytics/clients')).body.total).toBe(0);

    // Задача планировщика: уровни сохраняются в карточке, менеджеру — уведомление о риске
    const run = await app.get(SchedulerService).runNow('client-health');
    expect(run?.error).toBeNull();
    expect((await prisma.client.findUniqueOrThrow({ where: { id: risky.id } })).health).toBe(
      'RISK',
    );
    const n = await prisma.notification.findFirstOrThrow({
      where: { userId: fx.users.manager.id, type: 'client.risk' },
    });
    expect(n.body).toContain('Risky LLC');
    // Повторный пересчёт не шлёт уведомление ещё раз
    await app.get(SchedulerService).runNow('client-health');
    expect(await prisma.notification.count({ where: { type: 'client.risk' } })).toBe(1);
  });
});

describe('Поиск и экспорт (ТЗ §44)', () => {
  it('глобальный поиск по номеру, имени и телефону — в пределах прав', async () => {
    expect(parseNumber('D-00012')).toBe(12);
    expect(parseNumber('ДГ-7')).toBe(7);
    expect(parseNumber('42')).toBe(42);
    expect(parseNumber('Alfa')).toBeNull();

    const manager = await Client.login(app, 'manager@test.uz');
    const rop = await Client.login(app, 'rop@test.uz');
    const { dealId } = await sale(manager, rop, 'Alfa Market', '5000000');
    const deal = await prisma.deal.findUniqueOrThrow({ where: { id: dealId } });

    const hits = (await manager.get('/api/v1/search?q=alfa')).body;
    const kinds = new Set(hits.map((h: { kind: string }) => h.kind));
    expect(kinds).toEqual(new Set(['client', 'lead', 'deal', 'project', 'contract']));
    expect(hits.find((h: { kind: string }) => h.kind === 'deal').link).toBe(
      `/sales/deals/${dealId}`,
    );
    const byNumber = (await manager.get(`/api/v1/search?q=D-${deal.number}`)).body;
    expect(byNumber.some((h: { id: string }) => h.id === dealId)).toBe(true);
    expect(
      (await manager.get('/api/v1/search?q=901234500')).body.some(
        (h: { kind: string }) => h.kind === 'lead',
      ),
    ).toBe(true);
    // Чужой менеджер ничего не находит; сотрудников ищет только тот, у кого есть право
    const other = await Client.login(app, 'manager2@test.uz');
    expect((await other.get('/api/v1/search?q=alfa')).body).toEqual([]);
    const ceo = await Client.login(app, 'ceo@test.uz');
    expect((await ceo.get('/api/v1/search?q=manager2')).body[0]).toMatchObject({
      kind: 'user',
      link: '/team/employees?q=manager2%40test.uz',
    });
    expect((await manager.get('/api/v1/search?q=a')).status).toBe(422);
  });

  it('экспорт в Excel и CSV: права, область видимости, аудит', async () => {
    const manager = await Client.login(app, 'manager@test.uz');
    const rop = await Client.login(app, 'rop@test.uz');
    await sale(manager, rop, '=HYPERLINK("x")', '5000000');
    const other = await Client.login(app, 'manager2@test.uz');
    const refs = (await other.get('/api/v1/references')).body;
    await other.post('/api/v1/leads', {
      contactName: 'Other',
      phone: '+998903333333',
      sourceId: refs.sources[0].id,
      serviceId: refs.services[0].id,
    });

    // Менеджеру экспорт не разрешён (право export.run)
    expect((await manager.get('/api/v1/exports/leads')).status).toBe(403);

    // РОП: только свой отдел
    const csv = await rop.get('/api/v1/exports/leads?format=csv');
    expect(csv.status).toBe(200);
    expect(csv.headers['content-type']).toContain('text/csv');
    expect(csv.headers['content-disposition']).toMatch(
      /attachment; filename="fluggi-leads-\d{4}-\d{2}-\d{2}\.csv"/,
    );
    const text = csv.text;
    expect(text.charCodeAt(0)).toBe(0xfeff);
    const lines = text.trim().split('\r\n');
    expect(lines[0]).toContain('Номер;Название;Контакт');
    expect(lines).toHaveLength(2);
    // Формула в данных не исполнится в Excel
    expect(lines[1]).toContain(`"'=HYPERLINK(""x"")"`);
    expect(csv.headers['x-export-rows']).toBe('1');

    // CEO: Excel со всеми лидами
    const ceo = await Client.login(app, 'ceo@test.uz');
    const res = await (
      await import('supertest')
    )
      .default(app.getHttpServer())
      .get('/api/v1/exports/payments?format=xlsx')
      .set('Cookie', (ceo as unknown as { cookieHeader(): string }).cookieHeader())
      .buffer(true)
      .parse((r, cb) => {
        const chunks: Buffer[] = [];
        r.on('data', (c: Buffer) => chunks.push(c));
        r.on('end', () => cb(null, Buffer.concat(chunks)));
      });
    expect(res.status).toBe(200);
    const wb = new ExcelJS.Workbook();
    await wb.xlsx.load(res.body as unknown as ArrayBuffer);
    const ws = wb.getWorksheet('Оплаты')!;
    expect(ws.getRow(1).getCell(1).value).toBe('Номер');
    expect(ws.rowCount).toBe(2);
    expect(ws.getRow(2).getCell(10).value).toBe(5000000);
    expect(ws.getRow(2).getCell(6).value).toBe('Оплачено');

    for (const e of ['deals', 'clients', 'expenses', 'projects', 'tasks'])
      expect((await ceo.get(`/api/v1/exports/${e}?format=csv&period=month`)).status).toBe(200);
    expect((await ceo.get('/api/v1/exports/users')).status).toBe(422);
    expect(await prisma.auditLog.count({ where: { action: 'export.run' } })).toBe(7);
  });
});

describe('Годовой пакет для бухгалтера', () => {
  it('один Excel со всеми данными года; только CEO; реквизиты компании', async () => {
    const manager = await Client.login(app, 'manager@test.uz');
    const rop = await Client.login(app, 'rop@test.uz');
    const ceo = await Client.login(app, 'ceo@test.uz');
    const year = Number(today().slice(0, 4));
    const { dealId, clientId } = await sale(manager, rop, 'Alfa', '5000000');
    const project = await prisma.project.findFirstOrThrow({ where: { dealId } });
    // Второй договор того же клиента подписан, но не оплачен → долг клиента 2 млн
    await prisma.contract.create({
      data: {
        dealId,
        clientId,
        contractDate: new Date(`${today()}T00:00:00Z`),
        amount: 2000000,
        amountUzs: 2000000,
        status: 'SIGNED',
        signedAt: new Date(),
        createdById: fx.users.manager.id,
      },
    });
    for (const [scope, category, amount, projectId] of [
      ['PROJECT', 'DESIGN', '700000', project.id],
      ['COMPANY', 'ADS', '300000', null],
      ['COMPANY', 'SERVICES', '200000', null],
    ] as const) {
      const r = await ceo.post('/api/v1/expenses', {
        scope,
        category,
        amount,
        projectId,
        expenseDate: today(),
      });
      expect(r.status, JSON.stringify(r.body)).toBe(201);
    }
    await prisma.employee.update({
      where: { userId: fx.users.manager.id },
      data: { baseSalary: 4000000 },
    });
    await ceo.post('/api/v1/payroll/calculate', { period: today().slice(0, 7) });

    // Реквизиты компании
    expect((await ceo.get('/api/v1/settings/company')).body.taxRegime).toBe('OTHER');
    expect(
      (
        await ceo.put('/api/v1/settings/company', {
          name: 'Fluggi',
          inn: '12345',
          director: '',
          accountant: '',
          taxRegime: 'IT_PARK',
        })
      ).status,
    ).toBe(422);
    const saved = await ceo.put('/api/v1/settings/company', {
      name: 'ООО «Fluggi»',
      inn: '123456789',
      director: 'CEO',
      accountant: 'Бухгалтер',
      taxRegime: 'IT_PARK',
    });
    expect(saved.status, JSON.stringify(saved.body)).toBe(200);
    expect((await rop.get('/api/v1/settings/company')).status).toBe(403);

    // Только CEO (финансы компании + экспорт)
    expect((await rop.get(`/api/v1/exports/accountant-package?year=${year}`)).status).toBe(403);
    expect((await ceo.get('/api/v1/exports/accountant-package?year=1999')).status).toBe(422);

    const res = await (
      await import('supertest')
    )
      .default(app.getHttpServer())
      .get(`/api/v1/exports/accountant-package?year=${year}`)
      .set('Cookie', (ceo as unknown as { cookieHeader(): string }).cookieHeader())
      .buffer(true)
      .parse((r, cb) => {
        const chunks: Buffer[] = [];
        r.on('data', (c: Buffer) => chunks.push(c));
        r.on('end', () => cb(null, Buffer.concat(chunks)));
      });
    expect(res.status).toBe(200);
    expect(res.headers['content-disposition']).toBe(
      `attachment; filename="fluggi-buhgalter-${year}.xlsx"`,
    );
    const wb = new ExcelJS.Workbook();
    await wb.xlsx.load(res.body as unknown as ArrayBuffer);
    expect(wb.worksheets.map((w) => w.name)).toEqual([
      'Пояснения',
      'Сводка (форма 2)',
      'Договоры',
      'Оплаты',
      'Расчёты с клиентами 31.12',
      'Расходы',
      'Зарплата',
      'Комиссии',
      'Проекты',
    ]);
    const notes = wb.getWorksheet('Пояснения')!;
    const value = (label: string) => {
      let v: unknown;
      notes.eachRow((r) => {
        if (r.getCell(1).value === label) v = r.getCell(2).value;
      });
      return v;
    };
    expect(value('Компания')).toBe('ООО «Fluggi»');
    expect(value('Налоговый режим')).toBe('Резидент IT-Park');
    expect(value('Подписано договоров, UZS')).toBe(7000000);
    expect(value('Получено оплат (за вычетом возвратов), UZS')).toBe(5000000);
    expect(value('Расходы на проекты (себестоимость), UZS')).toBe(700000);
    expect(value('Расходы компании, UZS')).toBe(500000);
    expect(value('Дебиторская задолженность клиентов на 31.12, UZS')).toBe(2000000);

    // Сводка по форме №2: выручка 5 млн − себестоимость 0,7 млн; расходы периода = 0,5 млн + зарплата
    const f2 = wb.getWorksheet('Сводка (форма 2)')!;
    const line = (label: string) => {
      let v: unknown;
      f2.eachRow((r) => {
        if (String(r.getCell(2).value).trim() === label) v = r.getCell(3).value;
      });
      return v as number;
    };
    expect(line('Чистая выручка (по оплатам)')).toBe(5000000);
    expect(line('Валовая прибыль')).toBe(4300000);
    expect(line('расходы по реализации (реклама компании)')).toBe(300000);
    const salary = line('зарплата (без НДФЛ и соцналога)');
    expect(salary).toBeGreaterThanOrEqual(4000000);
    expect(line('Прибыль от основной деятельности')).toBe(4300000 - 500000 - salary);

    const exp = wb.getWorksheet('Расходы')!;
    expect(exp.getRow(2).getCell(5).value).toBe('Себестоимость услуг (9130)');
    const settle = wb.getWorksheet('Расчёты с клиентами 31.12')!;
    expect(settle.getRow(2).getCell(5).value).toBe(2000000);
    expect(await prisma.auditLog.count({ where: { entityType: 'accountant_package' } })).toBe(1);
  });
});

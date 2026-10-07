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

const month = () => new Date(Date.now() + 5 * 3_600_000).toISOString().slice(0, 7);
const today = () => new Date(Date.now() + 5 * 3_600_000).toISOString().slice(0, 10);

/** Полная продажа менеджера на 9 000 000: лид → встреча → сделка → КП → договор → оплата. */
async function sale(manager: Client, rop: Client) {
  const refs = (await manager.get('/api/v1/references')).body;
  const lead = await manager.post('/api/v1/leads', {
    contactName: 'Kamola',
    companyName: 'Kamola Shop',
    phone: '+998901110000',
    sourceId: refs.sources[0].id,
    serviceId: refs.services[0].id,
  });
  // Второй лид остаётся необработанным — конверсия 50%
  await manager.post('/api/v1/leads', {
    contactName: 'Bobur',
    phone: '+998901110001',
    sourceId: refs.sources[0].id,
    serviceId: refs.services[0].id,
  });
  const m = await manager.post('/api/v1/meetings', {
    leadId: lead.body.id,
    startsAt: new Date(Date.now() - 3_600_000).toISOString(),
    type: 'OFFLINE',
  });
  await manager.post(`/api/v1/meetings/${m.body.id}/complete`, { result: 'Нужен SMM' });
  const conv = await rop.post(`/api/v1/leads/${lead.body.id}/convert`, {
    clientName: 'Kamola Shop',
    amount: '9000000',
  });
  const dealId = conv.body.dealId as string;
  const kp = await manager.post('/api/v1/proposals', {
    dealId,
    title: 'SMM',
    items: [{ description: 'SMM', quantity: 1, unitPrice: '9000000' }],
  });
  await manager.post(`/api/v1/proposals/${kp.body.id}/send`);
  await manager.post(`/api/v1/proposals/${kp.body.id}/accept`);
  const c = await manager.post('/api/v1/contracts', {
    dealId,
    proposalId: kp.body.id,
    contractDate: today(),
    amount: '9000000',
  });
  await manager.post(`/api/v1/contracts/${c.body.id}/sign`);
  const p = await manager.post('/api/v1/payments', {
    dealId,
    amount: '9000000',
    type: 'FULL',
    method: 'BANK',
  });
  const confirmed = await rop.post(`/api/v1/payments/${p.body.id}/confirm`, {});
  expect(confirmed.status, JSON.stringify(confirmed.body)).toBe(200);
  return { dealId, projectId: confirmed.body.project.id as string };
}

describe('KPI и цели (ТЗ §28–31)', () => {
  it('KPI менеджера, РОП и исполнителя; цели и выполнение; разграничение', async () => {
    const manager = await Client.login(app, 'manager@test.uz');
    const rop = await Client.login(app, 'rop@test.uz');
    const { projectId } = await sale(manager, rop);

    // Исполнитель: задача выполнена после возврата с проверки
    await rop.post(`/api/v1/projects/${projectId}/members`, { userId: fx.users.executor.id });
    const ex = await Client.login(app, 'executor@test.uz');
    const t = await rop.post('/api/v1/tasks', {
      projectId,
      title: 'Логотип',
      assigneeId: fx.users.executor.id,
    });
    for (const s of ['IN_PROGRESS', 'REVIEW'])
      await ex.post(`/api/v1/tasks/${t.body.id}/move`, { status: s });
    await rop.post(`/api/v1/tasks/${t.body.id}/move`, { status: 'IN_PROGRESS' });
    await ex.post(`/api/v1/tasks/${t.body.id}/move`, { status: 'DONE' });

    // Цели: РОП ставит менеджеру $1 000 и 2 лида; себе — нельзя; менеджер — не может
    const set = await rop.put('/api/v1/kpi/targets', {
      userId: fx.users.manager.id,
      period: month(),
      targets: [
        { metric: 'REVENUE', value: '1000', currency: 'USD' },
        { metric: 'LEADS', value: 2 },
      ],
    });
    expect(set.status, JSON.stringify(set.body)).toBe(200);
    expect(
      (
        await rop.put('/api/v1/kpi/targets', {
          userId: fx.users.rop.id,
          period: month(),
          targets: [],
        })
      ).status,
    ).toBe(403);
    expect(
      (
        await manager.put('/api/v1/kpi/targets', {
          userId: fx.users.manager.id,
          period: month(),
          targets: [],
        })
      ).status,
    ).toBe(403);
    // Исполнитель не в отделе РОП: РОП не может ставить ему цели, CEO — может
    const ceo0 = await Client.login(app, 'ceo@test.uz');
    expect(
      (
        await rop.put('/api/v1/kpi/targets', {
          userId: fx.users.executor.id,
          period: month(),
          targets: [],
        })
      ).status,
    ).toBe(404);
    await ceo0.put('/api/v1/kpi/targets', {
      userId: fx.users.executor.id,
      period: month(),
      targets: [{ metric: 'TASKS', value: 4 }],
    });

    const rows = (await rop.get(`/api/v1/kpi?period=${month()}`)).body;
    const m = rows.find((r: { user: { id: string } }) => r.user.id === fx.users.manager.id);
    expect(m.manager).toMatchObject({
      leads: 2,
      processedLeads: 1,
      meetings: 1,
      proposals: 1,
      contracts: 1,
      payments: 1,
      orders: 1,
      revenueUzs: '9000000.00',
      avgCheckUzs: '9000000.00',
      conversionPct: '50.00',
    });
    // 9 000 000 / 12 650 = 711.46 USD из 1 000 → 71.15%; лиды 2 из 2 → 100%
    const rev = m.targets.find((x: { metric: string }) => x.metric === 'REVENUE');
    expect(rev).toMatchObject({ fact: '711.46', pct: '71.15', currency: 'USD' });
    expect(m.kpiPct).toBe('85.58');

    const r = rows.find((x: { user: { id: string } }) => x.user.id === fx.users.rop.id);
    expect(r.rop).toMatchObject({
      teamRevenueUzs: '9000000.00',
      orders: 1,
      managers: 1,
      planUzs: '12650000.00',
      planPct: '71.15',
    });
    // Исполнитель не в отделе РОП — его видит CEO
    const ceo = await Client.login(app, 'ceo@test.uz');
    const all = (await ceo.get(`/api/v1/kpi?period=${month()}&group=EXECUTOR`)).body;
    expect(all[0].executor).toMatchObject({
      tasks: 1,
      done: 1,
      reworks: 1,
      completionPct: '100.00',
    });
    expect(all[0].targets[0]).toMatchObject({ metric: 'TASKS', pct: '25.00' });

    // Менеджер видит только себя
    const own = (await manager.get(`/api/v1/kpi?period=${month()}`)).body;
    expect(own.map((x: { user: { id: string } }) => x.user.id)).toEqual([fx.users.manager.id]);
    const other = await Client.login(app, 'manager2@test.uz');
    expect(
      (await other.get(`/api/v1/kpi/targets?userId=${fx.users.manager.id}&period=${month()}`))
        .status,
    ).toBe(404);
  });
});

describe('Дашборды по ролям (ТЗ §5, §58–60)', () => {
  it('CEO — компания, РОП — отдел, менеджер — свои показатели, исполнитель — задачи', async () => {
    const manager = await Client.login(app, 'manager@test.uz');
    const rop = await Client.login(app, 'rop@test.uz');
    await sale(manager, rop);
    const ceo = await Client.login(app, 'ceo@test.uz');
    const c = (await ceo.get('/api/v1/dashboard?period=month')).body;
    expect(c.ceo).toMatchObject({
      revenueUzs: '9000000.00',
      paidUzs: '9000000.00',
      expectedUzs: '0.00',
      newLeads: 2,
      newDeals: 1,
      contracts: 1,
      projectsInProgress: 1,
    });
    expect(c.team).toBeUndefined();
    const r = (await rop.get('/api/v1/dashboard?period=month')).body;
    expect(r.ceo).toBeUndefined();
    expect(r.team).toMatchObject({
      leads: 2,
      meetings: 1,
      proposals: 1,
      contracts: 1,
      payments: 1,
    });
    expect(r.team.managers[0].manager.revenueUzs).toBe('9000000.00');
    const m = (await manager.get('/api/v1/dashboard?period=month')).body;
    expect(m.own.kpi.manager.orders).toBe(1);
    expect(m.own.commissionUzs).toBe('900000');
    const ex = await Client.login(app, 'executor@test.uz');
    expect((await ex.get('/api/v1/dashboard')).body.executor).toEqual({
      projects: 0,
      today: 0,
      overdue: 0,
      inProgress: 0,
      done: 0,
    });
  });
});

describe('Посещаемость и графики (ТЗ §35–36)', () => {
  it('отметка прихода/ухода, ручная запись HR с опозданием, графики, права', async () => {
    const manager = await Client.login(app, 'manager@test.uz');
    const hr = await Client.login(app, 'hr@test.uz');
    const ceo = await Client.login(app, 'ceo@test.uz');

    const day = (await manager.get('/api/v1/attendance/today')).body;
    expect(day.schedule).toMatchObject({ name: 'Менеджеры', startTime: '09:00' });
    const ci = await manager.post('/api/v1/attendance/check-in', {});
    expect(ci.status, JSON.stringify(ci.body)).toBe(200);
    expect(['PRESENT', 'LATE']).toContain(ci.body.status);
    expect((await manager.post('/api/v1/attendance/check-in', {})).status).toBe(409);
    const co = await manager.post('/api/v1/attendance/check-out', {});
    expect(co.body.checkOut).toMatch(/^\d{2}:\d{2}$/);
    expect((await manager.post('/api/v1/attendance/check-out', {})).status).toBe(409);

    // HR вносит прошлый день: приход 09:25 (среда) — опоздание 25 минут, 8.5 часа работы
    const fixed = await hr.put('/api/v1/attendance', {
      userId: fx.users.manager.id,
      date: '2026-10-07',
      status: 'PRESENT',
      checkIn: '09:25',
      checkOut: '17:55',
      comment: 'Забыл отметиться',
    });
    expect(fixed.status, JSON.stringify(fixed.body)).toBe(200);
    expect(fixed.body).toMatchObject({ status: 'LATE', lateMinutes: 25, workMinutes: 510 });
    await hr.put('/api/v1/attendance', {
      userId: fx.users.manager.id,
      date: '2026-10-06',
      status: 'SICK',
    });
    expect(
      (
        await manager.put('/api/v1/attendance', {
          userId: fx.users.manager.id,
          date: '2026-10-05',
          status: 'PRESENT',
        })
      ).status,
    ).toBe(403);

    const sum = (
      await hr.get(
        `/api/v1/attendance/summary?dateFrom=2026-10-01&dateTo=2026-10-07&userId=${fx.users.manager.id}`,
      )
    ).body[0];
    expect(sum).toMatchObject({ late: 1, lateMinutes: 25, sick: 1, workHours: '8.5' });
    // Исполнитель видит только себя
    const ex = await Client.login(app, 'executor@test.uz');
    expect((await ex.get('/api/v1/attendance?dateFrom=2026-10-01&dateTo=2026-12-31')).body).toEqual(
      [],
    );

    // Графики: у роли один активный график; индивидуальный график «Обучение»
    const schedules = (await ceo.get('/api/v1/work-schedules')).body;
    const training = schedules.find((s: { name: string }) => s.name === 'Обучение');
    expect(
      (
        await ceo.post('/api/v1/work-schedules', {
          name: 'Ещё менеджеры',
          roleCode: 'MANAGER',
          startTime: '10:00',
          endTime: '19:00',
          workDays: [1, 2, 3, 4, 5],
        })
      ).status,
    ).toBe(409);
    const upd = await ceo.put(`/api/v1/work-schedules/${training.id}`, {
      ...training,
      roleCode: null,
      userIds: [fx.users.manager.id],
    });
    expect(upd.status, JSON.stringify(upd.body)).toBe(200);
    expect((await manager.get('/api/v1/attendance/today')).body.schedule.name).toBe('Обучение');
    expect((await manager.post('/api/v1/work-schedules', training)).status).toBe(403);
  });
});

describe('Зарплата (ТЗ §32)', () => {
  it('оклад + KPI-бонус + комиссия + бонус − штраф; свою видит каждый, все — CEO', async () => {
    const manager = await Client.login(app, 'manager@test.uz');
    const rop = await Client.login(app, 'rop@test.uz');
    const ceo = await Client.login(app, 'ceo@test.uz');
    await sale(manager, rop);
    // Комиссии месяца утверждены — попадают в зарплату
    const ids = (await ceo.get('/api/v1/commissions?pageSize=100')).body.items.map(
      (c: { id: string }) => c.id,
    );
    await ceo.post('/api/v1/commissions/approve', { ids });

    const calc = await ceo.post('/api/v1/payroll/calculate', { period: month() });
    expect(calc.status, JSON.stringify(calc.body)).toBe(200);
    const mine = calc.body.find((e: { user: { id: string } }) => e.user.id === fx.users.manager.id);
    expect(mine).toMatchObject({ commission: '900000.00', baseSalary: '0.00', status: 'DRAFT' });

    const upd = await ceo.patch(`/api/v1/payroll/${mine.id}`, {
      baseSalary: '5 000 000',
      kpiBonus: '1000000',
      otherBonus: '200000',
      penalty: '150000',
      saveBaseSalary: true,
    });
    expect(upd.body.finalSalary).toBe('6950000.00');
    expect(
      (
        await prisma.employee.findUniqueOrThrow({ where: { userId: fx.users.manager.id } })
      ).baseSalary?.toFixed(2),
    ).toBe('5000000.00');
    // Пересчёт сохраняет ручные поля
    const again = (await ceo.post('/api/v1/payroll/calculate', { period: month() })).body;
    expect(again.find((e: { id: string }) => e.id === mine.id).finalSalary).toBe('6950000.00');

    // Менеджер видит только свою строку; РОП — свою; менять не может никто, кроме CEO
    const own = (await manager.get(`/api/v1/payroll?period=${month()}`)).body;
    expect(own.map((e: { user: { id: string } }) => e.user.id)).toEqual([fx.users.manager.id]);
    expect(
      (await rop.get(`/api/v1/payroll?period=${month()}`)).body.every(
        (e: { user: { id: string } }) => e.user.id === fx.users.rop.id,
      ),
    ).toBe(true);
    expect((await rop.patch(`/api/v1/payroll/${mine.id}`, { penalty: '0' })).status).toBe(403);
    const hr = await Client.login(app, 'hr@test.uz');
    expect(
      (await hr.get(`/api/v1/payroll?period=${month()}`)).body.map(
        (e: { user: { id: string } }) => e.user.id,
      ),
    ).toEqual([fx.users.hr.id]);
    // CEO в ведомость не входит
    expect(calc.body.some((e: { user: { id: string } }) => e.user.id === fx.users.ceo.id)).toBe(
      false,
    );

    expect((await ceo.post('/api/v1/payroll/pay', { ids: [mine.id] })).status).toBe(422);
    expect((await ceo.post('/api/v1/payroll/approve', { ids: [mine.id] })).body.updated).toBe(1);
    expect((await ceo.patch(`/api/v1/payroll/${mine.id}`, { penalty: '0' })).status).toBe(422);
    expect((await ceo.post('/api/v1/payroll/pay', { ids: [mine.id] })).status).toBe(200);
    await expect(prisma.payrollEntry.delete({ where: { id: mine.id } })).rejects.toThrow();
  });
});

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

/** Сделка на 9 000 000 UZS: КП → договор → оплата(ы) → подтверждение РОП. */
async function sale(manager: Client, rop: Client, payments: string[]) {
  const refs = (await manager.get('/api/v1/references')).body;
  const service = refs.services.find((s: { code: string }) => s.code === 'BRANDING');
  const lead = await manager.post('/api/v1/leads', {
    contactName: 'Dilnoza',
    companyName: 'Dilnoza Beauty',
    phone: '+998901234500',
    sourceId: refs.sources[0].id,
    serviceId: service.id,
  });
  const conv = await rop.post(`/api/v1/leads/${lead.body.id}/convert`, {
    clientName: 'Dilnoza Beauty',
    amount: '9000000',
  });
  const dealId = conv.body.dealId as string;
  const kp = await manager.post('/api/v1/proposals', {
    dealId,
    title: 'Брендинг',
    items: [{ serviceId: service.id, description: 'Брендинг', quantity: 1, unitPrice: '9000000' }],
  });
  await manager.post(`/api/v1/proposals/${kp.body.id}/send`);
  await manager.post(`/api/v1/proposals/${kp.body.id}/accept`);
  const contract = await manager.post('/api/v1/contracts', {
    dealId,
    proposalId: kp.body.id,
    contractDate: new Date().toISOString().slice(0, 10),
    amount: '9000000',
  });
  await manager.post(`/api/v1/contracts/${contract.body.id}/sign`);
  const pay = async (amount: string) => {
    const p = await manager.post('/api/v1/payments', {
      dealId,
      amount,
      type: 'PARTIAL',
      method: 'BANK',
    });
    const c = await rop.post(`/api/v1/payments/${p.body.id}/confirm`, {});
    expect(c.status, JSON.stringify(c.body)).toBe(200);
    return c.body;
  };
  const results = [];
  for (const a of payments) results.push(await pay(a));
  return { dealId, projectId: results[0].project.id as string, pay };
}

const today = () => new Date().toISOString().slice(0, 10);

describe('Финансы (ТЗ §25–27)', () => {
  it('финансовая карточка проекта: пример ТЗ §25 — прибыль 4 300 000, маржа 47.78%', async () => {
    const manager = await Client.login(app, 'manager@test.uz');
    const rop = await Client.login(app, 'rop@test.uz');
    const ceo = await Client.login(app, 'ceo@test.uz');
    const { projectId } = await sale(manager, rop, ['9000000']);

    const items: [string, string][] = [
      ['DESIGN', '700000'],
      ['VIDEO', '500000'],
      ['DEVELOPMENT', '2500000'],
      ['EXECUTOR', '500000'],
      ['ADS', '300000'],
      ['OTHER', '200000'],
    ];
    for (const [category, amount] of items) {
      const r = await ceo.post('/api/v1/expenses', {
        scope: 'PROJECT',
        projectId,
        category,
        amount,
        expenseDate: today(),
      });
      expect(r.status, JSON.stringify(r.body)).toBe(201);
    }
    const f = (await ceo.get(`/api/v1/projects/${projectId}/finance`)).body;
    expect(f).toMatchObject({
      revenueUzs: '9000000.00',
      collectedUzs: '9000000.00',
      receivableUzs: '0.00',
      expensesUzs: '4700000.00',
      grossProfitUzs: '4300000.00',
      marginPct: '47.78',
      commissionsUzs: '1800000.00',
    });
    expect(f.byCategory[0]).toEqual({
      category: 'DEVELOPMENT',
      name: 'Разработка',
      amountUzs: '2500000.00',
    });

    // Финансы проекта — только CEO: РОП, менеджер и исполнитель их не видят
    expect((await rop.get(`/api/v1/projects/${projectId}/finance`)).status).toBe(403);
    expect(
      (
        await rop.post('/api/v1/expenses', {
          scope: 'PROJECT',
          projectId,
          category: 'OTHER',
          amount: '1',
          expenseDate: today(),
        })
      ).status,
    ).toBe(403);
    expect((await manager.get(`/api/v1/projects/${projectId}/finance`)).status).toBe(403);
    expect((await manager.get('/api/v1/expenses')).status).toBe(403);
    const ex = await Client.login(app, 'executor@test.uz');
    expect((await ex.get(`/api/v1/projects/${projectId}/finance`)).status).toBe(403);
  });

  it('расходы ведёт только CEO: проектные и компании; правка и мягкое удаление с аудитом', async () => {
    const manager = await Client.login(app, 'manager@test.uz');
    const rop = await Client.login(app, 'rop@test.uz');
    const ceo = await Client.login(app, 'ceo@test.uz');
    const { projectId } = await sale(manager, rop, ['9000000']);

    const company = {
      scope: 'COMPANY',
      category: 'SERVICES',
      amount: '1000000',
      expenseDate: today(),
    };
    expect((await rop.post('/api/v1/expenses', company)).status).toBe(403);
    expect((await ceo.post('/api/v1/expenses', company)).status).toBe(201);
    // Проектный расход без проекта и расход компании с проектом — ошибка
    expect((await ceo.post('/api/v1/expenses', { ...company, scope: 'PROJECT' })).status).toBe(422);
    expect((await ceo.post('/api/v1/expenses', { ...company, projectId })).status).toBe(422);

    const e = await ceo.post('/api/v1/expenses', {
      scope: 'PROJECT',
      projectId,
      category: 'DESIGN',
      amount: '100',
      currency: 'USD',
      expenseDate: today(),
      payeeUserId: fx.users.executor.id,
    });
    expect(e.body.amountUzs).toBe('1265000.00'); // курс 12 650
    expect(e.body.number).toMatch(/^EXP-\d{5}$/);
    // РОП расходы не видит
    expect((await rop.get('/api/v1/expenses')).status).toBe(403);
    expect((await ceo.get('/api/v1/expenses')).body.total).toBe(2);

    const fixed = await ceo.patch(`/api/v1/expenses/${e.body.id}`, {
      amount: '1 500 000',
      currency: 'UZS',
    });
    expect(fixed.body.amountUzs).toBe('1500000.00');
    expect((await ceo.delete(`/api/v1/expenses/${e.body.id}`)).status).toBe(204);
    expect((await ceo.get('/api/v1/expenses')).body.total).toBe(1);
    expect(await prisma.expense.count()).toBe(2); // запись осталась (soft delete)
    const audit = await prisma.auditLog.findMany({
      where: { entityType: 'expense', entityId: e.body.id },
      orderBy: { createdAt: 'asc' },
    });
    expect(audit.map((a) => a.action)).toEqual([
      'expense.create',
      'expense.update',
      'expense.delete',
    ]);
  });

  it('финансовый дашборд: прибыль компании видит только CEO', async () => {
    const manager = await Client.login(app, 'manager@test.uz');
    const rop = await Client.login(app, 'rop@test.uz');
    const ceo = await Client.login(app, 'ceo@test.uz');
    const { projectId, pay } = await sale(manager, rop, ['5000000']);
    await ceo.post('/api/v1/expenses', {
      scope: 'PROJECT',
      projectId,
      category: 'DESIGN',
      amount: '2000000',
      expenseDate: today(),
    });
    await ceo.post('/api/v1/expenses', {
      scope: 'COMPANY',
      category: 'SERVICES',
      amount: '300000',
      expenseDate: today(),
    });
    const second = await pay('4000000');
    // Возврат 1 000 000 со второго платежа
    await rop.post(`/api/v1/payments/${second.payment.id}/refund`, {
      amount: '1000000',
      method: 'BANK',
      comment: 'Скидка',
    });

    const s = (await ceo.get('/api/v1/finance/summary?period=month')).body;
    expect(s).toMatchObject({
      revenueUzs: '9000000.00',
      collectedUzs: '9000000.00',
      refundsUzs: '1000000.00',
      receivablesUzs: '1000000.00',
      projectExpensesUzs: '2000000.00',
      companyExpensesUzs: '300000.00',
      // 10% + 10% с 9 000 000 и сторно 10% + 10% с возврата 1 000 000
      commissionsUzs: '1600000.00',
      grossProfitUzs: '6000000.00',
      operatingProfitUzs: '4100000.00',
      marginPct: '75.00',
    });
    expect((await rop.get('/api/v1/finance/summary?period=week')).status).toBe(403);
    // Прошлый год — пусто
    const empty = (
      await ceo.get('/api/v1/finance/summary?period=custom&from=2020-01-01&to=2020-12-31')
    ).body;
    expect(empty.collectedUzs).toBe('0.00');

    const profit = (await ceo.get('/api/v1/finance/projects')).body.items[0];
    expect(profit).toMatchObject({
      revenueUzs: '9000000.00',
      collectedUzs: '8000000.00',
      expensesUzs: '2000000.00',
      grossProfitUzs: '7000000.00',
      marginPct: '77.78',
    });
    expect((await manager.get('/api/v1/finance/summary')).status).toBe(403);
  });

  it('комиссии: «% от прибыли» по марже проекта; утверждение и выплата — только CEO', async () => {
    const manager = await Client.login(app, 'manager@test.uz');
    const rop = await Client.login(app, 'rop@test.uz');
    const ceo = await Client.login(app, 'ceo@test.uz');
    const rule = await ceo.post('/api/v1/commission-rules', {
      name: 'Менеджер — 20% от прибыли',
      appliesTo: 'MANAGER',
      calcType: 'PERCENT_OF_PROFIT',
      value: 20,
      priority: 100,
    });
    expect(rule.status, JSON.stringify(rule.body)).toBe(201);

    const { projectId, pay } = await sale(manager, rop, ['8000000']);
    await ceo.post('/api/v1/expenses', {
      scope: 'PROJECT',
      projectId,
      category: 'DEVELOPMENT',
      amount: '3000000',
      expenseDate: today(),
    });
    // Маржа 66.67% → база 1 000 000 × 66.67% = 666 700 → 20% = 133 340
    const second = await pay('1000000');
    const mine = second.commissions.find((c: { role: string }) => c.role === 'MANAGER');
    expect(mine).toMatchObject({ baseAmountUzs: '666700.00', amountUzs: '133340.00' });

    const list = (await ceo.get('/api/v1/commissions?status=ACCRUED&pageSize=100')).body.items;
    const ids = list.map((c: { id: string }) => c.id);
    expect((await rop.post('/api/v1/commissions/approve', { ids })).status).toBe(403);
    expect((await ceo.post('/api/v1/commissions/pay', { ids })).status).toBe(422);
    expect((await ceo.post('/api/v1/commissions/approve', { ids })).body.updated).toBe(ids.length);
    const approved = (await manager.get('/api/v1/commissions')).body.items[0];
    expect(approved.status).toBe('APPROVED');
    expect(approved.approvedBy.id).toBe(fx.users.ceo.id);
    expect((await ceo.post('/api/v1/commissions/pay', { ids })).status).toBe(200);
    expect(await prisma.commission.count({ where: { status: 'PAID' } })).toBe(ids.length);
    expect(await prisma.auditLog.count({ where: { action: 'commission.pay' } })).toBe(ids.length);
  });
});

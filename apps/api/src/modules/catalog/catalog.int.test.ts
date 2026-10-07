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

const drain = async () => {
  while ((await app.get(OutboxDispatcher).processBatch()) > 0);
};
const today = () => new Date(Date.now() + 5 * 3_600_000).toISOString().slice(0, 10);

describe('Категории доходов и расходов', () => {
  it('CEO редактирует справочник; расходы и прочие поступления — по категориям', async () => {
    const ceo = await Client.login(app, 'ceo@test.uz');
    const rop = await Client.login(app, 'rop@test.uz');

    const all = (await rop.get('/api/v1/finance-categories')).body;
    expect(all.find((c: { code: string }) => c.code === 'RENT')).toMatchObject({
      kind: 'EXPENSE',
      name: 'Аренда',
      isOverhead: true,
      accountHint: '9420',
    });
    expect(
      (await rop.get('/api/v1/finance-categories?kind=INCOME')).body.every(
        (c: { kind: string }) => c.kind === 'INCOME',
      ),
    ).toBe(true);

    // Новая категория расходов; РОП менять справочник не может
    const created = await ceo.post('/api/v1/finance-categories', {
      kind: 'EXPENSE',
      name: 'Обучение сотрудников',
      accountHint: '9420',
    });
    expect(created.status, JSON.stringify(created.body)).toBe(201);
    expect(created.body.code).toMatch(/^C_[0-9A-F]{8}$/);
    expect(
      (await rop.post('/api/v1/finance-categories', { kind: 'EXPENSE', name: 'x' })).status,
    ).toBe(403);

    // Расход с новой категорией; переименование видно в расходе
    const e = await ceo.post('/api/v1/expenses', {
      scope: 'COMPANY',
      category: created.body.code,
      amount: '500000',
      expenseDate: today(),
    });
    expect(e.status, JSON.stringify(e.body)).toBe(201);
    expect(e.body.categoryName).toBe('Обучение сотрудников');
    await ceo.put(`/api/v1/finance-categories/${created.body.id}`, {
      kind: 'EXPENSE',
      name: 'Курсы и обучение',
      accountHint: '9420',
    });
    expect((await ceo.get('/api/v1/expenses')).body.items[0].categoryName).toBe('Курсы и обучение');
    // Используемую категорию не удалить; неизвестную — не выбрать; доходную — не выбрать для расхода
    expect((await ceo.delete(`/api/v1/finance-categories/${created.body.id}`)).status).toBe(422);
    for (const category of ['NOPE', 'PARTNER'])
      expect(
        (
          await ceo.post('/api/v1/expenses', {
            scope: 'COMPANY',
            category,
            amount: '1',
            expenseDate: today(),
          })
        ).status,
      ).toBe(422);
    // Тип категории не меняется
    expect(
      (
        await ceo.put(`/api/v1/finance-categories/${created.body.id}`, {
          kind: 'INCOME',
          name: 'x',
        })
      ).status,
    ).toBe(422);

    // Прочие поступления: в финансовом обзоре и операционной прибыли
    const inc = await ceo.post('/api/v1/other-incomes', {
      category: 'PARTNER',
      amount: '2000000',
      incomeDate: today(),
      description: 'Вознаграждение партнёра',
    });
    expect(inc.status, JSON.stringify(inc.body)).toBe(201);
    expect(inc.body).toMatchObject({
      categoryName: 'Партнёрское вознаграждение',
      amountUzs: '2000000.00',
    });
    expect(
      (
        await ceo.post('/api/v1/other-incomes', {
          category: 'RENT',
          amount: '1',
          incomeDate: today(),
        })
      ).status,
    ).toBe(422);
    expect((await rop.get('/api/v1/other-incomes')).status).toBe(403);
    const s = (await ceo.get('/api/v1/finance/summary?period=month')).body;
    expect(s.otherIncomeUzs).toBe('2000000.00');
    // 0 оплат − 0,5 млн расходов компании + 2 млн прочих
    expect(s.operatingProfitUzs).toBe('1500000.00');
    expect((await rop.get('/api/v1/finance/summary?period=month')).body.otherIncomeUzs).toBeNull();
    expect((await ceo.delete(`/api/v1/other-incomes/${inc.body.id}`)).status).toBe(204);
    expect((await ceo.get('/api/v1/finance/summary?period=month')).body.otherIncomeUzs).toBe(
      '0.00',
    );
  });
});

describe('Тарифы, ставки исполнителей и себестоимость проекта', () => {
  it('тариф → КП → оплата → плановая себестоимость → личная ставка → начисление; накладные', async () => {
    const ceo = await Client.login(app, 'ceo@test.uz');
    const manager = await Client.login(app, 'manager@test.uz');
    const rop = await Client.login(app, 'rop@test.uz');
    const executor = await Client.login(app, 'executor@test.uz');

    // Тариф SMM «Эконом» из начальных данных: 650 USD, 8 рилсов, 8 обложек, 4 карусели, 15 сторис
    const tariffs = (await ceo.get('/api/v1/tariffs')).body;
    const smm = tariffs.find(
      (t: { service: { name: string }; name: string }) =>
        t.service.name === 'SMM' && t.name === 'Эконом',
    );
    expect(smm).toMatchObject({ price: '650.00', currency: 'USD' });
    expect(smm.items).toHaveLength(4);
    // 650 × 12 650 = 8 222 500; исполнители: 8×10$=1 012 000 + 8×70 000 + 4×150 000 + 15×50 000 = 2 922 000
    expect(smm.economics).toMatchObject({ priceUzs: '8222500.00', executorsUzs: '2922000.00' });
    // Менеджер видит тариф (для КП), но не экономику
    const forManager = (await manager.get('/api/v1/tariffs')).body.find(
      (t: { id: string }) => t.id === smm.id,
    );
    expect(forManager.economics).toBeNull();
    expect((await manager.put(`/api/v1/tariffs/${smm.id}`, { ...smm })).status).toBe(403);

    // CEO меняет цену и состав: веб-разработчику фикс за проект
    const site = tariffs.find(
      (t: { service: { name: string }; name: string }) =>
        t.service.name === 'Сайт' && t.name === 'Стандарт',
    );
    expect(site.items[0]).toMatchObject({
      kind: 'FIXED',
      specialty: 'DEVELOPER',
      amount: '5000000.00',
    });
    const upd = await ceo.put(`/api/v1/tariffs/${site.id}`, {
      serviceId: site.service.id,
      name: 'Стандарт',
      price: '1200',
      currency: 'USD',
      items: [
        {
          kind: 'FIXED',
          specialty: 'DEVELOPER',
          amount: '5500000',
          currency: 'UZS',
          label: 'Веб-разработчик',
        },
      ],
    });
    expect(upd.status, JSON.stringify(upd.body)).toBe(200);
    expect(upd.body).toMatchObject({ price: '1200.00', economics: { executorsUzs: '5500000.00' } });

    // Личная ставка видеографа: 12 $ за рилс (карточка сотрудника)
    await prisma.employee.update({
      where: { userId: fx.users.executor.id },
      data: { specialty: 'VIDEOGRAPHER' },
    });
    const works = (await ceo.get('/api/v1/work-items')).body;
    const reel = works.find((w: { code: string }) => w.code === 'REEL');
    const saved = await ceo.put(`/api/v1/users/${fx.users.executor.id}/rates`, {
      rates: [{ workItemId: reel.id, rate: '12', currency: 'USD' }],
    });
    expect(saved.status, JSON.stringify(saved.body)).toBe(200);
    expect(
      saved.body.find((r: { workItem: { code: string } }) => r.workItem.code === 'REEL'),
    ).toMatchObject({ rate: '12.00' });
    expect((await executor.get(`/api/v1/users/${fx.users.executor.id}/rates`)).status).toBe(200);
    expect((await executor.get(`/api/v1/users/${fx.users.manager.id}/rates`)).status).toBe(403);
    expect(
      (await rop.put(`/api/v1/users/${fx.users.executor.id}/rates`, { rates: [] })).status,
    ).toBe(403);

    // Продажа SMM по тарифу
    const refs = (await manager.get('/api/v1/references')).body;
    const service = refs.services.find((x: { code: string }) => x.code === 'SMM');
    const lead = await manager.post('/api/v1/leads', {
      contactName: 'Tariff',
      phone: '+998901112200',
      sourceId: refs.sources[0].id,
      serviceId: service.id,
    });
    const conv = await rop.post(`/api/v1/leads/${lead.body.id}/convert`, {
      clientName: 'Tariff LLC',
      amount: '8222500',
    });
    const dealId = conv.body.dealId as string;
    const kp = await manager.post('/api/v1/proposals', {
      dealId,
      title: 'SMM Эконом',
      items: [{ tariffId: smm.id, description: 'SMM — Эконом', quantity: 1, unitPrice: '8222500' }],
    });
    expect(kp.status, JSON.stringify(kp.body)).toBe(201);
    expect(kp.body.items[0]).toMatchObject({
      tariff: { id: smm.id, name: 'Эконом' },
      service: { id: service.id },
    });
    await manager.post(`/api/v1/proposals/${kp.body.id}/send`);
    await manager.post(`/api/v1/proposals/${kp.body.id}/accept`);
    const contract = await manager.post('/api/v1/contracts', {
      dealId,
      proposalId: kp.body.id,
      contractDate: today(),
      amount: '8222500',
    });
    await manager.post(`/api/v1/contracts/${contract.body.id}/sign`);
    const pay = await manager.post('/api/v1/payments', {
      dealId,
      amount: '8222500',
      type: 'FULL',
      method: 'BANK',
    });
    const confirmed = await rop.post(`/api/v1/payments/${pay.body.id}/confirm`, {});
    const projectId = confirmed.body.project.id as string;
    await drain();

    // Плановая себестоимость из тарифа
    let lines = (await rop.get(`/api/v1/projects/${projectId}/cost-lines`)).body;
    expect(
      lines.map((l: { label: string; quantity: string; rate: string; currency: string }) => [
        l.label,
        l.quantity,
        l.rate,
        l.currency,
      ]),
    ).toEqual([
      ['Рилс (съёмка)', '8', '10.00', 'USD'],
      ['Обложка', '8', '70000.00', 'UZS'],
      ['Карусель (5 картинок)', '4', '150000.00', 'UZS'],
      ['Сторис', '15', '50000.00', 'UZS'],
    ]);
    let fin = (await rop.get(`/api/v1/projects/${projectId}/finance`)).body;
    expect(fin.plannedCostUzs).toBe('2922000.00');

    // Видеограф в команде → его строка с личной ставкой 12 $
    await rop.post(`/api/v1/projects/${projectId}/members`, { userId: fx.users.executor.id });
    await drain();
    lines = (await rop.get(`/api/v1/projects/${projectId}/cost-lines`)).body;
    const reelLine = lines[0];
    expect(reelLine).toMatchObject({
      rate: '12.00',
      personalRate: true,
      assignee: { id: fx.users.executor.id },
      amount: '96.00',
    });
    // Без исполнителя не начислить; исполнитель вне команды — нельзя
    expect((await rop.post(`/api/v1/cost-lines/${lines[1].id}/accrue`)).status).toBe(422);
    expect(
      (await rop.patch(`/api/v1/cost-lines/${lines[1].id}`, { assigneeId: fx.users.manager.id }))
        .status,
    ).toBe(422);
    // Менеджер (без права на расходы проекта) не начисляет
    expect((await manager.post(`/api/v1/cost-lines/${reelLine.id}/accrue`)).status).toBe(403);

    // Начислить → расход проекта «Исполнитель» на 96 $
    const acc = await rop.post(`/api/v1/cost-lines/${reelLine.id}/accrue`);
    expect(acc.status, JSON.stringify(acc.body)).toBe(200);
    expect(acc.body).toMatchObject({
      status: 'ACCRUED',
      expense: { number: expect.stringMatching(/^EXP-/) },
    });
    const exp = await prisma.expense.findFirstOrThrow({ where: { projectId } });
    expect(exp).toMatchObject({
      category: 'EXECUTOR',
      payeeUserId: fx.users.executor.id,
      currency: 'USD',
    });
    expect(exp.amount.toFixed(2)).toBe('96.00');
    expect((await rop.post(`/api/v1/cost-lines/${reelLine.id}/accrue`)).status).toBe(422);
    fin = (await rop.get(`/api/v1/projects/${projectId}/finance`)).body;
    expect(fin.expensesUzs).toBe('1214400.00');
    expect(fin.plannedCostUzs).toBe('1910000.00');

    // Отмена плановой строки
    expect((await rop.post(`/api/v1/cost-lines/${lines[3].id}/cancel`)).status).toBe(204);
    expect((await rop.get(`/api/v1/projects/${projectId}/cost-lines`)).body).toHaveLength(3);

    // Накладные: аренда 10 млн за месяц делится на проекты месяца (1) → весь в проект; делитель 5 → 2 млн
    await ceo.post('/api/v1/expenses', {
      scope: 'COMPANY',
      category: 'RENT',
      amount: '10000000',
      expenseDate: today(),
    });
    fin = (await ceo.get(`/api/v1/projects/${projectId}/finance`)).body;
    expect(fin.overheadUzs).toBe('10000000.00');
    expect((await ceo.put('/api/v1/settings/finance', { overheadDivisor: 5 })).status).toBe(200);
    fin = (await ceo.get(`/api/v1/projects/${projectId}/finance`)).body;
    expect(fin.overheadUzs).toBe('2000000.00');
    expect(fin.netProfitUzs).toBe((Number(fin.grossProfitUzs) - 2000000).toFixed(2));
    const profit = (await ceo.get('/api/v1/finance/projects')).body.items[0];
    expect(profit).toMatchObject({ overheadUzs: '2000000.00' });
    expect((await rop.put('/api/v1/settings/finance', { overheadDivisor: null })).status).toBe(403);
  });
});

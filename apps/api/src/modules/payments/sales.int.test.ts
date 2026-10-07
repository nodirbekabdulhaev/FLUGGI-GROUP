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

/** Лид → квалификация → сделка на 9 000 000 UZS у manager@test.uz (отдел 1, РОП rop@test.uz). */
async function dealFixture(manager: Client, rop: Client, amount = '9000000') {
  const refs = (await manager.get('/api/v1/references')).body;
  const lead = await manager.post('/api/v1/leads', {
    contactName: 'Hamza',
    companyName: 'Hamza Textile',
    phone: '+998901234567',
    sourceId: refs.sources[0].id,
    serviceId: refs.services[0].id,
  });
  const conv = await rop.post(`/api/v1/leads/${lead.body.id}/convert`, {
    clientName: 'Hamza Textile',
    amount,
  });
  return { dealId: conv.body.dealId as string, serviceId: refs.services[0].id as string };
}

const stageOf = async (c: Client, dealId: string) =>
  (await c.get(`/api/v1/deals/${dealId}`)).body.stage.code;

describe('Сценарий приёмки §84, шаги 9–16 и 25', () => {
  it('КП → версия → отправка → принятие → договор → подпись → оплата → проект + комиссии', async () => {
    const manager = await Client.login(app, 'manager@test.uz');
    const rop = await Client.login(app, 'rop@test.uz');
    const { dealId, serviceId } = await dealFixture(manager, rop);

    // Шаг 9: КП v1
    const kp = await manager.post('/api/v1/proposals', {
      dealId,
      title: 'SMM на 3 месяца',
      items: [{ serviceId, description: 'SMM', quantity: 3, unitPrice: '3000000' }],
      paymentTerms: '50% предоплата',
    });
    expect(kp.status, JSON.stringify(kp.body)).toBe(201);
    expect(kp.body.total).toBe('9000000.00');
    expect(kp.body.currentVersion).toBe(1);

    // ТЗ §16: изменение → v2 (11 млн) → v3 (10.5 млн)
    const v2 = await manager.put(`/api/v1/proposals/${kp.body.id}`, {
      title: 'SMM на 3 месяца',
      items: [{ serviceId, description: 'SMM + сторис', quantity: 1, unitPrice: '11000000' }],
      versionComment: 'Добавили сторис',
    });
    expect(v2.body.currentVersion).toBe(2);
    const v3 = await manager.put(`/api/v1/proposals/${kp.body.id}`, {
      title: 'SMM на 3 месяца',
      items: [
        {
          serviceId,
          description: 'SMM + сторис',
          quantity: 1,
          unitPrice: '11000000',
          discountPct: 4.5454545,
        },
      ],
    });
    expect(v3.body.total).toBe('10500000.00');
    const versions = (await manager.get(`/api/v1/proposals/${kp.body.id}/versions`)).body;
    expect(versions.map((v: { version: number; total: string }) => [v.version, v.total])).toEqual([
      [3, '10500000.00'],
      [2, '11000000.00'],
      [1, '9000000.00'],
    ]);

    // PDF
    const pdf = await manager.get(`/api/v1/documents/proposals/${kp.body.id}?format=pdf`);
    expect(pdf.status).toBe(200);
    expect(pdf.headers['content-type']).toBe('application/pdf');

    // Согласование у РОП: пока на согласовании — отправить нельзя
    await manager.post(`/api/v1/proposals/${kp.body.id}/submit-approval`);
    expect((await manager.post(`/api/v1/proposals/${kp.body.id}/send`)).status).toBe(422);
    expect((await manager.post(`/api/v1/proposals/${kp.body.id}/approve`)).status).toBe(403);
    expect((await rop.post(`/api/v1/proposals/${kp.body.id}/approve`)).status).toBe(200);

    // Шаг 10: отправлено → сделка «КП отправлено»
    expect((await manager.post(`/api/v1/proposals/${kp.body.id}/send`)).status).toBe(200);
    expect(await stageOf(manager, dealId)).toBe('PROPOSAL_SENT');

    // Шаг 11: принято → сделка «Переговоры», сумма сделки = итог КП
    expect((await manager.post(`/api/v1/proposals/${kp.body.id}/accept`)).status).toBe(200);
    const deal = (await manager.get(`/api/v1/deals/${dealId}`)).body;
    expect(deal.stage.code).toBe('NEGOTIATION');
    expect(deal.amount).toBe('10500000.00');
    expect(
      (
        await manager.put(`/api/v1/proposals/${kp.body.id}`, {
          title: 'x',
          items: [{ description: 'x', quantity: 1, unitPrice: 1 }],
        })
      ).status,
    ).toBe(422);

    // Шаг 12–13: договор из КП → подписан → «Ожидаем оплату»
    expect(
      (await manager.post(`/api/v1/deals/${dealId}/stage`, { stageCode: 'AWAITING_PAYMENT' }))
        .status,
    ).toBe(422);
    const contract = await manager.post('/api/v1/contracts', {
      dealId,
      proposalId: kp.body.id,
      contractDate: '2026-10-06',
      amount: '10500000',
    });
    expect(contract.status, JSON.stringify(contract.body)).toBe(201);
    expect(await stageOf(manager, dealId)).toBe('CONTRACT');
    expect((await manager.post(`/api/v1/contracts/${contract.body.id}/sign`)).status).toBe(200);
    expect(await stageOf(manager, dealId)).toBe('AWAITING_PAYMENT');

    // Шаг 14: предоплата 50%; менеджер не может подтвердить сам
    const prepay = await manager.post('/api/v1/payments', {
      dealId,
      contractId: contract.body.id,
      amount: '5250000',
      type: 'PREPAYMENT',
      method: 'BANK',
    });
    expect(prepay.status).toBe(201);
    expect(prepay.body.status).toBe('PENDING');
    expect((await manager.post(`/api/v1/payments/${prepay.body.id}/confirm`, {})).status).toBe(403);

    // Шаг 15–16, 25: РОП подтверждает → PAID → проект + комиссии
    const confirmed = await rop.post(`/api/v1/payments/${prepay.body.id}/confirm`, {});
    expect(confirmed.status, JSON.stringify(confirmed.body)).toBe(200);
    expect(confirmed.body.payment.status).toBe('PAID');
    expect(confirmed.body.projectCreated).toBe(true);
    expect(confirmed.body.project.number).toBe('P-00001');
    const comms = confirmed.body.commissions
      .map((c: { role: string; amountUzs: string }) => [c.role, c.amountUzs])
      .sort();
    expect(comms).toEqual([
      ['MANAGER', '525000.00'],
      ['ROP', '525000.00'],
    ]);

    const won = (await manager.get(`/api/v1/deals/${dealId}`)).body;
    expect(won.status).toBe('WON');
    expect(won.stage.code).toBe('PAID');
    const project = await prisma.project.findFirstOrThrow({ where: { dealId } });
    expect(project.ropId).toBe(fx.users.rop.id); // Rule 4
    expect(project.managerId).toBe(fx.users.manager.id);
    expect(project.clientId).toBe(won.client.id); // Rule 1
    expect(project.price.toFixed(2)).toBe('10500000.00');

    // Второй платёж: проект не дублируется
    const final = await manager.post('/api/v1/payments', {
      dealId,
      amount: '5250000',
      type: 'FINAL',
      method: 'BANK',
    });
    const c2 = await rop.post(`/api/v1/payments/${final.body.id}/confirm`, {});
    expect(c2.body.projectCreated).toBe(false);
    expect(await prisma.project.count()).toBe(1);

    const money = (await manager.get(`/api/v1/deals/${dealId}/money`)).body;
    expect(money).toMatchObject({
      contractUzs: '10500000.00',
      paidUzs: '10500000.00',
      receivableUzs: '0.00',
    });

    // Менеджер видит свои комиссии, РОП — отдела
    const mine = (await manager.get('/api/v1/commissions')).body;
    expect(
      mine.items.every((c: { user: { id: string } }) => c.user.id === fx.users.manager.id),
    ).toBe(true);
    expect(mine.totalUzs).toBe('1050000');
    expect((await rop.get('/api/v1/commissions')).body.total).toBe(4);

    // Уведомления: о своих действиях не уведомляем; РОП подтвердил оплату — менеджер получает «Оплата» и «Проект»
    while ((await app.get(OutboxDispatcher).processBatch()) > 0);
    const types = async (c: Client) =>
      (await c.get('/api/v1/notifications?pageSize=100')).body.items.map(
        (n: { type: string }) => n.type,
      );
    expect(await types(rop)).toEqual(
      expect.arrayContaining(['contract.signed', 'proposal.approval']),
    );
    expect(await types(manager)).toEqual(
      expect.arrayContaining(['payment.paid', 'project.created']),
    );
    const ceo = await Client.login(app, 'ceo@test.uz');
    expect(await types(ceo)).toEqual(expect.arrayContaining(['payment.paid']));
  });
});

describe('Оплаты — правила и защита', () => {
  it('двойное подтверждение не создаёт второй проект и комиссии', async () => {
    const manager = await Client.login(app, 'manager@test.uz');
    const rop = await Client.login(app, 'rop@test.uz');
    const { dealId } = await dealFixture(manager, rop);
    const p = await manager.post('/api/v1/payments', {
      dealId,
      amount: '9000000',
      type: 'FULL',
      method: 'CASH',
    });
    const [a, b] = await Promise.all([
      rop.post(`/api/v1/payments/${p.body.id}/confirm`, {}),
      rop.post(`/api/v1/payments/${p.body.id}/confirm`, {}),
    ]);
    expect([a.status, b.status].sort()).toEqual([200, 422]);
    expect(await prisma.project.count()).toBe(1);
    expect(await prisma.commission.count()).toBe(2);
  });

  it('возврат сторнирует комиссии и уменьшает оплаченное; больше оплаченного вернуть нельзя', async () => {
    const manager = await Client.login(app, 'manager@test.uz');
    const rop = await Client.login(app, 'rop@test.uz');
    const { dealId } = await dealFixture(manager, rop);
    const p = await manager.post('/api/v1/payments', {
      dealId,
      amount: '9000000',
      type: 'FULL',
      method: 'CASH',
    });
    await rop.post(`/api/v1/payments/${p.body.id}/confirm`, {});
    expect(
      (
        await rop.post(`/api/v1/payments/${p.body.id}/refund`, {
          amount: '10000000',
          method: 'CASH',
          comment: 'x',
        })
      ).status,
    ).toBe(422);
    const r = await rop.post(`/api/v1/payments/${p.body.id}/refund`, {
      amount: '1000000',
      method: 'CASH',
      comment: 'Частичный возврат',
    });
    expect(r.status, JSON.stringify(r.body)).toBe(201);
    const comm = await prisma.commission.findMany({ where: { paymentId: r.body.id } });
    expect(comm.map((c) => c.amountUzs.toFixed(2)).sort()).toEqual(['-100000.00', '-100000.00']);
    expect((await manager.get(`/api/v1/deals/${dealId}/money`)).body.refundedUzs).toBe(
      '1000000.00',
    );
  });

  it('оплаты, договоры и комиссии нельзя удалить даже в БД', async () => {
    const manager = await Client.login(app, 'manager@test.uz');
    const rop = await Client.login(app, 'rop@test.uz');
    const { dealId } = await dealFixture(manager, rop);
    const p = await manager.post('/api/v1/payments', {
      dealId,
      amount: '100',
      type: 'FULL',
      method: 'CASH',
    });
    await expect(prisma.payment.delete({ where: { id: p.body.id } })).rejects.toThrow();
  });

  it('РОП получает 15% при среднем чеке > 3000 USD (правило из БД, ТЗ §34)', async () => {
    const manager = await Client.login(app, 'manager@test.uz');
    const rop = await Client.login(app, 'rop@test.uz');
    const { dealId } = await dealFixture(manager, rop, '50000000'); // ≈ 3953 USD по курсу 12 650
    const p = await manager.post('/api/v1/payments', {
      dealId,
      amount: '50000000',
      type: 'FULL',
      method: 'BANK',
    });
    const res = await rop.post(`/api/v1/payments/${p.body.id}/confirm`, {});
    const ropComm = res.body.commissions.find((c: { role: string }) => c.role === 'ROP');
    expect(ropComm.rate).toBe('15');
    expect(ropComm.amountUzs).toBe('7500000.00');
    const mgr = res.body.commissions.find((c: { role: string }) => c.role === 'MANAGER');
    expect(mgr.rate).toBe('10');
  });

  it('чужие оплаты и КП недоступны менеджеру другого отдела', async () => {
    const manager = await Client.login(app, 'manager@test.uz');
    const rop = await Client.login(app, 'rop@test.uz');
    const { dealId } = await dealFixture(manager, rop);
    const p = await manager.post('/api/v1/payments', {
      dealId,
      amount: '100',
      type: 'FULL',
      method: 'CASH',
    });
    const other = await Client.login(app, 'manager2@test.uz');
    expect((await other.get(`/api/v1/payments/${p.body.id}`)).status).toBe(404);
    expect(
      (
        await other.post('/api/v1/payments', {
          dealId,
          amount: '100',
          type: 'FULL',
          method: 'CASH',
        })
      ).status,
    ).toBe(404);
    const ex = await Client.login(app, 'executor@test.uz');
    expect((await ex.get('/api/v1/payments')).status).toBe(403);
    expect((await ex.get('/api/v1/commissions')).status).toBe(403);
  });
});

describe('Исправление оплаты', () => {
  it('неподтверждённую оплату можно изменить, подтверждённую — нет', async () => {
    const manager = await Client.login(app, 'manager@test.uz');
    const rop = await Client.login(app, 'rop@test.uz');
    const { dealId } = await dealFixture(manager, rop);
    const p = await manager.post('/api/v1/payments', {
      dealId,
      amount: '500000',
      type: 'PREPAYMENT',
      method: 'CASH',
    });
    // Ошиблись в сумме: 500 000 → 5 000 000 (пробелы из поля ввода допустимы)
    const fixed = await manager.patch(`/api/v1/payments/${p.body.id}`, {
      amount: '5 000 000',
      method: 'BANK',
    });
    expect(fixed.status, JSON.stringify(fixed.body)).toBe(200);
    expect(fixed.body).toMatchObject({
      amount: '5000000.00',
      amountUzs: '5000000.00',
      method: 'BANK',
    });
    const audit = await prisma.auditLog.findFirst({ where: { action: 'payment.update' } });
    expect(audit?.changes).toMatchObject({ method: { old: 'CASH', new: 'BANK' } });

    const other = await Client.login(app, 'manager2@test.uz');
    expect((await other.patch(`/api/v1/payments/${p.body.id}`, { amount: '1' })).status).toBe(404);

    await rop.post(`/api/v1/payments/${p.body.id}/confirm`, {});
    const late = await manager.patch(`/api/v1/payments/${p.body.id}`, { amount: '1' });
    expect(late.status).toBe(422);
    expect(late.body.error.message).toMatch(/возврат/);
  });
});

describe('Файлы', () => {
  it('загрузка PDF к договору, скачивание с проверкой прав, отказ для поддельного типа', async () => {
    const manager = await Client.login(app, 'manager@test.uz');
    const rop = await Client.login(app, 'rop@test.uz');
    const { dealId } = await dealFixture(manager, rop);
    const pdf = Buffer.from('%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF');
    const upload = (name: string, type: string, buf: Buffer) => {
      const csrf = (manager as unknown as { cookies: Map<string, string> }).cookies.get(
        'fluggi_csrf',
      )!;
      const cookie = [...(manager as unknown as { cookies: Map<string, string> }).cookies]
        .map(([k, v]) => `${k}=${v}`)
        .join('; ');
      // eslint-disable-next-line @typescript-eslint/no-require-imports
      const request = require('supertest');
      return request(app.getHttpServer())
        .post('/api/v1/files')
        .set('Cookie', cookie)
        .set('x-csrf-token', csrf)
        .field('dealId', dealId)
        .field('category', 'CONTRACT')
        .attach('file', buf, { filename: name, contentType: type });
    };
    const ok = await upload('договор.pdf', 'application/pdf', pdf);
    expect(ok.status, JSON.stringify(ok.body)).toBe(201);
    const fake = await upload('virus.pdf', 'application/pdf', Buffer.from('MZ this is an exe'));
    expect(fake.status).toBe(422);
    const exe = await upload('x.exe', 'application/x-msdownload', Buffer.from('MZ'));
    expect(exe.status).toBe(422);

    const dl = await manager.get(`/api/v1/files/${ok.body.id}/download`);
    expect(dl.status).toBe(200);
    expect(dl.headers['content-disposition']).toContain('attachment');
    const other = await Client.login(app, 'manager2@test.uz');
    expect((await other.get(`/api/v1/files/${ok.body.id}/download`)).status).toBe(404);
  });
});

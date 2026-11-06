import type { INestApplication } from '@nestjs/common';
import { afterAll, beforeAll, beforeEach, describe, expect, it } from 'vitest';
import type { PrismaService } from '../../core/prisma/prisma.service';
import { Client, createTestApp, resetDatabase } from '../../test/helpers';

let app: INestApplication;
let prisma: PrismaService;

beforeAll(async () => ({ app, prisma } = await createTestApp()));
afterAll(() => app.close());
beforeEach(async () => {
  await resetDatabase(prisma);
});

const today = () => new Date(Date.now() + 5 * 3_600_000).toISOString().slice(0, 10);

describe('Генерация КП и договора', () => {
  it('реквизиты компании и клиента подставляются в КП и договор во всех форматах', async () => {
    const ceo = await Client.login(app, 'ceo@test.uz');
    const rop = await Client.login(app, 'rop@test.uz');
    const manager = await Client.login(app, 'manager@test.uz');

    // Реквизиты компании (только CEO)
    const company = (await ceo.get('/api/v1/settings/company')).body;
    const saved = await ceo.put('/api/v1/settings/company', {
      ...company,
      name: 'Fluggi',
      legalName: 'ООО «FLUGGI GROUP»',
      inn: '123456789',
      director: 'Абдулхаев Нодирбек Тестович',
      signerGenitive: 'директора Абдулхаева Нодирбека Тестовича',
      bank: 'АКБ «Капиталбанк»',
      mfo: '01088',
      account: '20208000900123456001',
      phone: '+998 90 000 00 00',
    });
    expect(saved.status, JSON.stringify(saved.body)).toBe(200);
    expect((await ceo.put('/api/v1/settings/company', { ...saved.body, mfo: '12' })).status).toBe(
      422,
    );

    // Сделка, КП, договор
    const refs = (await manager.get('/api/v1/references')).body;
    const lead = await manager.post('/api/v1/leads', {
      contactName: 'Docs',
      phone: '+998901112233',
      sourceId: refs.sources[0].id,
      serviceId: refs.services[0].id,
    });
    const conv = await rop.post(`/api/v1/leads/${lead.body.id}/convert`, {
      clientName: 'Docs Client',
      amount: '7500000',
    });
    const dealId = conv.body.dealId as string;
    const clientId = (await manager.get(`/api/v1/deals/${dealId}`)).body.client.id as string;
    const kp = await manager.post('/api/v1/proposals', {
      dealId,
      title: 'Сайт',
      implementationTerm: '30 рабочих дней',
      paymentTerms: '50% предоплата, 50% после сдачи',
      items: [{ description: 'Лендинг', quantity: 1, unitPrice: '7500000' }],
    });
    expect(kp.status, JSON.stringify(kp.body)).toBe(201);

    // КП: HTML с позицией и суммой прописью; PDF, Word, TXT
    const html = await manager.get(`/api/v1/documents/proposals/${kp.body.id}?format=html`);
    expect(html.status).toBe(200);
    expect(html.headers['content-type']).toContain('text/html');
    expect(html.text).toContain('Коммерческое предложение');
    expect(html.text).toContain('Лендинг');
    expect(html.text).toContain('Семь миллионов пятьсот тысяч сумов 00 тийинов');
    const pdf = await manager.get(`/api/v1/documents/proposals/${kp.body.id}?format=pdf`);
    expect(pdf.headers['content-type']).toBe('application/pdf');
    const docx = await manager.get(
      `/api/v1/documents/proposals/${kp.body.id}?format=docx&download=true`,
    );
    expect(docx.status).toBe(200);
    expect(docx.headers['content-disposition']).toContain('attachment');
    expect(docx.headers['content-type']).toContain('wordprocessingml');
    expect((await manager.get(`/api/v1/documents/proposals/${kp.body.id}?format=exe`)).status).toBe(
      422,
    );

    await manager.post(`/api/v1/proposals/${kp.body.id}/send`);
    await manager.post(`/api/v1/proposals/${kp.body.id}/accept`);
    const contract = await manager.post('/api/v1/contracts', {
      dealId,
      proposalId: kp.body.id,
      contractDate: '2026-10-07',
      amount: '7500000',
    });
    expect(contract.status, JSON.stringify(contract.body)).toBe(201);
    const cid = contract.body.id as string;

    // Без реквизитов клиента — предупреждение
    let check = (await manager.get(`/api/v1/documents/contracts/${cid}/check`)).body;
    expect(check.missing).toEqual(
      expect.arrayContaining([expect.stringContaining('Юридическое название клиента')]),
    );

    // Реквизиты клиента: проверка формата, затем сохранение
    expect(
      (await manager.put(`/api/v1/clients/${clientId}/requisites`, { inn: '12' })).status,
    ).toBe(422);
    const req = await manager.put(`/api/v1/clients/${clientId}/requisites`, {
      legalName: 'ООО «Docs Client»',
      inn: '987654321',
      director: 'Каримов Алишер',
      directorPosition: 'Директор',
      signerGenitive: 'директора Каримова Алишера',
      basis: 'Устава',
      bank: 'АКБ «Ипотека-банк»',
      mfo: '00419',
      account: '20208000100000000002',
    });
    expect(req.status, JSON.stringify(req.body)).toBe(200);
    expect((await manager.get(`/api/v1/clients/${clientId}`)).body.requisites).toMatchObject({
      inn: '987654321',
    });
    check = (await manager.get(`/api/v1/documents/contracts/${cid}/check`)).body;
    expect(check.missing).toEqual([]);

    const doc = await manager.get(`/api/v1/documents/contracts/${cid}?format=txt`);
    expect(doc.status).toBe(200);
    expect(doc.text).toContain(`ДОГОВОР № ${contract.body.number}`);
    expect(doc.text).toContain(
      'ООО «FLUGGI GROUP», именуемое в дальнейшем «Исполнитель», в лице директора Абдулхаева Нодирбека Тестовича',
    );
    expect(doc.text).toContain(
      'ООО «Docs Client», именуемое в дальнейшем «Заказчик», в лице директора Каримова Алишера',
    );
    expect(doc.text).toContain('7 500 000 сум (Семь миллионов пятьсот тысяч сумов 00 тийинов)');
    expect(doc.text).toContain('Срок оказания услуг: 30 рабочих дней');
    expect(doc.text).toContain('«07» октября 2026 г.');
    expect(doc.text).toContain('МФО: 00419');
    expect(doc.text).toContain('/ Абдулхаев Н.Т. /');
    for (const format of ['pdf', 'docx', 'html'])
      expect(
        (await manager.get(`/api/v1/documents/contracts/${cid}?format=${format}`)).status,
      ).toBe(200);

    // Свой шаблон договора (только CEO)
    const ds = (await ceo.get('/api/v1/settings/documents')).body;
    expect(ds.contractTemplate).toContain('{{contract.number}}');
    expect((await manager.get('/api/v1/settings/documents')).status).toBe(403);
    await ceo.put('/api/v1/settings/documents', {
      ...ds,
      city: 'г. Самарканд',
      contractTemplate:
        '# ДОГОВОР {{contract.number}}\nЗаказчик: {{client.name}}; {{unknown.key}}\n[[services]]',
    });
    const custom = (await manager.get(`/api/v1/documents/contracts/${cid}?format=txt`)).text;
    expect(custom).toContain('г. Самарканд');
    expect(custom).toContain('Заказчик: ООО «Docs Client»; ________');
    // Реквизиты сторон добавляются, даже если в шаблоне нет [[parties]]
    expect(custom).toContain('ЗАКАЗЧИК: ООО «Docs Client»');

    // Исполнитель без права на договоры документ не получит
    const executor = await Client.login(app, 'executor@test.uz');
    expect((await executor.get(`/api/v1/documents/contracts/${cid}?format=pdf`)).status).toBe(403);
  });
});

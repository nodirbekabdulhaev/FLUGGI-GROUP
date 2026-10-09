import { createHmac } from 'node:crypto';
import { createServer, type Server } from 'node:http';
import type { AddressInfo } from 'node:net';
import type { INestApplication } from '@nestjs/common';
import request from 'supertest';
import { afterAll, beforeAll, beforeEach, describe, expect, it } from 'vitest';
import { OutboxDispatcher } from '../../core/outbox/outbox.dispatcher';
import type { PrismaService } from '../../core/prisma/prisma.service';
import { Client, createTestApp, resetDatabase } from '../../test/helpers';

/** Заглушка Graph API: запоминает запросы, отвечает профилем, данными лида и id отправки. */
const graphCalls: { method: string; path: string; body: unknown }[] = [];
let graph: Server;
let app: INestApplication;
let prisma: PrismaService;
let fx: Awaited<ReturnType<typeof resetDatabase>>;

const SECRET = 'test-app-secret';

beforeAll(async () => {
  graph = createServer((req, res) => {
    let raw = '';
    req.on('data', (c) => (raw += c));
    req.on('end', () => {
      const path = new URL(req.url!, 'http://x').pathname;
      graphCalls.push({ method: req.method!, path, body: raw ? JSON.parse(raw) : null });
      res.setHeader('Content-Type', 'application/json');
      if (path === '/v21.0/LEAD123')
        return res.end(
          JSON.stringify({
            field_data: [
              { name: 'full_name', values: ['Азиз Каримов'] },
              { name: 'phone_number', values: ['+998 90 777-66-55'] },
              { name: 'какой_бюджет', values: ['1000$'] },
            ],
            campaign_name: 'SMM октябрь',
            ad_name: 'Видео 1',
            platform: 'ig',
          }),
        );
      if (path === '/v21.0/IGUSER1')
        return res.end(JSON.stringify({ name: 'Malika', username: 'malika.shop' }));
      if (path.endsWith('/messages'))
        return res.end(JSON.stringify({ message_id: `m_${Date.now()}` }));
      if (path.endsWith('/replies')) return res.end(JSON.stringify({ id: `r_${Date.now()}` }));
      res.statusCode = 404;
      res.end(JSON.stringify({ error: { message: 'Unknown path' } }));
    });
  });
  await new Promise<void>((r) => graph.listen(0, '127.0.0.1', r));
  process.env.META_APP_SECRET = SECRET;
  process.env.META_VERIFY_TOKEN = 'verify-me';
  process.env.META_PAGE_ACCESS_TOKEN = 'page-token';
  process.env.META_GRAPH_BASE = `http://127.0.0.1:${(graph.address() as AddressInfo).port}/v21.0`;
  ({ app, prisma } = await createTestApp());
});
afterAll(async () => {
  await app.close();
  graph.close();
});
beforeEach(async () => {
  fx = await resetDatabase(prisma);
  graphCalls.length = 0;
});

const drain = async () => {
  while ((await app.get(OutboxDispatcher).processBatch()) > 0);
};

const signed = (payload: object) => {
  const body = JSON.stringify(payload);
  return request(app.getHttpServer())
    .post('/api/v1/public/meta/webhook')
    .set('Content-Type', 'application/json')
    .set('X-Hub-Signature-256', `sha256=${createHmac('sha256', SECRET).update(body).digest('hex')}`)
    .send(body);
};

describe('Формы для сайта', () => {
  it('заявка с сайта → лид «Сайт» менеджеру; повтор — в тот же лид; боты отсеиваются', async () => {
    const ceo = await Client.login(app, 'ceo@test.uz');
    const refs = (await ceo.get('/api/v1/references')).body;
    const smm = refs.services.find((s: { code: string }) => s.code === 'SMM');
    const created = await ceo.post('/api/v1/lead-forms', {
      name: 'Главная — SMM',
      title: 'Оставьте заявку',
      serviceId: smm.id,
      teamId: fx.teams.team1.id,
      fields: [
        { key: 'name', label: 'Имя', type: 'text', required: true },
        { key: 'phone', label: 'Телефон', type: 'phone', required: true },
        { key: 'budget', label: 'Бюджет', type: 'select', options: ['до 500$', 'больше 500$'] },
        { key: 'message', label: 'Сообщение', type: 'textarea' },
      ],
    });
    expect(created.status, JSON.stringify(created.body)).toBe(201);
    const key = created.body.key as string;
    // Только CEO настраивает формы
    const manager = await Client.login(app, 'manager@test.uz');
    expect((await manager.get('/api/v1/lead-forms')).status).toBe(403);

    // Посетитель сайта: форма и CORS
    const anon = request(app.getHttpServer());
    const pub = await anon.get(`/api/v1/public/forms/${key}`);
    expect(pub.status).toBe(200);
    expect(pub.body).toMatchObject({ title: 'Оставьте заявку' });
    expect(pub.body.serviceId).toBeUndefined();
    const pre = await anon
      .options(`/api/v1/public/forms/${key}`)
      .set('Origin', 'https://fluggi.uz')
      .set('Access-Control-Request-Method', 'POST');
    expect(pre.status).toBe(204);
    expect(pre.headers['access-control-allow-origin']).toBe('*');

    const send = (data: Record<string, string>, extra: object = {}) =>
      anon
        .post(`/api/v1/public/forms/${key}`)
        .set('Origin', 'https://fluggi.uz')
        .send({ data, page: 'https://fluggi.uz/smm', utm: { utm_source: 'google' }, ...extra });

    const missing = await send({ name: 'Ali' });
    expect(missing.status).toBe(422);
    expect(missing.body.error.details[0].path).toBe('data.phone');

    const ok = await send({
      name: 'Ali',
      phone: '+998 90 123-45-67',
      budget: 'больше 500$',
      message: 'Нужен SMM',
    });
    expect(ok.status, JSON.stringify(ok.body)).toBe(200);
    expect(ok.body.message).toMatch(/Спасибо/);
    const lead = await prisma.lead.findFirstOrThrow({ include: { source: true } });
    expect(lead).toMatchObject({
      contactName: 'Ali',
      phone: '+998 90 123-45-67',
      serviceId: smm.id,
      ownerId: fx.users.manager.id,
    });
    expect(lead.source.code).toBe('WEBSITE');
    expect(lead.comment).toContain('Нужен SMM');
    expect(lead.comment).toContain('Бюджет: больше 500$');
    expect(lead.comment).toContain('UTM: utm_source=google');
    await drain();
    expect(
      await prisma.notification.count({
        where: { userId: fx.users.manager.id, type: 'lead.created' },
      }),
    ).toBeGreaterThan(0);

    // Повтор с тем же номером в другом формате — без нового лида
    expect((await send({ name: 'Ali', phone: '901234567' })).status).toBe(200);
    expect(await prisma.lead.count()).toBe(1);
    // Бот: заполнил скрытое поле
    expect(
      (await send({ name: 'Bot', phone: '+998901112233' }, { website: 'spam.com' })).status,
    ).toBe(200);
    expect(await prisma.lead.count()).toBe(1);

    // Обычная HTML-форма на WordPress (поля плоско)
    const html = await anon
      .post(`/api/v1/public/forms/${key}`)
      .set('Origin', 'https://fluggi.uz')
      .set('Accept', 'text/html')
      .type('form')
      .send({ name: 'Dilshod', phone: '+998935556677', utm_campaign: 'wp' });
    expect(html.status).toBe(200);
    expect(html.text).toContain('Спасибо');
    expect(await prisma.lead.count()).toBe(2);

    const subs = (await ceo.get(`/api/v1/lead-forms/${created.body.id}/submissions`)).body;
    expect(subs.map((s: { result: string }) => s.result).sort()).toEqual([
      'DUPLICATE',
      'LEAD_CREATED',
      'LEAD_CREATED',
      'SPAM',
    ]);
    const list = (await ceo.get('/api/v1/lead-forms')).body;
    expect(list[0]).toMatchObject({ submissions: 4, leads: 2 });
  });
});

describe('Instagram и таргет (Meta)', () => {
  it('проверка webhook и подписи', async () => {
    const v = await request(app.getHttpServer()).get(
      '/api/v1/public/meta/webhook?hub.mode=subscribe&hub.verify_token=verify-me&hub.challenge=42',
    );
    expect(v.status).toBe(200);
    expect(v.text).toBe('42');
    expect(
      (
        await request(app.getHttpServer()).get(
          '/api/v1/public/meta/webhook?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=42',
        )
      ).status,
    ).toBe(403);
    const forged = await request(app.getHttpServer())
      .post('/api/v1/public/meta/webhook')
      .set('X-Hub-Signature-256', 'sha256=deadbeef')
      .send({ object: 'instagram', entry: [] });
    expect(forged.status).toBe(403);
  });

  it('Директ → переписка и лид; комментарий с «цена» → лид; ответ из CRM; таргет → лид', async () => {
    const dm = {
      object: 'instagram',
      entry: [
        {
          id: 'PAGE1',
          messaging: [
            {
              sender: { id: 'IGUSER1' },
              recipient: { id: 'PAGE1' },
              timestamp: Date.now(),
              message: { mid: 'mid.1', text: 'Здравствуйте, сколько стоит SMM?' },
            },
          ],
        },
      ],
    };
    expect((await signed(dm)).status).toBe(200);
    expect((await signed(dm)).status).toBe(200); // повтор события — без дубля
    const thread = await prisma.socialThread.findFirstOrThrow({ include: { lead: true } });
    expect(thread).toMatchObject({
      channel: 'INSTAGRAM_DM',
      peerUsername: 'malika.shop',
      unread: 1,
    });
    expect(thread.lead).toMatchObject({ instagram: 'malika.shop' });
    expect(await prisma.socialMessage.count()).toBe(1);

    // Менеджер видит свою переписку, другой отдел — нет
    const owner = await prisma.user.findUniqueOrThrow({ where: { id: thread.ownerId! } });
    const ownerClient = await Client.login(app, owner.email);
    const other = await Client.login(app, 'manager2@test.uz');
    const inbox = (await ownerClient.get('/api/v1/inbox')).body;
    expect(inbox.items).toHaveLength(1);
    expect(inbox.unread).toBe(1);
    if (owner.id !== fx.users.otherManager.id)
      expect((await other.get('/api/v1/inbox')).body.items).toHaveLength(0);

    // Ответ в Директ уходит в Graph API
    const reply = await ownerClient.post(`/api/v1/inbox/${thread.id}/reply`, { text: 'От 650$' });
    expect(reply.status, JSON.stringify(reply.body)).toBe(200);
    expect(reply.body.map((m: { direction: string }) => m.direction)).toEqual(['IN', 'OUT']);
    expect(graphCalls.find((c) => c.path === '/v21.0/me/messages')?.body).toMatchObject({
      recipient: { id: 'IGUSER1' },
      message: { text: 'От 650$' },
    });

    // Комментарии: без ключевого слова — только переписка; «цена» — лид
    const comment = (id: string, from: string, text: string) => ({
      object: 'instagram',
      entry: [
        {
          id: 'PAGE1',
          changes: [
            {
              field: 'comments',
              value: {
                id,
                text,
                from: { id: from, username: from.toLowerCase() },
                media: { id: 'POST1' },
              },
            },
          ],
        },
      ],
    });
    await signed(comment('c1', 'FAN1', 'Красиво 🔥'));
    await signed(comment('c2', 'BUYER1', 'Какая цена?'));
    const leads = await prisma.lead.findMany({ select: { instagram: true } });
    expect(leads.map((l) => l.instagram).sort()).toEqual(['buyer1', 'malika.shop']);
    // Создать лид вручную из переписки
    const fan = await prisma.socialThread.findFirstOrThrow({ where: { peerId: 'FAN1' } });
    const ceo = await Client.login(app, 'ceo@test.uz');
    const made = await ceo.post(`/api/v1/inbox/${fan.id}/lead`);
    expect(made.status, JSON.stringify(made.body)).toBe(200);
    expect(made.body.lead).toBeTruthy();
    // Публичный ответ на комментарий
    const buyer = await prisma.socialThread.findFirstOrThrow({ where: { peerId: 'BUYER1' } });
    const r = await ceo.post(`/api/v1/inbox/${buyer.id}/reply`, {
      text: 'Написали в Директ',
      commentId: 'c2',
    });
    expect(r.status, JSON.stringify(r.body)).toBe(200);
    expect(graphCalls.some((c) => c.path === '/v21.0/c2/replies')).toBe(true);

    // Таргет: лид-форма → лид «Таргет» с кампанией
    await signed({
      object: 'page',
      entry: [{ id: 'PAGE1', changes: [{ field: 'leadgen', value: { leadgen_id: 'LEAD123' } }] }],
    });
    const ad = await prisma.lead.findFirstOrThrow({
      where: { source: { code: 'TARGET' } },
    });
    expect(ad).toMatchObject({ contactName: 'Азиз Каримов', phone: '+998 90 777-66-55' });
    expect(ad.comment).toContain('Кампания: SMM октябрь');
    expect(ad.comment).toContain('какой_бюджет: 1000$');

    const status = (await ceo.get('/api/v1/integrations/meta')).body;
    expect(status).toMatchObject({ configured: true, missing: [], threads: 3, adsLeads: 1 });
  });
});

describe('Финансы проекта — только CEO', () => {
  it('РОП не видит финансы и расходы проектов', async () => {
    const rop = await Client.login(app, 'rop@test.uz');
    for (const url of ['/api/v1/finance/summary', '/api/v1/expenses', '/api/v1/finance/projects'])
      expect((await rop.get(url)).status, url).toBe(403);
    const ceo = await Client.login(app, 'ceo@test.uz');
    expect((await ceo.get('/api/v1/finance/summary')).status).toBe(200);
  });
});

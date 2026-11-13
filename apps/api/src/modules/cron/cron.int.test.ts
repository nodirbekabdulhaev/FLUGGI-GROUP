import type { INestApplication } from '@nestjs/common';
import request from 'supertest';
import { afterAll, beforeAll, beforeEach, describe, expect, it } from 'vitest';
import type { PrismaService } from '../../core/prisma/prisma.service';
import { Client, createTestApp, resetDatabase } from '../../test/helpers';

// Задаётся в src/test/setup-env.ts
const SECRET = 'cron-secret-for-tests-0123456789';

let app: INestApplication;
let prisma: PrismaService;
let fixtures: Awaited<ReturnType<typeof resetDatabase>>;

beforeAll(async () => ({ app, prisma } = await createTestApp()));
afterAll(() => app.close());
beforeEach(async () => (fixtures = await resetDatabase(prisma)));

const cron = (secret?: string) => {
  const req = request(app.getHttpServer()).post('/api/v1/internal/cron');
  return secret ? req.set('x-cron-secret', secret) : req;
};

describe('Фоновые задачи по cron (виртуальный хостинг)', () => {
  it('без верного секрета эндпоинт недоступен', async () => {
    expect((await cron()).status).toBe(404);
    expect((await cron('wrong-secret-wrong-secret-000000')).status).toBe(404);
  });

  it('вызов cron доставляет события outbox: CEO получает уведомление о новом лиде', async () => {
    const manager = await Client.login(app, 'manager@test.uz');
    const refs = (await manager.get('/api/v1/references')).body;
    const lead = await manager.post('/api/v1/leads', {
      contactName: 'Cron Test',
      phone: '+998901234567',
      sourceId: refs.sources[0].id,
      serviceId: refs.services[0].id,
    });
    expect(lead.status).toBe(201);
    const pending = () => prisma.outboxEvent.count({ where: { processedAt: null } });
    expect(await pending()).toBeGreaterThan(0);

    const res = await cron(SECRET);
    expect(res.status).toBe(200);
    expect(res.body.events).toBeGreaterThan(0);
    expect(await pending()).toBe(0);
    const notes = await prisma.notification.count({ where: { userId: fixtures.users.ceo.id } });
    expect(notes).toBeGreaterThan(0);
  });
});

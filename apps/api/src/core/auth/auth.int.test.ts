import type { INestApplication } from '@nestjs/common';
import request from 'supertest';
import { afterAll, beforeAll, beforeEach, describe, expect, it } from 'vitest';
import type { PrismaService } from '../prisma/prisma.service';
import { Client, TEST_PASSWORD, createTestApp, resetDatabase } from '../../test/helpers';

let app: INestApplication;
let prisma: PrismaService;

beforeAll(async () => ({ app, prisma } = await createTestApp()));
afterAll(() => app.close());
beforeEach(() => resetDatabase(prisma));

describe('auth', () => {
  it('вход с верным паролем возвращает профиль, права и ставит httpOnly cookie', async () => {
    const res = await request(app.getHttpServer())
      .post('/api/v1/auth/login')
      .send({ email: 'Manager@Test.uz', password: TEST_PASSWORD });
    expect(res.status).toBe(200);
    expect(res.body.role.code).toBe('MANAGER');
    expect(res.body.permissions['lead.read']).toBe('OWN');
    const cookies = (res.headers['set-cookie'] as unknown as string[]).join('\n');
    expect(cookies).toMatch(/fluggi_session=.+HttpOnly/);
    expect(cookies).toMatch(/fluggi_csrf=/);
    // В БД хранится только хэш токена
    const session = await prisma.session.findFirstOrThrow();
    expect(cookies).not.toContain(session.tokenHash);
  });

  it('неверный пароль — понятная ошибка без утечки деталей', async () => {
    const res = await request(app.getHttpServer())
      .post('/api/v1/auth/login')
      .send({ email: 'manager@test.uz', password: 'wrong-password' });
    expect(res.status).toBe(401);
    expect(res.body.error).toMatchObject({
      code: 'UNAUTHENTICATED',
      message: 'Неверный email или пароль',
    });
    expect(JSON.stringify(res.body)).not.toMatch(/stack|prisma/i);
  });

  it('невалидный body → 422 с полями', async () => {
    const res = await request(app.getHttpServer()).post('/api/v1/auth/login').send({ email: 'x' });
    expect(res.status).toBe(422);
    expect(res.body.error.code).toBe('VALIDATION_ERROR');
    expect(res.body.error.details.map((d: { path: string }) => d.path)).toEqual(
      expect.arrayContaining(['email', 'password']),
    );
  });

  it('после 5 неудачных попыток аккаунт временно блокируется', async () => {
    for (let i = 0; i < 5; i++) {
      await request(app.getHttpServer())
        .post('/api/v1/auth/login')
        .send({ email: 'manager@test.uz', password: 'wrong-password' });
    }
    const res = await request(app.getHttpServer())
      .post('/api/v1/auth/login')
      .send({ email: 'manager@test.uz', password: TEST_PASSWORD });
    expect(res.status).toBe(429);
    expect(await prisma.auditLog.count({ where: { action: 'auth.locked' } })).toBe(1);
  });

  it('заблокированный пользователь не может войти', async () => {
    await prisma.user.update({ where: { email: 'manager@test.uz' }, data: { status: 'BLOCKED' } });
    const res = await request(app.getHttpServer())
      .post('/api/v1/auth/login')
      .send({ email: 'manager@test.uz', password: TEST_PASSWORD });
    expect(res.status).toBe(403);
  });

  it('без сессии — 401', async () => {
    const res = await request(app.getHttpServer()).get('/api/v1/auth/me');
    expect(res.status).toBe(401);
  });

  it('изменяющий запрос без CSRF-токена отклоняется', async () => {
    const client = await Client.login(app, 'ceo@test.uz');
    const res = await client.post('/api/v1/teams', { name: 'Новый отдел' }, { csrf: false });
    expect(res.status).toBe(403);
    expect(res.body.error.code).toBe('CSRF_INVALID');
  });

  it('чужой Origin отклоняется', async () => {
    const res = await request(app.getHttpServer())
      .post('/api/v1/auth/login')
      .set('Origin', 'https://evil.example')
      .send({ email: 'ceo@test.uz', password: TEST_PASSWORD });
    expect(res.status).toBe(403);
  });

  it('logout отзывает сессию', async () => {
    const client = await Client.login(app, 'ceo@test.uz');
    expect((await client.get('/api/v1/auth/me')).status).toBe(200);
    expect((await client.post('/api/v1/auth/logout')).status).toBe(204);
    expect((await client.get('/api/v1/auth/me')).status).toBe(401);
  });

  it('смена пароля закрывает другие сессии, текущая остаётся', async () => {
    const a = await Client.login(app, 'manager@test.uz');
    const b = await Client.login(app, 'manager@test.uz');
    const res = await a.post('/api/v1/auth/change-password', {
      currentPassword: TEST_PASSWORD,
      newPassword: 'NewPassword2026',
    });
    expect(res.status).toBe(204);
    expect((await a.get('/api/v1/auth/me')).status).toBe(200);
    expect((await b.get('/api/v1/auth/me')).status).toBe(401);
    await Client.login(app, 'manager@test.uz', 'NewPassword2026');
  });
});

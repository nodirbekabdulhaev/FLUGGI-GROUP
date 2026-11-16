import type { INestApplication } from '@nestjs/common';
import request from 'supertest';
import { afterAll, beforeAll, beforeEach, describe, expect, it } from 'vitest';
import type { PrismaService } from '../../core/prisma/prisma.service';
import { Client, createTestApp, resetDatabase } from '../../test/helpers';

let app: INestApplication;
let prisma: PrismaService;
let fixtures: Awaited<ReturnType<typeof resetDatabase>>;

beforeAll(async () => ({ app, prisma } = await createTestApp()));
afterAll(() => app.close());
beforeEach(async () => (fixtures = await resetDatabase(prisma)));

describe('users — сценарий приёмки §84 шаги 1–2', () => {
  it('CEO создаёт менеджера, менеджер входит с временным паролем', async () => {
    const ceo = await Client.login(app, 'ceo@test.uz');
    const res = await ceo.post('/api/v1/users', {
      email: 'asror@fluggi.uz',
      fullName: 'Asror',
      roleCode: 'MANAGER',
      teamId: fixtures.teams.team1.id,
    });
    expect(res.status).toBe(201);
    expect(res.body.user.role.code).toBe('MANAGER');
    expect(res.body.temporaryPassword).toMatch(/^.{14,}$/);

    const manager = await Client.login(app, 'asror@fluggi.uz', res.body.temporaryPassword);
    const me = await manager.get('/api/v1/auth/me');
    expect(me.body.team.id).toBe(fixtures.teams.team1.id);

    const audit = await prisma.auditLog.findFirstOrThrow({ where: { action: 'user.create' } });
    expect(audit.actorId).toBe(fixtures.users.ceo.id);
    expect(await prisma.outboxEvent.count({ where: { type: 'user.created' } })).toBe(1);
  });
});

describe('users — разграничение доступа на backend', () => {
  it('менеджер не видит сотрудников и не может их создавать', async () => {
    const manager = await Client.login(app, 'manager@test.uz');
    expect((await manager.get('/api/v1/users')).status).toBe(403);
    expect((await manager.get(`/api/v1/users/${fixtures.users.ceo.id}`)).status).toBe(403);
    const create = await manager.post('/api/v1/users', {
      email: 'x@test.uz',
      fullName: 'X',
      roleCode: 'MANAGER',
    });
    expect(create.status).toBe(403);
  });

  it('РОП видит только свой отдел', async () => {
    const rop = await Client.login(app, 'rop@test.uz');
    const list = await rop.get('/api/v1/users');
    const emails = list.body.items.map((u: { email: string }) => u.email).sort();
    expect(emails).toEqual(['manager@test.uz', 'rop@test.uz']);
    // Сотрудник чужого отдела для РОП «не существует»
    expect((await rop.get(`/api/v1/users/${fixtures.users.otherManager.id}`)).status).toBe(404);
  });

  it('исполнитель не имеет доступа к списку сотрудников', async () => {
    const executor = await Client.login(app, 'executor@test.uz');
    expect((await executor.get('/api/v1/users')).status).toBe(403);
  });

  it('HR создаёт сотрудников, но не может назначить роль CEO или изменить CEO', async () => {
    const hr = await Client.login(app, 'hr@test.uz');
    const ok = await hr.post('/api/v1/users', {
      email: 'd@test.uz',
      fullName: 'Designer',
      roleCode: 'EXECUTOR',
      specialty: 'DESIGNER',
    });
    expect(ok.status).toBe(201);
    expect(ok.body.user.specialty).toBe('DESIGNER');

    const ceoRole = await hr.post('/api/v1/users', {
      email: 'c@test.uz',
      fullName: 'CEO 2',
      roleCode: 'CEO',
    });
    expect(ceoRole.status).toBe(403);
    const editCeo = await hr.patch(`/api/v1/users/${fixtures.users.ceo.id}`, {
      fullName: 'Hacked',
    });
    expect(editCeo.status).toBe(403);
  });

  it('роли и журнал аудита доступны только своим ролям', async () => {
    const rop = await Client.login(app, 'rop@test.uz');
    expect((await rop.get('/api/v1/roles')).status).toBe(403);
    expect((await rop.get('/api/v1/audit-logs')).status).toBe(403);
    const hr = await Client.login(app, 'hr@test.uz');
    expect((await hr.get('/api/v1/audit-logs')).status).toBe(200);
    expect((await hr.get('/api/v1/roles')).status).toBe(403);
  });
});

describe('users — управление', () => {
  it('блокировка закрывает все сессии сотрудника', async () => {
    const manager = await Client.login(app, 'manager@test.uz');
    const ceo = await Client.login(app, 'ceo@test.uz');
    const res = await ceo.post(`/api/v1/users/${fixtures.users.manager.id}/block`);
    expect(res.status).toBe(200);
    expect(res.body.status).toBe('BLOCKED');
    expect((await manager.get('/api/v1/auth/me')).status).toBe(401);
  });

  it('нельзя заблокировать себя и последнего CEO', async () => {
    const ceo = await Client.login(app, 'ceo@test.uz');
    expect((await ceo.post(`/api/v1/users/${fixtures.users.ceo.id}/block`)).status).toBe(422);
  });

  it('нельзя сменить роль РОП, пока он руководит отделом', async () => {
    const ceo = await Client.login(app, 'ceo@test.uz');
    const res = await ceo.patch(`/api/v1/users/${fixtures.users.rop.id}`, { roleCode: 'MANAGER' });
    expect(res.status).toBe(422);
    expect(res.body.error.code).toBe('BUSINESS_RULE_VIOLATION');
  });

  it('изменение пишет старое и новое значение в аудит', async () => {
    const ceo = await Client.login(app, 'ceo@test.uz');
    await ceo.patch(`/api/v1/users/${fixtures.users.manager.id}`, {
      teamId: fixtures.teams.team2.id,
    });
    const log = await prisma.auditLog.findFirstOrThrow({ where: { action: 'user.update' } });
    expect(log.changes).toEqual({
      teamId: { old: fixtures.teams.team1.id, new: fixtures.teams.team2.id },
    });
  });

  it('дубль email → 409', async () => {
    const ceo = await Client.login(app, 'ceo@test.uz');
    const res = await ceo.post('/api/v1/users', {
      email: 'manager@test.uz',
      fullName: 'Dup',
      roleCode: 'MANAGER',
    });
    expect(res.status).toBe(409);
  });

  it('сброс пароля возвращает временный пароль один раз', async () => {
    const ceo = await Client.login(app, 'ceo@test.uz');
    const res = await ceo.post(`/api/v1/users/${fixtures.users.manager.id}/reset-password`);
    expect(res.status).toBe(200);
    await Client.login(app, 'manager@test.uz', res.body.temporaryPassword);
  });
});

describe('audit log', () => {
  it('записи нельзя изменить или удалить даже напрямую в БД', async () => {
    await Client.login(app, 'ceo@test.uz');
    const log = await prisma.auditLog.findFirstOrThrow();
    await expect(
      prisma.auditLog.update({ where: { id: log.id }, data: { action: 'x' } }),
    ).rejects.toThrow();
    await expect(prisma.auditLog.delete({ where: { id: log.id } })).rejects.toThrow();
  });
});

describe('users — удаление сотрудника', () => {
  it('открытая работа передаётся другому; email освобождается; вход невозможен', async () => {
    const ceo = await Client.login(app, 'ceo@test.uz');
    const manager = await Client.login(app, 'manager@test.uz');
    const refs = (await manager.get('/api/v1/references')).body;
    await manager.post('/api/v1/leads', {
      contactName: 'Уходящий лид',
      phone: '+998901110099',
      sourceId: refs.sources[0].id,
      serviceId: refs.services[0].id,
    });
    await manager.post('/api/v1/todos', { title: 'Перезвонить' });

    const work = (await ceo.get(`/api/v1/users/${fixtures.users.manager.id}/workload`)).body;
    expect(work).toMatchObject({ leads: 1, todos: 1 });
    expect(work.total).toBeGreaterThanOrEqual(2);

    // Без передачи — нельзя; себя и РОП-руководителя отдела — нельзя; HR — только CEO-права
    const noTarget = await ceo.delete(`/api/v1/users/${fixtures.users.manager.id}`);
    expect(noTarget.status).toBe(422);
    expect(noTarget.body.error.details[0].path).toBe('transferToId');
    expect((await ceo.delete(`/api/v1/users/${fixtures.users.ceo.id}`)).status).toBe(422);
    expect(
      (
        await ceo.delete(
          `/api/v1/users/${fixtures.users.rop.id}?transferToId=${fixtures.users.ceo.id}`,
        )
      ).status,
    ).toBe(422);
    expect((await manager.delete(`/api/v1/users/${fixtures.users.otherManager.id}`)).status).toBe(
      403,
    );

    const del = await ceo.delete(
      `/api/v1/users/${fixtures.users.manager.id}?transferToId=${fixtures.users.otherManager.id}`,
    );
    expect(del.status, JSON.stringify(del.body)).toBe(204);

    expect(await prisma.lead.count({ where: { ownerId: fixtures.users.otherManager.id } })).toBe(1);
    expect(await prisma.todo.count({ where: { ownerId: fixtures.users.otherManager.id } })).toBe(1);
    // Не в списке, не входит, email свободен
    const list = (await ceo.get('/api/v1/users?pageSize=100')).body.items;
    expect(list.map((u: { id: string }) => u.id)).not.toContain(fixtures.users.manager.id);
    expect(
      (
        await request(app.getHttpServer())
          .post('/api/v1/auth/login')
          .send({ email: 'manager@test.uz', password: 'TestPass2026' })
      ).status,
    ).toBe(401);
    expect((await manager.get('/api/v1/auth/me')).status).toBe(401);
    const again = await ceo.post('/api/v1/users', {
      email: 'manager@test.uz',
      fullName: 'Новый менеджер',
      roleCode: 'MANAGER',
    });
    expect(again.status, JSON.stringify(again.body)).toBe(201);
    expect(await prisma.auditLog.count({ where: { action: 'user.delete' } })).toBe(1);
  });
});

import type { INestApplication } from '@nestjs/common';
import { DEFAULT_ROLE_PERMISSIONS } from '@fluggi/contracts';
import { afterAll, beforeAll, beforeEach, describe, expect, it } from 'vitest';
import type { PrismaService } from '../../core/prisma/prisma.service';
import { Client, createTestApp, resetDatabase } from '../../test/helpers';

let app: INestApplication;
let prisma: PrismaService;

beforeAll(async () => ({ app, prisma } = await createTestApp()));
afterAll(() => app.close());
beforeEach(() => resetDatabase(prisma));

describe('roles', () => {
  it('изменённые права применяются сразу на backend, область OWN ограничивает данные', async () => {
    const manager = await Client.login(app, 'manager@test.uz');
    expect((await manager.get('/api/v1/users')).status).toBe(403);

    const ceo = await Client.login(app, 'ceo@test.uz');
    const roles = await ceo.get('/api/v1/roles');
    const managerRole = roles.body.find((r: { code: string }) => r.code === 'MANAGER');
    const granted = { ...DEFAULT_ROLE_PERMISSIONS.MANAGER, 'employee.read': 'OWN' };
    expect((await ceo.put(`/api/v1/roles/${managerRole.id}/permissions`, { permissions: granted })).status).toBe(200);

    const list = await manager.get('/api/v1/users');
    expect(list.status).toBe(200);
    expect(list.body.items.map((u: { email: string }) => u.email)).toEqual(['manager@test.uz']);

    await ceo.put(`/api/v1/roles/${managerRole.id}/permissions`, { permissions: DEFAULT_ROLE_PERMISSIONS.MANAGER });
    expect((await manager.get('/api/v1/users')).status).toBe(403);

    const logs = await prisma.auditLog.findMany({
      where: { action: 'role.permissions_update' },
      orderBy: { createdAt: 'asc' },
    });
    expect(logs.map((l) => l.changes)).toEqual([
      { 'employee.read': { old: null, new: 'OWN' } },
      { 'employee.read': { old: 'OWN', new: null } },
    ]);
  });

  it('у CEO нельзя отнять управление ролями', async () => {
    const ceo = await Client.login(app, 'ceo@test.uz');
    const roles = await ceo.get('/api/v1/roles');
    const ceoRole = roles.body.find((r: { code: string }) => r.code === 'CEO');
    const res = await ceo.put(`/api/v1/roles/${ceoRole.id}/permissions`, { permissions: { 'lead.read': 'ALL' } });
    expect(res.status).toBe(422);
  });
});

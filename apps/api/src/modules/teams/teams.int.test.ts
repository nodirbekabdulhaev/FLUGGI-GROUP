import type { INestApplication } from '@nestjs/common';
import { afterAll, beforeAll, beforeEach, describe, expect, it } from 'vitest';
import type { PrismaService } from '../../core/prisma/prisma.service';
import { Client, createTestApp, resetDatabase } from '../../test/helpers';

let app: INestApplication;
let prisma: PrismaService;
let fixtures: Awaited<ReturnType<typeof resetDatabase>>;

beforeAll(async () => ({ app, prisma } = await createTestApp()));
afterAll(() => app.close());
beforeEach(async () => (fixtures = await resetDatabase(prisma)));

describe('teams — несколько отделов продаж', () => {
  it('CEO создаёт отдел с РОП; РОП переводится в этот отдел', async () => {
    const ceo = await Client.login(app, 'ceo@test.uz');
    const res = await ceo.post('/api/v1/teams', { name: 'Отдел 3', headId: fixtures.users.rop.id });
    expect(res.status).toBe(201);
    expect(res.body.head.id).toBe(fixtures.users.rop.id);
    const rop = await prisma.user.findUniqueOrThrow({ where: { id: fixtures.users.rop.id } });
    expect(rop.teamId).toBe(res.body.id);
  });

  it('руководителем может быть только РОП', async () => {
    const ceo = await Client.login(app, 'ceo@test.uz');
    const res = await ceo.post('/api/v1/teams', { name: 'Отдел 3', headId: fixtures.users.manager.id });
    expect(res.status).toBe(422);
  });

  it('нельзя удалить отдел с сотрудниками; пустой удаляется мягко', async () => {
    const ceo = await Client.login(app, 'ceo@test.uz');
    expect((await ceo.delete(`/api/v1/teams/${fixtures.teams.team1.id}`)).status).toBe(422);
    await prisma.user.update({ where: { id: fixtures.users.otherManager.id }, data: { teamId: null } });
    expect((await ceo.delete(`/api/v1/teams/${fixtures.teams.team2.id}`)).status).toBe(204);
    const team = await prisma.team.findUniqueOrThrow({ where: { id: fixtures.teams.team2.id } });
    expect(team.deletedAt).not.toBeNull();
  });
});

import { CSRF_COOKIE, CSRF_HEADER, DEFAULT_ROLE_PERMISSIONS, PERMISSIONS, PERMISSION_CODES } from '@fluggi/contracts';
import type { RoleCode } from '@fluggi/contracts';
import { hash } from '@node-rs/argon2';
import type { INestApplication } from '@nestjs/common';
import request from 'supertest';
import { createApp } from '../app.factory';
import { PrismaService } from '../core/prisma/prisma.service';

export const TEST_PASSWORD = 'TestPass2026';

export async function createTestApp() {
  const app = await createApp();
  await app.init();
  return { app, prisma: app.get(PrismaService) };
}

let cachedHash: string | undefined;

/** Полная очистка и базовые данные: роли, права, отделы, по пользователю на роль. */
export async function resetDatabase(prisma: PrismaService) {
  await prisma.$executeRawUnsafe(
    'TRUNCATE audit_logs, outbox_events, sessions, employees, users, teams, role_permissions, permissions, roles RESTART IDENTITY CASCADE',
  );
  await prisma.permission.createMany({
    data: PERMISSION_CODES.map((code) => ({ code, description: PERMISSIONS[code], module: code.split('.')[0]! })),
  });
  const perms = new Map((await prisma.permission.findMany()).map((p) => [p.code, p.id]));
  for (const code of Object.keys(DEFAULT_ROLE_PERMISSIONS) as RoleCode[]) {
    const role = await prisma.role.create({ data: { code, name: code } });
    await prisma.rolePermission.createMany({
      data: Object.entries(DEFAULT_ROLE_PERMISSIONS[code]).map(([p, scope]) => ({
        roleId: role.id,
        permissionId: perms.get(p)!,
        scope: scope!,
      })),
    });
  }
  const team1 = await prisma.team.create({ data: { name: 'Отдел 1' } });
  const team2 = await prisma.team.create({ data: { name: 'Отдел 2' } });

  cachedHash ??= await hash(TEST_PASSWORD);
  const mk = async (email: string, roleCode: RoleCode, teamId?: string) => {
    const role = await prisma.role.findUniqueOrThrow({ where: { code: roleCode } });
    return prisma.user.create({
      data: { email, fullName: email.split('@')[0]!, passwordHash: cachedHash!, roleId: role.id, teamId, employee: { create: {} } },
    });
  };
  const users = {
    ceo: await mk('ceo@test.uz', 'CEO'),
    rop: await mk('rop@test.uz', 'ROP', team1.id),
    manager: await mk('manager@test.uz', 'MANAGER', team1.id),
    otherManager: await mk('manager2@test.uz', 'MANAGER', team2.id),
    executor: await mk('executor@test.uz', 'EXECUTOR'),
    hr: await mk('hr@test.uz', 'HR_ADMIN'),
  };
  await prisma.team.update({ where: { id: team1.id }, data: { headId: users.rop.id } });
  return { users, teams: { team1, team2 } };
}

/** HTTP-клиент с cookie-сессией и CSRF-заголовком, как у браузера. */
export class Client {
  private cookies = new Map<string, string>();

  constructor(private readonly app: INestApplication) {}

  static async login(app: INestApplication, email: string, password = TEST_PASSWORD) {
    const client = new Client(app);
    const res = await client.post('/api/v1/auth/login', { email, password });
    if (res.status !== 200) throw new Error(`login ${email} failed: ${res.status} ${JSON.stringify(res.body)}`);
    return client;
  }

  private store(res: request.Response) {
    const setCookie = res.headers['set-cookie'] as unknown as string[] | undefined;
    for (const c of setCookie ?? []) {
      const [pair] = c.split(';');
      const [name, ...rest] = pair!.split('=');
      const value = rest.join('=');
      if (value === '' || /Expires=Thu, 01 Jan 1970/i.test(c)) this.cookies.delete(name!);
      else this.cookies.set(name!, value);
    }
    return res;
  }

  private cookieHeader() {
    return [...this.cookies].map(([k, v]) => `${k}=${v}`).join('; ');
  }

  async get(url: string) {
    return this.store(await request(this.app.getHttpServer()).get(url).set('Cookie', this.cookieHeader()));
  }

  async send(method: 'post' | 'patch' | 'put' | 'delete', url: string, body?: object, opts: { csrf?: boolean } = {}) {
    let req = request(this.app.getHttpServer())[method](url).set('Cookie', this.cookieHeader());
    const csrf = this.cookies.get(CSRF_COOKIE);
    if (csrf && opts.csrf !== false) req = req.set(CSRF_HEADER, csrf);
    return this.store(await (body ? req.send(body) : req));
  }

  post(url: string, body?: object, opts?: { csrf?: boolean }) {
    return this.send('post', url, body, opts);
  }
  patch(url: string, body?: object) {
    return this.send('patch', url, body);
  }
  put(url: string, body?: object) {
    return this.send('put', url, body);
  }
  delete(url: string) {
    return this.send('delete', url);
  }
}

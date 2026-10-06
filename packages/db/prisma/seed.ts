/**
 * Seed.
 *  1. Роли и права (всегда; идемпотентно — существующие настройки прав не перезаписываются).
 *  2. Первый CEO для production: SEED_CEO_EMAIL + SEED_CEO_PASSWORD.
 *  3. Демо-данные (SEED_DEMO=true, по умолчанию вне production).
 *
 * Пароли демо-аккаунтов в репозитории не хранятся: берутся из SEED_DEMO_PASSWORD
 * или генерируются случайно и выводятся в консоль один раз.
 */
import { randomBytes } from 'node:crypto';
import { hash } from '@node-rs/argon2';
import {
  DEFAULT_ROLE_PERMISSIONS,
  PERMISSIONS,
  PERMISSION_CODES,
  type ExecutorSpecialty,
  type RoleCode,
} from '@fluggi/contracts';
import { PrismaClient } from '@prisma/client';

const prisma = new PrismaClient();

const ROLE_NAMES: Record<RoleCode, string> = {
  CEO: 'CEO / Владелец',
  ROP: 'Руководитель отдела продаж',
  MANAGER: 'Менеджер',
  EXECUTOR: 'Исполнитель',
  HR_ADMIN: 'HR / Администратор',
};

export const ARGON2_OPTIONS = { memoryCost: 19456, timeCost: 2, parallelism: 1 } as const;

function generatePassword(): string {
  // 12 символов base64url + гарантированная цифра → проходит политику паролей
  return `${randomBytes(9).toString('base64url')}7`;
}

async function seedRolesAndPermissions() {
  for (const code of PERMISSION_CODES) {
    await prisma.permission.upsert({
      where: { code },
      update: { description: PERMISSIONS[code], module: code.split('.')[0]! },
      create: { code, description: PERMISSIONS[code], module: code.split('.')[0]! },
    });
  }
  const permissions = await prisma.permission.findMany();
  const permissionIdByCode = new Map(permissions.map((p) => [p.code, p.id]));

  for (const code of Object.keys(ROLE_NAMES) as RoleCode[]) {
    const role = await prisma.role.upsert({
      where: { code },
      update: {},
      create: { code, name: ROLE_NAMES[code], isSystem: true },
    });
    const existing = await prisma.rolePermission.count({ where: { roleId: role.id } });
    // CEO всегда получает новые права; остальным роли — только при первом seed,
    // чтобы не затирать настройки, сделанные в интерфейсе.
    if (existing > 0 && code !== 'CEO') continue;
    const map = DEFAULT_ROLE_PERMISSIONS[code];
    await prisma.rolePermission.createMany({
      data: Object.entries(map).map(([permCode, scope]) => ({
        roleId: role.id,
        permissionId: permissionIdByCode.get(permCode)!,
        scope: scope!,
      })),
      skipDuplicates: true,
    });
  }
  console.log(`✓ Роли: ${Object.keys(ROLE_NAMES).length}, права: ${PERMISSION_CODES.length}`);
}

interface SeedUser {
  email: string;
  fullName: string;
  role: RoleCode;
  position?: string;
  specialty?: ExecutorSpecialty;
  team?: string;
}

async function upsertUser(u: SeedUser, password: string, teamIds: Map<string, string>) {
  const existing = await prisma.user.findUnique({ where: { email: u.email } });
  if (existing) return { created: false, id: existing.id };
  const role = await prisma.role.findUniqueOrThrow({ where: { code: u.role } });
  const user = await prisma.user.create({
    data: {
      email: u.email,
      fullName: u.fullName,
      passwordHash: await hash(password, ARGON2_OPTIONS),
      roleId: role.id,
      teamId: u.team ? teamIds.get(u.team) : undefined,
      employee: {
        create: { position: u.position, specialty: u.specialty, hiredAt: new Date('2025-01-15') },
      },
    },
  });
  return { created: true, id: user.id };
}

async function seedInitialCeo() {
  const email = process.env.SEED_CEO_EMAIL?.trim().toLowerCase();
  const password = process.env.SEED_CEO_PASSWORD;
  if (!email || !password) return;
  const { created } = await upsertUser(
    { email, fullName: process.env.SEED_CEO_NAME ?? 'CEO', role: 'CEO', position: 'CEO' },
    password,
    new Map(),
  );
  console.log(created ? `✓ Создан CEO ${email}` : `• CEO ${email} уже существует`);
}

const DEMO_TEAMS = ['Отдел продаж 1', 'Отдел продаж 2'];

const DEMO_USERS: SeedUser[] = [
  { email: 'ceo@fluggi.demo', fullName: 'Нодир Абдулхаев', role: 'CEO', position: 'CEO' },
  {
    email: 'rop@fluggi.demo',
    fullName: 'Азиз Каримов',
    role: 'ROP',
    position: 'РОП',
    team: 'Отдел продаж 1',
  },
  {
    email: 'rop2@fluggi.demo',
    fullName: 'Дилноза Юсупова',
    role: 'ROP',
    position: 'РОП',
    team: 'Отдел продаж 2',
  },
  {
    email: 'manager1@fluggi.demo',
    fullName: 'Асрор Рахимов',
    role: 'MANAGER',
    position: 'Менеджер по продажам',
    team: 'Отдел продаж 1',
  },
  {
    email: 'manager2@fluggi.demo',
    fullName: 'Малика Турсунова',
    role: 'MANAGER',
    position: 'Менеджер по продажам',
    team: 'Отдел продаж 1',
  },
  {
    email: 'manager3@fluggi.demo',
    fullName: 'Жасур Алимов',
    role: 'MANAGER',
    position: 'Менеджер по продажам',
    team: 'Отдел продаж 2',
  },
  {
    email: 'smm@fluggi.demo',
    fullName: 'Севара Назарова',
    role: 'EXECUTOR',
    specialty: 'SMM',
    position: 'SMM-менеджер',
  },
  {
    email: 'designer@fluggi.demo',
    fullName: 'Бобур Хасанов',
    role: 'EXECUTOR',
    specialty: 'DESIGNER',
    position: 'Дизайнер',
  },
  {
    email: 'video@fluggi.demo',
    fullName: 'Тимур Саидов',
    role: 'EXECUTOR',
    specialty: 'VIDEOGRAPHER',
    position: 'Видеограф',
  },
  {
    email: 'target@fluggi.demo',
    fullName: 'Камола Ибрагимова',
    role: 'EXECUTOR',
    specialty: 'TARGETOLOGIST',
    position: 'Таргетолог',
  },
  {
    email: 'dev@fluggi.demo',
    fullName: 'Шерзод Мирзаев',
    role: 'EXECUTOR',
    specialty: 'DEVELOPER',
    position: 'Разработчик',
  },
  {
    email: 'hr@fluggi.demo',
    fullName: 'Гульнора Ахмедова',
    role: 'HR_ADMIN',
    position: 'HR-менеджер',
  },
];

async function seedDemo() {
  const teamIds = new Map<string, string>();
  for (const name of DEMO_TEAMS) {
    const team = await prisma.team.upsert({ where: { name }, update: {}, create: { name } });
    teamIds.set(name, team.id);
  }

  const sharedPassword = process.env.SEED_DEMO_PASSWORD;
  const credentials: { email: string; role: RoleCode; password: string }[] = [];
  for (const u of DEMO_USERS) {
    const password = sharedPassword ?? generatePassword();
    const { created, id } = await upsertUser(u, password, teamIds);
    if (created) credentials.push({ email: u.email, role: u.role, password });
    if (u.role === 'ROP' && u.team) {
      await prisma.team.update({ where: { id: teamIds.get(u.team)! }, data: { headId: id } });
    }
  }

  console.log(`✓ Демо: ${DEMO_TEAMS.length} отдела, ${DEMO_USERS.length} сотрудников`);
  if (credentials.length > 0) {
    console.log('\nДемо-аккаунты (пароли показываются только сейчас):');
    console.table(credentials);
  } else {
    console.log('• Демо-аккаунты уже существуют, пароли не изменены');
  }
}

async function main() {
  await seedRolesAndPermissions();
  await seedInitialCeo();
  const demo = process.env.SEED_DEMO ?? (process.env.NODE_ENV === 'production' ? 'false' : 'true');
  if (demo === 'true') await seedDemo();
}

main()
  .catch((e) => {
    console.error(e);
    process.exitCode = 1;
  })
  .finally(() => prisma.$disconnect());

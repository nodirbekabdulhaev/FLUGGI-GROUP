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
import { computeLeadScore, toUzs } from '@fluggi/domain';
import { Prisma, PrismaClient } from '@prisma/client';
import { SOURCES, STAGES, seedReferences } from '../src/reference-data';

const prisma = new PrismaClient();

/** Пустая строка в .env (`SEED_DEMO=`) считается «не задано». */
function env(name: string): string | undefined {
  const value = process.env[name]?.trim();
  return value ? value : undefined;
}

const ROLE_NAMES: Record<RoleCode, string> = {
  CEO: 'CEO / Владелец',
  ROP: 'Руководитель отдела продаж',
  MANAGER: 'Менеджер',
  EXECUTOR: 'Исполнитель',
  HR_ADMIN: 'HR / Администратор',
  PROJECT_MANAGER: 'Проект-менеджер',
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
  /** Направления (проект-менеджер): коды из справочника directions */
  directions?: string[];
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
  const email = env('SEED_CEO_EMAIL')?.toLowerCase();
  const password = env('SEED_CEO_PASSWORD');
  if (!email || !password) return;
  const { created } = await upsertUser(
    { email, fullName: env('SEED_CEO_NAME') ?? 'CEO', role: 'CEO', position: 'CEO' },
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
  {
    email: 'pm@fluggi.demo',
    fullName: 'Дильноза Каримова',
    role: 'PROJECT_MANAGER',
    position: 'Проект-менеджер (Медиа)',
    directions: ['MEDIA'],
  },
];

async function seedDemo() {
  const teamIds = new Map<string, string>();
  for (const name of DEMO_TEAMS) {
    const team = await prisma.team.upsert({ where: { name }, update: {}, create: { name } });
    teamIds.set(name, team.id);
  }

  const sharedPassword = env('SEED_DEMO_PASSWORD');
  const credentials: { email: string; role: RoleCode; password: string }[] = [];
  for (const u of DEMO_USERS) {
    const password = sharedPassword ?? generatePassword();
    const { created, id } = await upsertUser(u, password, teamIds);
    if (created) credentials.push({ email: u.email, role: u.role, password });
    if (u.role === 'ROP' && u.team) {
      await prisma.team.update({ where: { id: teamIds.get(u.team)! }, data: { headId: id } });
    }
    for (const code of u.directions ?? []) {
      const d = await prisma.direction.findUnique({ where: { code } });
      if (d)
        await prisma.userDirection.upsert({
          where: { userId_directionId: { userId: id, directionId: d.id } },
          update: {},
          create: { userId: id, directionId: d.id },
        });
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

// ─────────────────────── Справочники CRM (ТЗ §7, §9, §39, §61) ───────────────────────

// ─────────────────────── Демо-данные CRM (ТЗ §68) ───────────────────────

const DEMO_CLIENTS = [
  'Hamza Textile',
  'Samarkand Foods',
  'Tashkent City Mall',
  'Navruz Pharm',
  'Bukhara Ceramics',
  'Oasis Auto',
  'Green Line Logistics',
  'Silk Road Hotel',
  'Uzbek Fitness Club',
  'Nur Education',
];

const DEMO_LEADS: {
  contact: string;
  company: string;
  phone: string;
  service: string;
  source: string;
  budget: number;
  currency: 'UZS' | 'USD';
  stage: string;
}[] = [
  {
    contact: 'Бехзод',
    company: 'Chorsu Market',
    phone: '+998901112201',
    service: 'SMM',
    source: 'INSTAGRAM',
    budget: 6_000_000,
    currency: 'UZS',
    stage: 'NEW',
  },
  {
    contact: 'Нигора',
    company: 'Lola Beauty',
    phone: '+998901112202',
    service: 'TARGET',
    source: 'TELEGRAM',
    budget: 4_500_000,
    currency: 'UZS',
    stage: 'NEW',
  },
  {
    contact: 'Ильхом',
    company: 'Ilm Akademiya',
    phone: '+998901112203',
    service: 'WEBSITE',
    source: 'WEBSITE',
    budget: 2_000,
    currency: 'USD',
    stage: 'NEW',
  },
  {
    contact: 'Фарход',
    company: 'Farhod Mebel',
    phone: '+998901112204',
    service: 'BRANDING',
    source: 'REFERRAL',
    budget: 15_000_000,
    currency: 'UZS',
    stage: 'CONTACTED',
  },
  {
    contact: 'Дильшод',
    company: 'Dilshod Stroy',
    phone: '+998901112205',
    service: 'CRM',
    source: 'COLD_OUTREACH',
    budget: 4_000,
    currency: 'USD',
    stage: 'CONTACTED',
  },
  {
    contact: 'Мадина',
    company: 'Madina Kids',
    phone: '+998901112206',
    service: 'PHOTO',
    source: 'INSTAGRAM',
    budget: 3_000_000,
    currency: 'UZS',
    stage: 'CONTACTED',
  },
  {
    contact: 'Улугбек',
    company: 'Ulug Tech',
    phone: '+998901112207',
    service: 'ERP',
    source: 'REFERRAL',
    budget: 12_000,
    currency: 'USD',
    stage: 'QUALIFICATION',
  },
  {
    contact: 'Гульчехра',
    company: 'Gul Flowers',
    phone: '+998901112208',
    service: 'SMM',
    source: 'WHATSAPP',
    budget: 5_000_000,
    currency: 'UZS',
    stage: 'QUALIFICATION',
  },
  {
    contact: 'Санжар',
    company: 'Sanjar Motors',
    phone: '+998901112209',
    service: 'VIDEO',
    source: 'ADVERTISEMENT',
    budget: 9_000_000,
    currency: 'UZS',
    stage: 'MEETING_SCHEDULED',
  },
  {
    contact: 'Зарина',
    company: 'Zarina Fashion',
    phone: '+998901112210',
    service: 'MARKETING',
    source: 'INSTAGRAM',
    budget: 20_000_000,
    currency: 'UZS',
    stage: 'MEETING_SCHEDULED',
  },
  {
    contact: 'Акмаль',
    company: 'Akmal Group',
    phone: '+998901112211',
    service: 'WEBSITE',
    source: 'PHONE',
    budget: 3_500,
    currency: 'USD',
    stage: 'MEETING_DONE',
  },
  {
    contact: 'Шахноза',
    company: 'Shahnoza Dental',
    phone: '+998901112212',
    service: 'TARGET',
    source: 'TELEGRAM',
    budget: 6_500_000,
    currency: 'UZS',
    stage: 'MEETING_DONE',
  },
];

async function seedDemoCrm(rate: Prisma.Decimal) {
  if ((await prisma.lead.count()) > 0) {
    console.log('• Демо-данные CRM уже есть');
    return;
  }
  const stages = new Map((await prisma.dealStage.findMany()).map((s) => [s.code, s]));
  const services = new Map((await prisma.service.findMany()).map((s) => [s.code, s]));
  const sources = new Map((await prisma.leadSource.findMany()).map((s) => [s.code, s]));
  const managers = await prisma.user.findMany({
    where: { role: { code: 'MANAGER' } },
    orderBy: { email: 'asc' },
  });
  const leadStageCodes = STAGES.filter((s) => s.entity === 'LEAD').map((s) => s.code);

  // 12 лидов в работе
  for (const [i, l] of DEMO_LEADS.entries()) {
    const owner = managers[i % managers.length]!;
    const budgetUzs = toUzs(l.budget, l.currency, rate.toString());
    const { score, level } = computeLeadScore({
      budgetUzs: budgetUzs.toNumber(),
      hasService: true,
      priority: i % 3 === 0 ? 'HIGH' : 'MEDIUM',
      interest: (i % 5) + 1,
      stageIndex: leadStageCodes.indexOf(l.stage),
      stageCount: leadStageCodes.length,
    });
    await prisma.lead.create({
      data: {
        title: `${l.company} — ${services.get(l.service)!.nameRu}`,
        contactName: l.contact,
        companyName: l.company,
        phone: l.phone,
        sourceId: sources.get(l.source)!.id,
        serviceId: services.get(l.service)!.id,
        ownerId: owner.id,
        teamId: owner.teamId,
        budget: l.budget,
        currency: l.currency,
        budgetUzs: budgetUzs.toString(),
        priority: i % 3 === 0 ? 'HIGH' : 'MEDIUM',
        interest: (i % 5) + 1,
        stageId: stages.get(l.stage)!.id,
        score,
        scoreLevel: level,
        createdById: owner.id,
        activities: {
          create: { type: 'lead.created', actorId: owner.id, payload: { seed: true } },
        },
      },
    });
  }

  // 10 клиентов, у 10 из них — сделки (8 лидов уже сконвертированы)
  const dealStageCodes = [
    'NEED_DEFINED',
    'NEED_DEFINED',
    'PROPOSAL_SENT',
    'PROPOSAL_SENT',
    'NEGOTIATION',
    'NEGOTIATION',
    'CONTRACT',
    'AWAITING_PAYMENT',
    'NEED_DEFINED',
    'PROPOSAL_SENT',
  ];
  const amounts: [number, 'UZS' | 'USD'][] = [
    [9_000_000, 'UZS'],
    [25_000_000, 'UZS'],
    [15_000_000, 'UZS'],
    [3_000, 'USD'],
    [7_500_000, 'UZS'],
    [12_000_000, 'UZS'],
    [5_000, 'USD'],
    [18_000_000, 'UZS'],
    [4_000_000, 'UZS'],
    [30_000_000, 'UZS'],
  ];
  const serviceCodes = [
    'SMM',
    'WEBSITE',
    'BRANDING',
    'CRM',
    'TARGET',
    'VIDEO',
    'ERP',
    'MARKETING',
    'PHOTO',
    'WEBSITE',
  ];
  for (const [i, name] of DEMO_CLIENTS.entries()) {
    const owner = managers[i % managers.length]!;
    const client = await prisma.client.create({
      data: {
        name,
        type: 'COMPANY',
        phone: `+99890222330${i}`,
        city: 'Ташкент',
        country: 'Узбекистан',
        ownerId: owner.id,
        teamId: owner.teamId,
        sourceId: sources.get(SOURCES[i % SOURCES.length]![0])!.id,
        contacts: {
          create: { fullName: `Контакт ${name}`, phone: `+99890333440${i}`, isPrimary: true },
        },
      },
    });
    const [amount, currency] = amounts[i]!;
    const service = services.get(serviceCodes[i]!)!;
    const stage = stages.get(dealStageCodes[i]!)!;
    const lead = await prisma.lead.create({
      data: {
        title: `${name} — ${service.nameRu}`,
        companyName: name,
        contactName: `Контакт ${name}`,
        phone: `+99890333440${i}`,
        sourceId: client.sourceId!,
        serviceId: service.id,
        ownerId: owner.id,
        teamId: owner.teamId,
        stageId: stages.get('MEETING_DONE')!.id,
        status: 'CONVERTED',
        convertedAt: new Date(),
        clientId: client.id,
        createdById: owner.id,
      },
    });
    const deal = await prisma.deal.create({
      data: {
        title: `${service.nameRu} для ${name}`,
        clientId: client.id,
        ownerId: owner.id,
        teamId: owner.teamId,
        serviceId: service.id,
        amount,
        currency,
        exchangeRate: currency === 'USD' ? rate : 1,
        amountUzs: toUzs(amount, currency, rate.toString()).toString(),
        stageId: stage.id,
        createdById: owner.id,
        activities: {
          create: { type: 'deal.created', actorId: owner.id, payload: { seed: true } },
        },
      },
    });
    await prisma.lead.update({ where: { id: lead.id }, data: { dealId: deal.id } });
  }
  console.log(
    `✓ Демо CRM: клиентов ${DEMO_CLIENTS.length}, лидов ${DEMO_LEADS.length + DEMO_CLIENTS.length}, сделок ${DEMO_CLIENTS.length}`,
  );
}

async function seedDemoRate(): Promise<Prisma.Decimal> {
  const existing = await prisma.exchangeRate.findFirst({
    where: { currency: 'USD' },
    orderBy: { date: 'desc' },
  });
  if (existing) return existing.rateToUzs;
  // Демо-курс. В рабочей системе курс задаёт CEO в «Настройки → Справочники».
  const rate = await prisma.exchangeRate.create({
    data: {
      currency: 'USD',
      rateToUzs: 12650,
      date: new Date(new Date().toISOString().slice(0, 10)),
    },
  });
  console.log('✓ Демо-курс USD: 12 650 UZS');
  return rate.rateToUzs;
}

async function main() {
  await seedRolesAndPermissions();
  const refs = await seedReferences(prisma);
  console.log(
    `✓ Справочники: ${refs.services} услуг, ${refs.sources} источников, ${refs.lossReasons} причин потерь, ${refs.stages} этапов`,
  );
  await seedInitialCeo();
  const demo = env('SEED_DEMO') ?? (process.env.NODE_ENV === 'production' ? 'false' : 'true');
  if (demo === 'true') {
    await seedDemo();
    await seedDemoCrm(await seedDemoRate());
  }
}

main()
  .catch((e) => {
    console.error(e);
    process.exitCode = 1;
  })
  .finally(() => prisma.$disconnect());

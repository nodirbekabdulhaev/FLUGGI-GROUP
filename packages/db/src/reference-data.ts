/**
 * Обязательные справочники CRM (ТЗ §7, §9, §39, §61). Используются seed'ом и тестами.
 * Повторный запуск не перезаписывает изменения, сделанные в интерфейсе.
 */
import type { PrismaClient } from '@prisma/client';

export const SERVICES: [string, string][] = [
  ['SMM', 'SMM'],
  ['TARGET', 'Таргетированная реклама'],
  ['BRANDING', 'Брендинг'],
  ['WEBSITE', 'Сайт'],
  ['CRM', 'CRM-система'],
  ['ERP', 'ERP-система'],
  ['DESIGN', 'Дизайн'],
  ['PHOTO', 'Фотосъёмка'],
  ['VIDEO', 'Видеопродакшн'],
  ['MARKETING', 'Маркетинг'],
];

export const SOURCES: [string, string][] = [
  ['INSTAGRAM', 'Instagram'],
  ['TELEGRAM', 'Telegram'],
  ['WEBSITE', 'Сайт'],
  ['WHATSAPP', 'WhatsApp'],
  ['REFERRAL', 'Рекомендация'],
  ['COLD_OUTREACH', 'Холодный контакт'],
  ['ADVERTISEMENT', 'Реклама'],
  ['PHONE', 'Звонок'],
  ['OTHER', 'Другой'],
];

export const LOSS_REASONS: [string, string][] = [
  ['TOO_EXPENSIVE', 'Слишком дорого'],
  ['NO_BUDGET', 'Нет бюджета'],
  ['COMPETITOR', 'Выбрал конкурента'],
  ['POSTPONED', 'Отложил проект'],
  ['NO_RESPONSE', 'Не отвечает'],
  ['TERMS', 'Не подошли условия'],
  ['TIMING', 'Сроки'],
  ['NO_NEED', 'Нет потребности'],
  ['OTHER', 'Другая причина'],
];

export const STAGES: {
  code: string;
  entity: 'LEAD' | 'DEAL';
  name: string;
  probability: number;
  color: string;
}[] = [
  { code: 'NEW', entity: 'LEAD', name: 'Новый лид', probability: 5, color: '#71717a' },
  { code: 'CONTACTED', entity: 'LEAD', name: 'Связались', probability: 10, color: '#0ea5e9' },
  {
    code: 'QUALIFICATION',
    entity: 'LEAD',
    name: 'Квалификация',
    probability: 15,
    color: '#6366f1',
  },
  {
    code: 'MEETING_SCHEDULED',
    entity: 'LEAD',
    name: 'Назначена встреча',
    probability: 20,
    color: '#8b5cf6',
  },
  {
    code: 'MEETING_DONE',
    entity: 'LEAD',
    name: 'Встреча проведена',
    probability: 25,
    color: '#a855f7',
  },
  {
    code: 'NEED_DEFINED',
    entity: 'DEAL',
    name: 'Потребность определена',
    probability: 30,
    color: '#d946ef',
  },
  {
    code: 'PROPOSAL_SENT',
    entity: 'DEAL',
    name: 'КП отправлено',
    probability: 40,
    color: '#ec4899',
  },
  { code: 'NEGOTIATION', entity: 'DEAL', name: 'Переговоры', probability: 55, color: '#f97316' },
  { code: 'CONTRACT', entity: 'DEAL', name: 'Договор', probability: 75, color: '#eab308' },
  {
    code: 'AWAITING_PAYMENT',
    entity: 'DEAL',
    name: 'Ожидаем оплату',
    probability: 90,
    color: '#84cc16',
  },
  { code: 'PAID', entity: 'DEAL', name: 'Оплачено', probability: 100, color: '#16a34a' },
];

type Specialty =
  | 'SMM'
  | 'DESIGNER'
  | 'VIDEOGRAPHER'
  | 'EDITOR'
  | 'TARGETOLOGIST'
  | 'DEVELOPER'
  | 'PHOTOGRAPHER'
  | 'COPYWRITER'
  | 'MOBILOGRAPHER'
  | 'BRANDFACE';

/** [название задачи, роль исполнителя, начало (дней от старта проекта), длительность (дней)] */
export const PROJECT_TEMPLATES: {
  service: string;
  name: string;
  tasks: [string, Specialty | null, number, number][];
}[] = [
  {
    service: 'SMM',
    name: 'SMM-проект',
    tasks: [
      ['Контент-план', 'SMM', 0, 3],
      ['Съёмка', 'VIDEOGRAPHER', 3, 3],
      ['Монтаж', 'EDITOR', 6, 3],
      ['Дизайн', 'DESIGNER', 3, 4],
      ['Копирайтинг', 'COPYWRITER', 3, 4],
      ['Публикация', 'SMM', 8, 2],
      ['Таргет', 'TARGETOLOGIST', 8, 5],
      ['Отчёт', 'SMM', 28, 2],
    ],
  },
  {
    service: 'BRANDING',
    name: 'Брендинг',
    tasks: [
      ['Бриф и исследование', null, 0, 3],
      ['Концепции логотипа', 'DESIGNER', 3, 5],
      ['Фирменный стиль', 'DESIGNER', 8, 7],
      ['Брендбук', 'DESIGNER', 15, 5],
      ['Передача материалов клиенту', null, 20, 1],
    ],
  },
  {
    service: 'WEBSITE',
    name: 'Сайт',
    tasks: [
      ['Техническое задание', null, 0, 3],
      ['Прототип', 'DESIGNER', 3, 4],
      ['Дизайн страниц', 'DESIGNER', 7, 7],
      ['Вёрстка и разработка', 'DEVELOPER', 14, 10],
      ['Тексты', 'COPYWRITER', 7, 5],
      ['Тестирование и запуск', 'DEVELOPER', 24, 3],
    ],
  },
  {
    service: 'TARGET',
    name: 'Таргетированная реклама',
    tasks: [
      ['Анализ аудитории', 'TARGETOLOGIST', 0, 2],
      ['Креативы', 'DESIGNER', 2, 3],
      ['Запуск кампаний', 'TARGETOLOGIST', 5, 1],
      ['Оптимизация', 'TARGETOLOGIST', 6, 20],
      ['Отчёт', 'TARGETOLOGIST', 28, 2],
    ],
  },
];

export async function seedReferences(prisma: PrismaClient) {
  for (const [i, [code, nameRu]] of SERVICES.entries()) {
    await prisma.service.upsert({ where: { code }, update: {}, create: { code, nameRu, sort: i } });
  }
  for (const [i, [code, nameRu]] of SOURCES.entries()) {
    await prisma.leadSource.upsert({
      where: { code },
      update: {},
      create: { code, nameRu, sort: i },
    });
  }
  for (const [i, [code, nameRu]] of LOSS_REASONS.entries()) {
    await prisma.lossReason.upsert({
      where: { code },
      update: {},
      create: { code, nameRu, sort: i, requiresComment: code === 'OTHER' },
    });
  }
  for (const [i, st] of STAGES.entries()) {
    await prisma.dealStage.upsert({
      where: { code: st.code },
      update: {},
      create: {
        code: st.code,
        entity: st.entity,
        nameRu: st.name,
        sort: i,
        probability: st.probability,
        color: st.color,
      },
    });
  }
  // Правила комиссий по умолчанию (ТЗ §33–34). Создаются один раз, дальше меняются в настройках.
  if ((await prisma.commissionRule.count()) === 0) {
    await prisma.commissionRule.createMany({
      data: [
        {
          name: 'Менеджер — 10% от оплаты',
          appliesTo: 'MANAGER',
          calcType: 'PERCENT_OF_PAYMENT',
          value: 10,
          priority: 0,
        },
        {
          name: 'РОП — 10% от оплаты',
          appliesTo: 'ROP',
          calcType: 'PERCENT_OF_PAYMENT',
          value: 10,
          priority: 0,
        },
        {
          name: 'РОП — 15%, если средний чек > 3000 USD или заказов > 15 за месяц',
          appliesTo: 'ROP',
          calcType: 'PERCENT_OF_PAYMENT',
          value: 15,
          priority: 10,
          conditions: {
            any: [
              { metric: 'avg_check_usd', op: '>', value: 3000 },
              { metric: 'orders_count', op: '>', value: 15 },
            ],
          },
        },
      ],
    });
  }
  // Шаблоны проектов (ТЗ §62). Создаются один раз, дальше меняются в настройках.
  if ((await prisma.projectTemplate.count()) === 0) {
    for (const t of PROJECT_TEMPLATES) {
      const service = await prisma.service.findUnique({ where: { code: t.service } });
      await prisma.projectTemplate.create({
        data: {
          name: t.name,
          serviceId: service?.id,
          tasks: {
            create: t.tasks.map(([title, role, startOffsetDays, durationDays], sort) => ({
              title,
              role,
              startOffsetDays,
              durationDays,
              sort,
            })),
          },
        },
      });
    }
  }
  // Рабочие графики по умолчанию (ТЗ §36). Меняются в настройках.
  if ((await prisma.workSchedule.count()) === 0) {
    await prisma.workSchedule.createMany({
      data: [
        {
          name: 'Менеджеры',
          roleCode: 'MANAGER',
          startTime: '09:00',
          endTime: '18:00',
          workDays: [1, 2, 3, 4, 5],
          graceMinutes: 10,
        },
        {
          name: 'РОП',
          roleCode: 'ROP',
          startTime: '09:30',
          endTime: '18:00',
          workDays: [1, 2, 3, 4, 5],
          graceMinutes: 10,
        },
        {
          name: 'Исполнители',
          roleCode: 'EXECUTOR',
          startTime: '09:00',
          endTime: '18:00',
          workDays: [1, 2, 3, 4, 5],
          graceMinutes: 10,
        },
        {
          name: 'Обучение',
          roleCode: null,
          startTime: '08:00',
          endTime: '12:00',
          workDays: [1, 2, 3, 4, 5],
          graceMinutes: 5,
        },
      ],
    });
  }
  // Категории доходов и расходов (меняются в настройках; код постоянный)
  for (const [i, [code, kind, name, accountHint, isOverhead]] of FINANCE_CATEGORIES.entries()) {
    await prisma.financeCategory.upsert({
      where: { code },
      update: {},
      create: { code, kind, name, accountHint, isOverhead, sort: i },
    });
  }
  // Единицы работ исполнителей и базовые ставки (CEO меняет в «Настройки → Тарифы»)
  for (const [i, w] of WORK_ITEMS.entries()) {
    await prisma.workItem.upsert({
      where: { code: w.code },
      update: {},
      create: { ...w, sort: i },
    });
  }
  // Тарифы — пример из ТЗ, создаются только если тарифов ещё нет
  if ((await prisma.tariff.count()) === 0) {
    for (const t of TARIFFS) {
      const service = await prisma.service.findUnique({ where: { code: t.service } });
      if (!service) continue;
      const items = [];
      for (const [sort, it] of t.items.entries()) {
        const workItem = it.workItem
          ? await prisma.workItem.findUnique({ where: { code: it.workItem } })
          : null;
        items.push({
          kind: it.kind,
          workItemId: workItem?.id ?? null,
          quantity: it.quantity ?? 1,
          specialty: it.specialty ?? null,
          amount: it.amount ?? null,
          currency: it.currency ?? 'UZS',
          label: it.label ?? null,
          sort,
        });
      }
      await prisma.tariff.create({
        data: {
          serviceId: service.id,
          name: t.name,
          description: t.description,
          price: t.price,
          currency: t.currency,
          sort: t.sort,
          items: { create: items },
        },
      });
    }
  }
  return {
    services: SERVICES.length,
    sources: SOURCES.length,
    lossReasons: LOSS_REASONS.length,
    stages: STAGES.length,
  };
}

export const FINANCE_CATEGORIES: [string, 'EXPENSE' | 'INCOME', string, string, boolean][] = [
  ['EXECUTOR', 'EXPENSE', 'Исполнитель', '9130', false],
  ['ADS', 'EXPENSE', 'Реклама', '9410', false],
  ['PRODUCTION', 'EXPENSE', 'Производство', '9130', false],
  ['PHOTO', 'EXPENSE', 'Фото', '9130', false],
  ['VIDEO', 'EXPENSE', 'Видео', '9130', false],
  ['DESIGN', 'EXPENSE', 'Дизайн', '9130', false],
  ['DEVELOPMENT', 'EXPENSE', 'Разработка', '9130', false],
  ['TRANSPORT', 'EXPENSE', 'Транспорт', '9420', false],
  ['MATERIALS', 'EXPENSE', 'Материалы', '9130', false],
  ['SERVICES', 'EXPENSE', 'Сервисы', '9420', false],
  ['OTHER', 'EXPENSE', 'Прочее', '9420', false],
  ['RENT', 'EXPENSE', 'Аренда', '9420', true],
  ['OFFICE', 'EXPENSE', 'Офис (связь, интернет, хозтовары)', '9420', true],
  ['TAXES', 'EXPENSE', 'Налоги и сборы', '9430', false],
  ['BANK', 'EXPENSE', 'Банковские комиссии', '9430', false],
  ['PARTNER', 'INCOME', 'Партнёрское вознаграждение', '9390', false],
  ['SUPPLIER_REFUND', 'INCOME', 'Возврат от поставщика', '9390', false],
  ['BANK_INTEREST', 'INCOME', 'Проценты банка', '9530', false],
  ['FX_GAIN', 'INCOME', 'Курсовая разница', '9540', false],
  ['OTHER_INCOME', 'INCOME', 'Прочие доходы', '9390', false],
];

/** Единицы работ и базовые ставки (пример CEO; личные ставки — в карточке сотрудника). */
export const WORK_ITEMS: {
  code: string;
  name: string;
  unit: string;
  specialty: Specialty;
  defaultRate: number;
  currency: 'UZS' | 'USD';
}[] = [
  {
    code: 'REEL',
    name: 'Рилс (съёмка)',
    unit: 'шт',
    specialty: 'VIDEOGRAPHER',
    defaultRate: 10,
    currency: 'USD',
  },
  {
    code: 'COVER',
    name: 'Обложка',
    unit: 'шт',
    specialty: 'DESIGNER',
    defaultRate: 70000,
    currency: 'UZS',
  },
  {
    code: 'CAROUSEL',
    name: 'Карусель (5 картинок)',
    unit: 'шт',
    specialty: 'DESIGNER',
    defaultRate: 150000,
    currency: 'UZS',
  },
  {
    code: 'STORY',
    name: 'Сторис',
    unit: 'шт',
    specialty: 'MOBILOGRAPHER',
    defaultRate: 50000,
    currency: 'UZS',
  },
  {
    code: 'BRANDFACE_REEL',
    name: 'Рилс с брендфейсом',
    unit: 'шт',
    specialty: 'BRANDFACE',
    defaultRate: 200000,
    currency: 'UZS',
  },
];

type TariffSeed = {
  service: string;
  name: string;
  description: string;
  price: number;
  currency: 'UZS' | 'USD';
  sort: number;
  items: {
    kind: 'PIECE' | 'FIXED';
    workItem?: string;
    quantity?: number;
    specialty?: Specialty;
    amount?: number;
    currency?: 'UZS' | 'USD';
    label?: string;
  }[];
};

/** Тарифы — пример из ТЗ (CEO меняет цены и состав в настройках). */
export const TARIFFS: TariffSeed[] = [
  {
    service: 'SMM',
    name: 'Эконом',
    description: '8 рилсов, 8 обложек, 4 карусели, 15 сторис в месяц',
    price: 650,
    currency: 'USD',
    sort: 0,
    items: [
      { kind: 'PIECE', workItem: 'REEL', quantity: 8 },
      { kind: 'PIECE', workItem: 'COVER', quantity: 8 },
      { kind: 'PIECE', workItem: 'CAROUSEL', quantity: 4 },
      { kind: 'PIECE', workItem: 'STORY', quantity: 15 },
    ],
  },
  {
    service: 'WEBSITE',
    name: 'Эконом',
    description: 'Лендинг до 5 блоков',
    price: 750,
    currency: 'USD',
    sort: 0,
    items: [
      {
        kind: 'FIXED',
        specialty: 'DEVELOPER',
        amount: 3000000,
        currency: 'UZS',
        label: 'Веб-разработчик',
      },
    ],
  },
  {
    service: 'WEBSITE',
    name: 'Стандарт',
    description: 'Корпоративный сайт до 10 страниц',
    price: 1150,
    currency: 'USD',
    sort: 1,
    items: [
      {
        kind: 'FIXED',
        specialty: 'DEVELOPER',
        amount: 5000000,
        currency: 'UZS',
        label: 'Веб-разработчик',
      },
    ],
  },
];

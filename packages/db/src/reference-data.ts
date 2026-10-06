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
  return {
    services: SERVICES.length,
    sources: SOURCES.length,
    lossReasons: LOSS_REASONS.length,
    stages: STAGES.length,
  };
}

import { Injectable } from '@nestjs/common';
import {
  formatNumber,
  resolvePeriodQuery,
  type ExportEntity,
  type exportQuerySchema,
} from '@fluggi/contracts';
import { companyDate } from '@fluggi/domain';
import ExcelJS from 'exceljs';
import type { z } from 'zod';
import type { AuthContext, RequestMeta } from '../../core/auth/auth-context';
import { AuditService } from '../../core/audit/audit.service';
import { PrismaService } from '../../core/prisma/prisma.service';
import { CrmAccessService } from '../crm/crm-access.service';
import { ExpensesService } from '../finance/expenses.service';
import { ProjectAccessService } from '../projects/project-access.service';
import { ClientInsightsService } from './client-insights.service';

type Query = z.output<typeof exportQuerySchema>;
export type Cell = string | number | Date | null;
export interface Sheet {
  title: string;
  columns: { header: string; width?: number; money?: boolean; date?: boolean }[];
  rows: Cell[][];
}

/** Максимум строк в одном файле — защита от случайной выгрузки всей базы в браузер. */
export const EXPORT_LIMIT = 50_000;

export const L = {
  leadStatus: {
    OPEN: 'В работе',
    CONVERTED: 'Сделка',
    LOST: 'Потеряно',
    REJECTED: 'Отказ',
    PAUSED: 'Пауза',
    NO_RESPONSE: 'Не отвечает',
  },
  dealStatus: {
    OPEN: 'В работе',
    WON: 'Оплачено',
    LOST: 'Потеряно',
    REJECTED: 'Отказ',
    PAUSED: 'Пауза',
    NO_RESPONSE: 'Не отвечает',
  },
  clientType: { COMPANY: 'Компания', PERSON: 'Частное лицо' },
  health: { HEALTHY: 'Здоров', ATTENTION: 'Внимание', RISK: 'Риск', LOST: 'Потерян' },
  paymentType: {
    PREPAYMENT: 'Предоплата',
    PARTIAL: 'Частичная',
    FULL: 'Полная',
    FINAL: 'Финальная',
    REFUND: 'Возврат',
  },
  paymentMethod: {
    CASH: 'Наличные',
    BANK: 'Банк',
    CARD: 'Карта',
    TRANSFER: 'Перевод',
    OTHER: 'Другое',
  },
  paymentStatus: { PENDING: 'Ожидает подтверждения', PAID: 'Оплачено', CANCELLED: 'Отменено' },
  projectStatus: {
    NEW: 'Новый',
    PLANNING: 'Планирование',
    IN_PROGRESS: 'В работе',
    REVIEW: 'На проверке',
    WAITING_CLIENT: 'Ждём клиента',
    PAUSED: 'Пауза',
    COMPLETED: 'Завершён',
    CANCELLED: 'Отменён',
  },
  taskStatus: {
    TODO: 'К выполнению',
    IN_PROGRESS: 'В работе',
    REVIEW: 'На проверке',
    DONE: 'Готово',
    BLOCKED: 'Заблокирована',
    CANCELLED: 'Отменена',
  },
  priority: { LOW: 'Низкий', MEDIUM: 'Средний', HIGH: 'Высокий', URGENT: 'Срочный' },
  category: {
    EXECUTOR: 'Исполнитель',
    ADS: 'Реклама',
    PRODUCTION: 'Производство',
    PHOTO: 'Фото',
    VIDEO: 'Видео',
    DESIGN: 'Дизайн',
    DEVELOPMENT: 'Разработка',
    TRANSPORT: 'Транспорт',
    MATERIALS: 'Материалы',
    SERVICES: 'Сервисы',
    OTHER: 'Прочее',
  },
  expenseScope: { PROJECT: 'Проект', COMPANY: 'Компания' },
} as const;
export const lbl = <T extends Record<string, string>>(map: T, v: string | null | undefined) =>
  v ? ((map as Record<string, string>)[v] ?? v) : null;
export const num = (d: { toString(): string } | null | undefined) => (d == null ? null : Number(d));
/** Календарная дата (без времени) хранится как полночь UTC. */
export const day = (d: Date | null) => (d ? new Date(d.toISOString().slice(0, 10)) : null);
/** Момент → дата и время по Ташкенту (Excel показывает «как есть»). */
export const local = (d: Date | null) => (d ? new Date(d.getTime() + 5 * 3_600_000) : null);

const TITLES: Record<ExportEntity, string> = {
  leads: 'Лиды',
  deals: 'Сделки',
  clients: 'Клиенты',
  payments: 'Оплаты',
  expenses: 'Расходы',
  projects: 'Проекты',
  tasks: 'Задачи',
};

/**
 * Экспорт в Excel/CSV (ТЗ §44). Данные — строго в пределах прав пользователя на сущность,
 * каждая выгрузка записывается в журнал аудита.
 */
@Injectable()
export class ExportService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly crm: CrmAccessService,
    private readonly projects: ProjectAccessService,
    private readonly expenses: ExpensesService,
    private readonly insights: ClientInsightsService,
    private readonly audit: AuditService,
  ) {}

  private range(q: Query) {
    if (q.period === 'all') return undefined;
    const r = resolvePeriodQuery({ period: q.period, from: q.from, to: q.to });
    return { gte: r.from, lt: r.to };
  }

  async sheet(auth: AuthContext, entity: ExportEntity, q: Query): Promise<Sheet> {
    const createdAt = this.range(q);
    const take = EXPORT_LIMIT;
    switch (entity) {
      case 'leads': {
        const rows = await this.prisma.lead.findMany({
          where: { AND: [this.crm.leadWhere(auth), createdAt ? { createdAt } : {}] },
          include: { source: true, service: true, stage: true, owner: true, team: true },
          orderBy: { number: 'asc' },
          take,
        });
        return {
          title: TITLES.leads,
          columns: [
            { header: 'Номер' },
            { header: 'Название', width: 30 },
            { header: 'Контакт', width: 20 },
            { header: 'Компания', width: 22 },
            { header: 'Телефон', width: 16 },
            { header: 'Telegram' },
            { header: 'Email', width: 22 },
            { header: 'Источник', width: 16 },
            { header: 'Услуга', width: 16 },
            { header: 'Бюджет', money: true },
            { header: 'Валюта' },
            { header: 'Этап', width: 16 },
            { header: 'Статус' },
            { header: 'Ответственный', width: 20 },
            { header: 'Отдел' },
            { header: 'Создан', date: true },
          ],
          rows: rows.map((l) => [
            formatNumber('L', l.number),
            l.title,
            l.contactName,
            l.companyName,
            l.phone,
            l.telegram,
            l.email,
            l.source.nameRu,
            l.service?.nameRu ?? null,
            num(l.budget),
            l.currency,
            l.stage.nameRu,
            lbl(L.leadStatus, l.status),
            l.owner.fullName,
            l.team?.name ?? null,
            local(l.createdAt),
          ]),
        };
      }
      case 'deals': {
        const rows = await this.prisma.deal.findMany({
          where: { AND: [this.crm.dealWhere(auth), createdAt ? { createdAt } : {}] },
          include: { client: true, service: true, stage: true, owner: true, team: true },
          orderBy: { number: 'asc' },
          take,
        });
        return {
          title: TITLES.deals,
          columns: [
            { header: 'Номер' },
            { header: 'Название', width: 30 },
            { header: 'Клиент', width: 24 },
            { header: 'Услуга', width: 16 },
            { header: 'Сумма', money: true },
            { header: 'Валюта' },
            { header: 'Сумма, UZS', money: true },
            { header: 'Этап', width: 16 },
            { header: 'Статус' },
            { header: 'Повторная' },
            { header: 'Ответственный', width: 20 },
            { header: 'Отдел' },
            { header: 'Создана', date: true },
            { header: 'Оплачена', date: true },
          ],
          rows: rows.map((d) => [
            formatNumber('D', d.number),
            d.title,
            d.client.name,
            d.service?.nameRu ?? null,
            num(d.amount),
            d.currency,
            num(d.amountUzs),
            d.stage.nameRu,
            lbl(L.dealStatus, d.status),
            d.isRepeat ? 'Да' : 'Нет',
            d.owner.fullName,
            d.team?.name ?? null,
            local(d.createdAt),
            local(d.wonAt),
          ]),
        };
      }
      case 'clients': {
        const rows = await this.prisma.client.findMany({
          where: { AND: [this.crm.clientWhere(auth), createdAt ? { createdAt } : {}] },
          include: { owner: true, team: true },
          orderBy: { number: 'asc' },
          take,
        });
        const m = await this.insights.compute(rows.map((c) => c.id));
        return {
          title: TITLES.clients,
          columns: [
            { header: 'Номер' },
            { header: 'Название', width: 28 },
            { header: 'Тип' },
            { header: 'Телефон', width: 16 },
            { header: 'Email', width: 22 },
            { header: 'Telegram' },
            { header: 'Город' },
            { header: 'Ответственный', width: 20 },
            { header: 'Отдел' },
            { header: 'LTV, UZS', money: true },
            { header: 'Оплаченных сделок' },
            { header: 'Последняя оплата', date: true },
            { header: 'Здоровье' },
            { header: 'Оценка' },
            { header: 'Создан', date: true },
          ],
          rows: rows.map((c) => {
            const x = m.get(c.id)!;
            return [
              formatNumber('C', c.number),
              c.name,
              lbl(L.clientType, c.type),
              c.phone,
              c.email,
              c.telegram,
              c.city,
              c.owner.fullName,
              c.team?.name ?? null,
              Number(x.ltv),
              x.paidDeals,
              local(x.lastPaymentAt ? new Date(x.lastPaymentAt) : null),
              lbl(L.health, x.health.level),
              x.health.score,
              local(c.createdAt),
            ];
          }),
        };
      }
      case 'payments': {
        const rows = await this.prisma.payment.findMany({
          where: {
            AND: [
              { deal: this.crm.dealWhere(auth, 'payment.read') },
              createdAt ? { OR: [{ paidAt: createdAt }, { paidAt: null, createdAt }] } : {},
            ],
          },
          include: { deal: true, client: true, confirmedBy: true },
          orderBy: { number: 'asc' },
          take,
        });
        return {
          title: TITLES.payments,
          columns: [
            { header: 'Номер' },
            { header: 'Сделка', width: 28 },
            { header: 'Клиент', width: 24 },
            { header: 'Тип' },
            { header: 'Способ' },
            { header: 'Статус', width: 20 },
            { header: 'Сумма', money: true },
            { header: 'Валюта' },
            { header: 'Курс' },
            { header: 'Сумма, UZS', money: true },
            { header: 'Срок оплаты', date: true },
            { header: 'Оплачено', date: true },
            { header: 'Подтвердил', width: 20 },
          ],
          rows: rows.map((p) => [
            formatNumber('PAY', p.number),
            `${formatNumber('D', p.deal.number)} ${p.deal.title}`,
            p.client.name,
            lbl(L.paymentType, p.type),
            lbl(L.paymentMethod, p.method),
            lbl(L.paymentStatus, p.status),
            num(p.amount),
            p.currency,
            num(p.exchangeRate),
            num(p.amountUzs),
            day(p.dueDate),
            local(p.paidAt),
            p.confirmedBy?.fullName ?? null,
          ]),
        };
      }
      case 'expenses': {
        const dateRange = createdAt
          ? {
              gte: new Date(createdAt.gte.getTime() + 5 * 3_600_000),
              lt: new Date(createdAt.lt.getTime() + 5 * 3_600_000),
            }
          : undefined;
        const rows = await this.prisma.expense.findMany({
          where: { AND: [this.expenses.where(auth), dateRange ? { expenseDate: dateRange } : {}] },
          include: { project: true, payee: true, createdBy: true, categoryRef: true },
          orderBy: { expenseDate: 'asc' },
          take,
        });
        return {
          title: TITLES.expenses,
          columns: [
            { header: 'Номер' },
            { header: 'Дата', date: true },
            { header: 'Тип' },
            { header: 'Категория', width: 16 },
            { header: 'Проект', width: 28 },
            { header: 'Описание', width: 30 },
            { header: 'Получатель', width: 20 },
            { header: 'Сумма', money: true },
            { header: 'Валюта' },
            { header: 'Сумма, UZS', money: true },
            { header: 'Внёс', width: 20 },
          ],
          rows: rows.map((e) => [
            formatNumber('EXP', e.number),
            day(e.expenseDate),
            lbl(L.expenseScope, e.scope),
            e.categoryRef.name,
            e.project ? `${formatNumber('P', e.project.number)} ${e.project.name}` : null,
            e.description,
            e.payee?.fullName ?? null,
            num(e.amount),
            e.currency,
            num(e.amountUzs),
            e.createdBy.fullName,
          ]),
        };
      }
      case 'projects': {
        const rows = await this.prisma.project.findMany({
          where: { AND: [this.projects.projectWhere(auth), createdAt ? { createdAt } : {}] },
          include: { client: true, manager: true, rop: true },
          orderBy: { number: 'asc' },
          take,
        });
        return {
          title: TITLES.projects,
          columns: [
            { header: 'Номер' },
            { header: 'Проект', width: 30 },
            { header: 'Клиент', width: 24 },
            { header: 'Статус', width: 16 },
            { header: 'Менеджер', width: 20 },
            { header: 'РОП', width: 20 },
            { header: 'Старт', date: true },
            { header: 'Дедлайн', date: true },
            { header: 'Стоимость, UZS', money: true },
            { header: 'Создан', date: true },
            { header: 'Завершён', date: true },
          ],
          rows: rows.map((p) => [
            formatNumber('P', p.number),
            p.name,
            p.client.name,
            lbl(L.projectStatus, p.status),
            p.manager.fullName,
            p.rop.fullName,
            day(p.startDate),
            day(p.deadline),
            num(p.priceUzs),
            local(p.createdAt),
            local(p.completedAt),
          ]),
        };
      }
      case 'tasks': {
        const rows = await this.prisma.task.findMany({
          where: { AND: [this.projects.taskWhere(auth), createdAt ? { createdAt } : {}] },
          include: { project: true, assignee: true },
          orderBy: { number: 'asc' },
          take,
        });
        return {
          title: TITLES.tasks,
          columns: [
            { header: 'Номер' },
            { header: 'Задача', width: 34 },
            { header: 'Проект', width: 28 },
            { header: 'Исполнитель', width: 20 },
            { header: 'Статус', width: 14 },
            { header: 'Приоритет' },
            { header: 'Дедлайн', date: true },
            { header: 'Переделок' },
            { header: 'Создана', date: true },
            { header: 'Готово', date: true },
          ],
          rows: rows.map((t) => [
            formatNumber('T', t.number),
            t.title,
            `${formatNumber('P', t.project.number)} ${t.project.name}`,
            t.assignee.fullName,
            lbl(L.taskStatus, t.status),
            lbl(L.priority, t.priority),
            local(t.deadline),
            t.reworkCount,
            local(t.createdAt),
            local(t.completedAt),
          ]),
        };
      }
    }
  }

  async file(
    auth: AuthContext,
    entity: ExportEntity,
    q: Query,
    meta: RequestMeta,
  ): Promise<{ buffer: Buffer; filename: string; contentType: string; rows: number }> {
    const sheet = await this.sheet(auth, entity, q);
    const stamp = companyDate(new Date());
    const base = `fluggi-${entity}-${stamp}`;
    const out =
      q.format === 'csv'
        ? {
            buffer: toCsv(sheet),
            filename: `${base}.csv`,
            contentType: 'text/csv; charset=utf-8',
          }
        : {
            buffer: await toXlsx(sheet),
            filename: `${base}.xlsx`,
            contentType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
          };
    await this.prisma.$transaction((tx) =>
      this.audit.log(tx, {
        actorId: auth.userId,
        action: 'export.run',
        entityType: entity,
        entityId: null,
        changes: {
          export: {
            old: null,
            new: { format: q.format, period: q.period, rows: sheet.rows.length },
          },
        },
        meta,
      }),
    );
    return { ...out, rows: sheet.rows.length };
  }
}

const fmtCell = (v: Cell, col: Sheet['columns'][number]) => {
  if (v === null || v === undefined) return '';
  if (v instanceof Date) {
    const iso = v.toISOString();
    return col.date && iso.endsWith('T00:00:00.000Z')
      ? iso.slice(0, 10)
      : iso.slice(0, 16).replace('T', ' ');
  }
  return String(v);
};

/** CSV для Excel: UTF-8 с BOM, разделитель «;» (так Excel в русской локали открывает без мастера). */
export function toCsv(sheet: Sheet): Buffer {
  const esc = (s: string) => (/[";\n\r]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s);
  // Защита от формул в ячейках (CSV injection)
  const safe = (s: string) => (/^[=+\-@\t\r]/.test(s) && !/^-?\d/.test(s) ? `'${s}` : s);
  const lines = [
    sheet.columns.map((c) => esc(c.header)).join(';'),
    ...sheet.rows.map((r) => r.map((v, i) => esc(safe(fmtCell(v, sheet.columns[i]!)))).join(';')),
  ];
  return Buffer.from(`﻿${lines.join('\r\n')}\r\n`, 'utf8');
}

/** Лист Excel: шапка жирная и закреплена, автофильтр, форматы денег и дат. */
export function addSheet(wb: ExcelJS.Workbook, sheet: Sheet): ExcelJS.Worksheet {
  const ws = wb.addWorksheet(sheet.title.slice(0, 31), { views: [{ state: 'frozen', ySplit: 1 }] });
  ws.columns = sheet.columns.map((c) => ({
    header: c.header,
    width: c.width ?? Math.max(12, c.header.length + 2),
    style: c.money ? { numFmt: '#,##0.00' } : c.date ? { numFmt: 'dd.mm.yyyy hh:mm' } : {},
  }));
  ws.getRow(1).font = { bold: true };
  if (sheet.columns.length > 1)
    ws.autoFilter = { from: { row: 1, column: 1 }, to: { row: 1, column: sheet.columns.length } };
  for (const r of sheet.rows) {
    ws.addRow(
      r.map((v) => (typeof v === 'string' && /^[=+\-@]/.test(v) && !/^-?\d/.test(v) ? `'${v}` : v)),
    );
  }
  // Даты без времени — без «00:00»
  sheet.columns.forEach((c, i) => {
    if (!c.date) return;
    const col = ws.getColumn(i + 1);
    const dateOnly = sheet.rows.every((r) => {
      const v = r[i];
      return !(v instanceof Date) || v.toISOString().endsWith('T00:00:00.000Z');
    });
    if (dateOnly) col.numFmt = 'dd.mm.yyyy';
  });
  return ws;
}

export async function toXlsx(sheet: Sheet): Promise<Buffer> {
  const wb = new ExcelJS.Workbook();
  wb.creator = 'Fluggi CRM';
  addSheet(wb, sheet);
  return Buffer.from(await wb.xlsx.writeBuffer());
}

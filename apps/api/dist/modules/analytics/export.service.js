"use strict";
var __decorate = (this && this.__decorate) || function (decorators, target, key, desc) {
    var c = arguments.length, r = c < 3 ? target : desc === null ? desc = Object.getOwnPropertyDescriptor(target, key) : desc, d;
    if (typeof Reflect === "object" && typeof Reflect.decorate === "function") r = Reflect.decorate(decorators, target, key, desc);
    else for (var i = decorators.length - 1; i >= 0; i--) if (d = decorators[i]) r = (c < 3 ? d(r) : c > 3 ? d(target, key, r) : d(target, key)) || r;
    return c > 3 && r && Object.defineProperty(target, key, r), r;
};
var __metadata = (this && this.__metadata) || function (k, v) {
    if (typeof Reflect === "object" && typeof Reflect.metadata === "function") return Reflect.metadata(k, v);
};
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.ExportService = exports.local = exports.day = exports.num = exports.lbl = exports.L = exports.EXPORT_LIMIT = void 0;
exports.toCsv = toCsv;
exports.addSheet = addSheet;
exports.toXlsx = toXlsx;
const common_1 = require("@nestjs/common");
const contracts_1 = require("@fluggi/contracts");
const domain_1 = require("@fluggi/domain");
const exceljs_1 = __importDefault(require("exceljs"));
const audit_service_1 = require("../../core/audit/audit.service");
const prisma_service_1 = require("../../core/prisma/prisma.service");
const crm_access_service_1 = require("../crm/crm-access.service");
const expenses_service_1 = require("../finance/expenses.service");
const project_access_service_1 = require("../projects/project-access.service");
const client_insights_service_1 = require("./client-insights.service");
/** Максимум строк в одном файле — защита от случайной выгрузки всей базы в браузер. */
exports.EXPORT_LIMIT = 50_000;
exports.L = {
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
};
const lbl = (map, v) => v ? (map[v] ?? v) : null;
exports.lbl = lbl;
const num = (d) => (d == null ? null : Number(d));
exports.num = num;
/** Календарная дата (без времени) хранится как полночь UTC. */
const day = (d) => (d ? new Date(d.toISOString().slice(0, 10)) : null);
exports.day = day;
/** Момент → дата и время по Ташкенту (Excel показывает «как есть»). */
const local = (d) => (d ? new Date(d.getTime() + 5 * 3_600_000) : null);
exports.local = local;
const TITLES = {
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
let ExportService = class ExportService {
    prisma;
    crm;
    projects;
    expenses;
    insights;
    audit;
    constructor(prisma, crm, projects, expenses, insights, audit) {
        this.prisma = prisma;
        this.crm = crm;
        this.projects = projects;
        this.expenses = expenses;
        this.insights = insights;
        this.audit = audit;
    }
    range(q) {
        if (q.period === 'all')
            return undefined;
        const r = (0, contracts_1.resolvePeriodQuery)({ period: q.period, from: q.from, to: q.to });
        return { gte: r.from, lt: r.to };
    }
    async sheet(auth, entity, q) {
        const createdAt = this.range(q);
        const take = exports.EXPORT_LIMIT;
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
                        (0, contracts_1.formatNumber)('L', l.number),
                        l.title,
                        l.contactName,
                        l.companyName,
                        l.phone,
                        l.telegram,
                        l.email,
                        l.source.nameRu,
                        l.service?.nameRu ?? null,
                        (0, exports.num)(l.budget),
                        l.currency,
                        l.stage.nameRu,
                        (0, exports.lbl)(exports.L.leadStatus, l.status),
                        l.owner.fullName,
                        l.team?.name ?? null,
                        (0, exports.local)(l.createdAt),
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
                        (0, contracts_1.formatNumber)('D', d.number),
                        d.title,
                        d.client.name,
                        d.service?.nameRu ?? null,
                        (0, exports.num)(d.amount),
                        d.currency,
                        (0, exports.num)(d.amountUzs),
                        d.stage.nameRu,
                        (0, exports.lbl)(exports.L.dealStatus, d.status),
                        d.isRepeat ? 'Да' : 'Нет',
                        d.owner.fullName,
                        d.team?.name ?? null,
                        (0, exports.local)(d.createdAt),
                        (0, exports.local)(d.wonAt),
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
                        const x = m.get(c.id);
                        return [
                            (0, contracts_1.formatNumber)('C', c.number),
                            c.name,
                            (0, exports.lbl)(exports.L.clientType, c.type),
                            c.phone,
                            c.email,
                            c.telegram,
                            c.city,
                            c.owner.fullName,
                            c.team?.name ?? null,
                            Number(x.ltv),
                            x.paidDeals,
                            (0, exports.local)(x.lastPaymentAt ? new Date(x.lastPaymentAt) : null),
                            (0, exports.lbl)(exports.L.health, x.health.level),
                            x.health.score,
                            (0, exports.local)(c.createdAt),
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
                        (0, contracts_1.formatNumber)('PAY', p.number),
                        `${(0, contracts_1.formatNumber)('D', p.deal.number)} ${p.deal.title}`,
                        p.client.name,
                        (0, exports.lbl)(exports.L.paymentType, p.type),
                        (0, exports.lbl)(exports.L.paymentMethod, p.method),
                        (0, exports.lbl)(exports.L.paymentStatus, p.status),
                        (0, exports.num)(p.amount),
                        p.currency,
                        (0, exports.num)(p.exchangeRate),
                        (0, exports.num)(p.amountUzs),
                        (0, exports.day)(p.dueDate),
                        (0, exports.local)(p.paidAt),
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
                        (0, contracts_1.formatNumber)('EXP', e.number),
                        (0, exports.day)(e.expenseDate),
                        (0, exports.lbl)(exports.L.expenseScope, e.scope),
                        e.categoryRef.name,
                        e.project ? `${(0, contracts_1.formatNumber)('P', e.project.number)} ${e.project.name}` : null,
                        e.description,
                        e.payee?.fullName ?? null,
                        (0, exports.num)(e.amount),
                        e.currency,
                        (0, exports.num)(e.amountUzs),
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
                        (0, contracts_1.formatNumber)('P', p.number),
                        p.name,
                        p.client.name,
                        (0, exports.lbl)(exports.L.projectStatus, p.status),
                        p.manager.fullName,
                        p.rop.fullName,
                        (0, exports.day)(p.startDate),
                        (0, exports.day)(p.deadline),
                        (0, exports.num)(p.priceUzs),
                        (0, exports.local)(p.createdAt),
                        (0, exports.local)(p.completedAt),
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
                        (0, contracts_1.formatNumber)('T', t.number),
                        t.title,
                        `${(0, contracts_1.formatNumber)('P', t.project.number)} ${t.project.name}`,
                        t.assignee.fullName,
                        (0, exports.lbl)(exports.L.taskStatus, t.status),
                        (0, exports.lbl)(exports.L.priority, t.priority),
                        (0, exports.local)(t.deadline),
                        t.reworkCount,
                        (0, exports.local)(t.createdAt),
                        (0, exports.local)(t.completedAt),
                    ]),
                };
            }
        }
    }
    async file(auth, entity, q, meta) {
        const sheet = await this.sheet(auth, entity, q);
        const stamp = (0, domain_1.companyDate)(new Date());
        const base = `fluggi-${entity}-${stamp}`;
        const out = q.format === 'csv'
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
        await this.prisma.$transaction((tx) => this.audit.log(tx, {
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
        }));
        return { ...out, rows: sheet.rows.length };
    }
};
exports.ExportService = ExportService;
exports.ExportService = ExportService = __decorate([
    (0, common_1.Injectable)(),
    __metadata("design:paramtypes", [prisma_service_1.PrismaService,
        crm_access_service_1.CrmAccessService,
        project_access_service_1.ProjectAccessService,
        expenses_service_1.ExpensesService,
        client_insights_service_1.ClientInsightsService,
        audit_service_1.AuditService])
], ExportService);
const fmtCell = (v, col) => {
    if (v === null || v === undefined)
        return '';
    if (v instanceof Date) {
        const iso = v.toISOString();
        return col.date && iso.endsWith('T00:00:00.000Z')
            ? iso.slice(0, 10)
            : iso.slice(0, 16).replace('T', ' ');
    }
    return String(v);
};
/** CSV для Excel: UTF-8 с BOM, разделитель «;» (так Excel в русской локали открывает без мастера). */
function toCsv(sheet) {
    const esc = (s) => (/[";\n\r]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s);
    // Защита от формул в ячейках (CSV injection)
    const safe = (s) => (/^[=+\-@\t\r]/.test(s) && !/^-?\d/.test(s) ? `'${s}` : s);
    const lines = [
        sheet.columns.map((c) => esc(c.header)).join(';'),
        ...sheet.rows.map((r) => r.map((v, i) => esc(safe(fmtCell(v, sheet.columns[i])))).join(';')),
    ];
    return Buffer.from(`﻿${lines.join('\r\n')}\r\n`, 'utf8');
}
/** Лист Excel: шапка жирная и закреплена, автофильтр, форматы денег и дат. */
function addSheet(wb, sheet) {
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
        ws.addRow(r.map((v) => (typeof v === 'string' && /^[=+\-@]/.test(v) && !/^-?\d/.test(v) ? `'${v}` : v)));
    }
    // Даты без времени — без «00:00»
    sheet.columns.forEach((c, i) => {
        if (!c.date)
            return;
        const col = ws.getColumn(i + 1);
        const dateOnly = sheet.rows.every((r) => {
            const v = r[i];
            return !(v instanceof Date) || v.toISOString().endsWith('T00:00:00.000Z');
        });
        if (dateOnly)
            col.numFmt = 'dd.mm.yyyy';
    });
    return ws;
}
async function toXlsx(sheet) {
    const wb = new exceljs_1.default.Workbook();
    wb.creator = 'Fluggi CRM';
    addSheet(wb, sheet);
    return Buffer.from(await wb.xlsx.writeBuffer());
}
//# sourceMappingURL=export.service.js.map
import { Injectable } from '@nestjs/common';
import { formatNumber, TAX_REGIME_LABELS } from '@fluggi/contracts';
import { companyDate, companyDayStart } from '@fluggi/domain';
import { Prisma } from '@fluggi/db';
import ExcelJS from 'exceljs';
import type { AuthContext, RequestMeta } from '../../core/auth/auth-context';
import { AuditService } from '../../core/audit/audit.service';
import { PrismaService } from '../../core/prisma/prisma.service';
import { SettingsService } from '../../core/settings/settings.service';
import { addSheet, day, L, lbl, local, num, type Cell, type Sheet } from './export.service';

const ZERO = new Prisma.Decimal(0);
const MONTHS = [
  'Январь',
  'Февраль',
  'Март',
  'Апрель',
  'Май',
  'Июнь',
  'Июль',
  'Август',
  'Сентябрь',
  'Октябрь',
  'Ноябрь',
  'Декабрь',
];

/** Куда бухгалтеру отнести расход (подсказка по плану счетов НСБУ №21). */
export function expenseAccount(scope: string, category: string): string {
  if (scope === 'PROJECT') return 'Себестоимость услуг (9130)';
  if (category === 'ADS') return 'Расходы по реализации (9410)';
  return 'Административные расходы (9420)';
}

/**
 * Годовой пакет для бухгалтера: всё, что CRM знает о деньгах компании за год, одним Excel-файлом.
 * Баланс (форма №1) и отчёт о финансовых результатах (форма №2) составляет бухгалтер:
 * в CRM нет основных средств, кредитов, налогов, остатков кассы и счёта.
 */
@Injectable()
export class AccountantPackageService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly settings: SettingsService,
    private readonly audit: AuditService,
  ) {}

  async build(year: number, now = new Date()) {
    const from = companyDayStart(`${year}-01-01`);
    const to = companyDayStart(`${year + 1}-01-01`);
    const inYear = { gte: from, lt: to };
    // Календарные даты (расходы) хранятся как полночь UTC
    const dateYear = { gte: new Date(`${year}-01-01`), lt: new Date(`${year + 1}-01-01`) };
    const periods = Array.from(
      { length: 12 },
      (_, i) => `${year}-${String(i + 1).padStart(2, '0')}`,
    );

    const [
      company,
      contracts,
      payments,
      allPaid,
      signedToDate,
      expenses,
      payroll,
      commissions,
      projects,
    ] = await Promise.all([
      this.settings.company(),
      this.prisma.contract.findMany({
        where: { status: 'SIGNED', signedAt: inYear },
        include: { client: true, deal: true },
        orderBy: { signedAt: 'asc' },
      }),
      this.prisma.payment.findMany({
        where: { status: 'PAID', paidAt: inYear },
        include: { client: true, deal: true, contract: true },
        orderBy: { paidAt: 'asc' },
      }),
      this.prisma.payment.findMany({
        where: { status: 'PAID', paidAt: { lt: to } },
        select: { clientId: true, dealId: true, type: true, amountUzs: true },
      }),
      this.prisma.contract.findMany({
        where: { status: 'SIGNED', signedAt: { lt: to } },
        select: {
          clientId: true,
          amountUzs: true,
          client: { select: { name: true, number: true } },
        },
      }),
      this.prisma.expense.findMany({
        where: { deletedAt: null, expenseDate: dateYear },
        include: { project: true, payee: true, createdBy: true },
        orderBy: { expenseDate: 'asc' },
      }),
      this.prisma.payrollEntry.findMany({
        where: { period: { in: periods } },
        include: { user: true },
        orderBy: [{ period: 'asc' }, { user: { fullName: 'asc' } }],
      }),
      this.prisma.commission.findMany({
        where: { period: { in: periods }, status: { not: 'CANCELLED' } },
        include: { user: true, deal: true },
        orderBy: [{ period: 'asc' }],
      }),
      this.prisma.project.findMany({
        where: {
          deletedAt: null,
          createdAt: { lt: to },
          OR: [{ completedAt: null }, { completedAt: { gte: from } }],
          status: { not: 'CANCELLED' },
        },
        include: {
          client: true,
          expenses: {
            where: { deletedAt: null, expenseDate: { lt: dateYear.lt } },
            select: { amountUzs: true },
          },
          deal: {
            select: {
              payments: {
                where: { status: 'PAID', paidAt: { lt: to } },
                select: { type: true, amountUzs: true },
              },
            },
          },
        },
        orderBy: { number: 'asc' },
      }),
    ]);

    const sign = (p: { type: string; amountUzs: Prisma.Decimal }) =>
      p.type === 'REFUND' ? p.amountUzs.neg() : p.amountUzs;
    const sum = <T>(rows: T[], f: (r: T) => Prisma.Decimal) =>
      rows.reduce((a, r) => a.add(f(r)), ZERO);
    const month = (d: Date) => Number(companyDate(d).slice(5, 7)) - 1;

    // ── Сводка по месяцам
    const m = periods.map(() => ({
      contracts: ZERO,
      paid: ZERO,
      cost: ZERO,
      sales: ZERO,
      admin: ZERO,
      payroll: ZERO,
    }));
    for (const c of contracts)
      m[month(c.signedAt!)]!.contracts = m[month(c.signedAt!)]!.contracts.add(c.amountUzs);
    for (const p of payments) m[month(p.paidAt!)]!.paid = m[month(p.paidAt!)]!.paid.add(sign(p));
    for (const e of expenses) {
      const i = Number(e.expenseDate.toISOString().slice(5, 7)) - 1;
      const acc = expenseAccount(e.scope, e.category);
      const k = acc.startsWith('Себестоимость')
        ? 'cost'
        : acc.startsWith('Расходы по реализации')
          ? 'sales'
          : 'admin';
      m[i]![k] = m[i]![k].add(e.amountUzs);
    }
    for (const p of payroll) {
      const i = Number(p.period.slice(5, 7)) - 1;
      m[i]!.payroll = m[i]!.payroll.add(p.finalSalary);
    }
    const t = m.reduce(
      (a, x) => ({
        contracts: a.contracts.add(x.contracts),
        paid: a.paid.add(x.paid),
        cost: a.cost.add(x.cost),
        sales: a.sales.add(x.sales),
        admin: a.admin.add(x.admin),
        payroll: a.payroll.add(x.payroll),
      }),
      { contracts: ZERO, paid: ZERO, cost: ZERO, sales: ZERO, admin: ZERO, payroll: ZERO },
    );
    const gross = t.paid.sub(t.cost);
    const periodExp = t.sales.add(t.admin).add(t.payroll);
    const operating = gross.sub(periodExp);

    // ── Расчёты с клиентами на 31.12: подписано по договорам − оплачено
    const byClient = new Map<
      string,
      { name: string; number: number; contracted: Prisma.Decimal; paid: Prisma.Decimal }
    >();
    for (const c of signedToDate) {
      const r = byClient.get(c.clientId) ?? {
        name: c.client.name,
        number: c.client.number,
        contracted: ZERO,
        paid: ZERO,
      };
      r.contracted = r.contracted.add(c.amountUzs);
      byClient.set(c.clientId, r);
    }
    const clientNames = new Map(
      (
        await this.prisma.client.findMany({
          where: { id: { in: [...new Set(allPaid.map((p) => p.clientId))] } },
          select: { id: true, name: true, number: true },
        })
      ).map((c) => [c.id, c]),
    );
    for (const p of allPaid) {
      const c = clientNames.get(p.clientId)!;
      const r = byClient.get(p.clientId) ?? {
        name: c.name,
        number: c.number,
        contracted: ZERO,
        paid: ZERO,
      };
      r.paid = r.paid.add(sign(p));
      byClient.set(p.clientId, r);
    }
    const settlements = [...byClient.values()]
      .map((r) => ({ ...r, balance: r.contracted.sub(r.paid) }))
      .filter((r) => !r.balance.isZero())
      .sort((a, b) => a.name.localeCompare(b.name, 'ru'));
    const receivable = sum(
      settlements.filter((r) => r.balance.gt(0)),
      (r) => r.balance,
    );
    const advances = sum(
      settlements.filter((r) => r.balance.lt(0)),
      (r) => r.balance.neg(),
    );

    // ── Задолженность по зарплате и комиссиям на конец года
    const payrollDebt = sum(
      payroll.filter(
        (p) => p.status === 'APPROVED' || (p.status === 'PAID' && p.paidAt && p.paidAt >= to),
      ),
      (p) => p.finalSalary,
    );
    const commissionsUnpaid = sum(
      commissions.filter((c) => c.status !== 'PAID' || (c.paidAt && c.paidAt >= to)),
      (c) => c.amountUzs,
    );

    const d = (v: Prisma.Decimal) => Number(v.toFixed(2));
    const sheets: Sheet[] = [];

    sheets.push({
      title: 'Пояснения',
      columns: [
        { header: 'Пакет для бухгалтера', width: 60 },
        { header: '', width: 70 },
      ],
      rows: [
        ['Компания', company.name || '— (укажите в «Настройки → Автоматизация → Компания»)'],
        ['ИНН (СТИР)', company.inn || '—'],
        ['Налоговый режим', TAX_REGIME_LABELS[company.taxRegime]],
        ['Руководитель', company.director || '—'],
        ['Бухгалтер', company.accountant || '—'],
        ['Период', `01.01.${year} — 31.12.${year}`],
        ['Сформировано', `${companyDate(now)} (Fluggi CRM)`],
        ['Валюта', 'UZS. Суммы в USD пересчитаны по курсу на дату операции (колонка «Курс»)'],
        [null, null],
        ['Как посчитано', null],
        ['Договоры', 'Подписанные в году договоры (дата подписания)'],
        ['Оплаты', 'Подтверждённые РОП оплаты по дате поступления; возвраты — с минусом'],
        [
          'Расчёты с клиентами',
          'На 31.12: сумма подписанных договоров − оплачено. «+» — долг клиента (дебиторка), «−» — аванс полученный',
        ],
        [
          'Расходы',
          'Внесённые в CRM расходы по дате расхода; колонка «Отнесение» — подсказка по счёту НСБУ №21',
        ],
        [
          'Зарплата',
          'Расчёт CRM: оклад + KPI-бонус + комиссия + бонусы − штраф. НДФЛ и социальный налог не начислены',
        ],
        [
          'Комиссии',
          'Справочно: уже включены в зарплату месяца (колонка «Комиссия» на листе «Зарплата»)',
        ],
        [
          'Проекты',
          'Проекты в работе на конец года и завершённые в году: для актов выполненных работ и незавершённых услуг',
        ],
        [
          'Сводка (форма №2)',
          'Черновик по данным CRM: выручка — по оплатам (кассовый метод). По НСБУ выручку признают по актам — проверьте по листу «Проекты»',
        ],
        [null, null],
        ['Чего нет в CRM (нужно из бухгалтерии и банка)', null],
        ['•', 'Остатки денег в кассе и на счетах, банковские выписки'],
        ['•', 'Основные средства, нематериальные активы и их износ'],
        ['•', 'Кредиты и займы, уставный капитал, дивиденды'],
        ['•', 'Налоги и взносы (начисление и уплата), НДФЛ и социальный налог с зарплаты'],
        [
          '•',
          'Акты выполненных работ и счета-фактуры (Didox / Faktura.uz), расчёты с поставщиками',
        ],
        [null, null],
        ['Итоги года', null],
        ['Подписано договоров, UZS', d(t.contracts)],
        ['Получено оплат (за вычетом возвратов), UZS', d(t.paid)],
        ['Расходы на проекты (себестоимость), UZS', d(t.cost)],
        ['Расходы компании, UZS', d(t.sales.add(t.admin))],
        ['Зарплата начислена (CRM), UZS', d(t.payroll)],
        ['Дебиторская задолженность клиентов на 31.12, UZS', d(receivable)],
        ['Авансы полученные от клиентов на 31.12, UZS', d(advances)],
        ['Зарплата начислена, но не выплачена на 31.12, UZS', d(payrollDebt)],
      ],
    });

    sheets.push({
      title: 'Сводка (форма 2)',
      columns: [
        { header: 'Строка' },
        { header: 'Показатель', width: 52 },
        { header: 'За год, UZS', money: true, width: 18 },
        ...MONTHS.map((h) => ({ header: h, money: true, width: 15 })),
      ],
      rows: (
        [
          ['010', 'Чистая выручка (по оплатам)', (x) => x.paid],
          ['020', 'Себестоимость: расходы на проекты', (x) => x.cost],
          ['030', 'Валовая прибыль', (x) => x.paid.sub(x.cost)],
          ['040', 'Расходы периода, всего', (x) => x.sales.add(x.admin).add(x.payroll)],
          ['050', '  расходы по реализации (реклама компании)', (x) => x.sales],
          ['060', '  административные (прочие расходы компании)', (x) => x.admin],
          ['060', '  зарплата (без НДФЛ и соцналога)', (x) => x.payroll],
          [
            '100',
            'Прибыль от основной деятельности',
            (x) => x.paid.sub(x.cost).sub(x.sales).sub(x.admin).sub(x.payroll),
          ],
          ['—', 'Справочно: подписано договоров', (x) => x.contracts],
        ] as [string, string, (x: (typeof m)[number]) => Prisma.Decimal][]
      ).map(([code, label, f]) => [
        code,
        label,
        d(f(t as (typeof m)[number])),
        ...m.map((x) => d(f(x))),
      ]),
    });

    sheets.push({
      title: 'Договоры',
      columns: [
        { header: 'Номер' },
        { header: 'Дата договора', date: true },
        { header: 'Подписан', date: true },
        { header: 'Клиент', width: 26 },
        { header: 'Сделка', width: 28 },
        { header: 'Сумма', money: true },
        { header: 'Валюта' },
        { header: 'Курс' },
        { header: 'Сумма, UZS', money: true },
      ],
      rows: contracts.map((c) => [
        formatNumber('C', c.number).replace(/^C-/, 'ДГ-'),
        day(c.contractDate),
        local(c.signedAt),
        c.client.name,
        `${formatNumber('D', c.deal.number)} ${c.deal.title}`,
        num(c.amount),
        c.currency,
        num(c.exchangeRate),
        num(c.amountUzs),
      ]),
    });

    sheets.push({
      title: 'Оплаты',
      columns: [
        { header: 'Номер' },
        { header: 'Дата поступления', date: true },
        { header: 'Клиент', width: 26 },
        { header: 'Договор' },
        { header: 'Сделка', width: 28 },
        { header: 'Тип' },
        { header: 'Способ' },
        { header: 'Сумма', money: true },
        { header: 'Валюта' },
        { header: 'Курс' },
        { header: 'Сумма, UZS', money: true },
      ],
      rows: payments.map((p) => [
        formatNumber('PAY', p.number),
        local(p.paidAt),
        p.client.name,
        p.contract ? formatNumber('C', p.contract.number).replace(/^C-/, 'ДГ-') : null,
        `${formatNumber('D', p.deal.number)} ${p.deal.title}`,
        lbl(L.paymentType, p.type),
        lbl(L.paymentMethod, p.method),
        p.type === 'REFUND' ? -Number(p.amount) : num(p.amount),
        p.currency,
        num(p.exchangeRate),
        d(sign(p)),
      ]),
    });

    sheets.push({
      title: 'Расчёты с клиентами 31.12',
      columns: [
        { header: 'Клиент', width: 28 },
        { header: 'Номер' },
        { header: 'Подписано договоров, UZS', money: true, width: 20 },
        { header: 'Оплачено, UZS', money: true, width: 18 },
        { header: 'Долг клиента (дебиторка), UZS', money: true, width: 22 },
        { header: 'Аванс полученный, UZS', money: true, width: 20 },
      ],
      rows: [
        ...settlements.map((r): Cell[] => [
          r.name,
          formatNumber('C', r.number),
          d(r.contracted),
          d(r.paid),
          r.balance.gt(0) ? d(r.balance) : null,
          r.balance.lt(0) ? d(r.balance.neg()) : null,
        ]),
        ['Итого', null, null, null, d(receivable), d(advances)],
      ],
    });

    sheets.push({
      title: 'Расходы',
      columns: [
        { header: 'Номер' },
        { header: 'Дата', date: true },
        { header: 'Тип' },
        { header: 'Категория', width: 16 },
        { header: 'Отнесение (подсказка)', width: 30 },
        { header: 'Проект', width: 28 },
        { header: 'Описание', width: 30 },
        { header: 'Получатель', width: 20 },
        { header: 'Сумма', money: true },
        { header: 'Валюта' },
        { header: 'Сумма, UZS', money: true },
        { header: 'Внёс', width: 18 },
      ],
      rows: expenses.map((e) => [
        formatNumber('EXP', e.number),
        day(e.expenseDate),
        lbl(L.expenseScope, e.scope),
        lbl(L.category, e.category),
        expenseAccount(e.scope, e.category),
        e.project ? `${formatNumber('P', e.project.number)} ${e.project.name}` : null,
        e.description,
        e.payee?.fullName ?? null,
        num(e.amount),
        e.currency,
        num(e.amountUzs),
        e.createdBy.fullName,
      ]),
    });

    sheets.push({
      title: 'Зарплата',
      columns: [
        { header: 'Месяц' },
        { header: 'Сотрудник', width: 24 },
        { header: 'Оклад', money: true },
        { header: 'KPI-бонус', money: true },
        { header: 'Комиссия', money: true },
        { header: 'Бонус', money: true },
        { header: 'Штраф', money: true },
        { header: 'Итого начислено', money: true, width: 16 },
        { header: 'Статус' },
        { header: 'Выплачено', date: true },
      ],
      rows: payroll.map((p) => [
        p.period,
        p.user.fullName,
        num(p.baseSalary),
        num(p.kpiBonus),
        num(p.commission),
        num(p.otherBonus),
        num(p.penalty),
        num(p.finalSalary),
        { DRAFT: 'Черновик', APPROVED: 'Утверждена', PAID: 'Выплачена' }[p.status],
        local(p.paidAt),
      ]),
    });

    sheets.push({
      title: 'Комиссии',
      columns: [
        { header: 'Месяц' },
        { header: 'Сотрудник', width: 24 },
        { header: 'Сделка', width: 30 },
        { header: 'База, UZS', money: true },
        { header: 'Ставка' },
        { header: 'Комиссия, UZS', money: true },
        { header: 'Статус' },
        { header: 'Выплачена', date: true },
      ],
      rows: [
        ...commissions.map((c): Cell[] => [
          c.period,
          c.user.fullName,
          `${formatNumber('D', c.deal.number)} ${c.deal.title}`,
          num(c.baseAmountUzs),
          num(c.rate),
          num(c.amountUzs),
          {
            ACCRUED: 'Начислена',
            APPROVED: 'Утверждена',
            PAID: 'Выплачена',
            CANCELLED: 'Отменена',
          }[c.status],
          local(c.paidAt),
        ]),
        ['Не выплачено на 31.12', null, null, null, null, d(commissionsUnpaid), null, null],
      ],
    });

    sheets.push({
      title: 'Проекты',
      columns: [
        { header: 'Номер' },
        { header: 'Проект', width: 30 },
        { header: 'Клиент', width: 24 },
        { header: 'Статус на сегодня', width: 16 },
        { header: 'Завершён', date: true },
        { header: 'Стоимость, UZS', money: true },
        { header: 'Оплачено на 31.12, UZS', money: true, width: 18 },
        { header: 'Расходы на 31.12, UZS', money: true, width: 18 },
        { header: 'Работы завершены в году', width: 14 },
      ],
      rows: projects.map((p) => [
        formatNumber('P', p.number),
        p.name,
        p.client.name,
        lbl(L.projectStatus, p.status),
        local(p.completedAt),
        num(p.priceUzs),
        d(sum(p.deal.payments, sign)),
        d(sum(p.expenses, (e) => e.amountUzs)),
        p.completedAt && p.completedAt < to ? 'Да' : 'Нет',
      ]),
    });

    const wb = new ExcelJS.Workbook();
    wb.creator = 'Fluggi CRM';
    for (const s of sheets) addSheet(wb, s);
    // Лист «Пояснения» — без автофильтра и с жирными заголовками разделов
    const notes = wb.getWorksheet('Пояснения')!;
    notes.autoFilter = undefined as unknown as ExcelJS.AutoFilter;
    notes.eachRow((row) => {
      if (row.getCell(2).value === null && row.getCell(1).value) row.font = { bold: true };
      if (typeof row.getCell(2).value === 'number') row.getCell(2).numFmt = '#,##0.00';
    });
    return {
      buffer: Buffer.from(await wb.xlsx.writeBuffer()),
      filename: `fluggi-buhgalter-${year}.xlsx`,
      counts: {
        contracts: contracts.length,
        payments: payments.length,
        expenses: expenses.length,
        payroll: payroll.length,
      },
    };
  }

  async file(auth: AuthContext, year: number, meta: RequestMeta) {
    const f = await this.build(year);
    await this.prisma.$transaction((tx) =>
      this.audit.log(tx, {
        actorId: auth.userId,
        action: 'export.run',
        entityType: 'accountant_package',
        entityId: null,
        changes: { export: { old: null, new: { year, ...f.counts } } },
        meta,
      }),
    );
    return f;
  }
}

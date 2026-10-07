import { randomBytes } from 'node:crypto';
import { Injectable } from '@nestjs/common';
import {
  formatNumber,
  type AuditChange,
  type employeeRatesSchema,
  type EmployeeRateDto,
  type FinanceCategoryDto,
  type financeCategorySchema,
  type OtherIncomeDto,
  type otherIncomeListQuerySchema,
  type otherIncomeSchema,
  type Paginated,
  type TariffDto,
  type tariffSchema,
  type WorkItemDto,
  type workItemSchema,
} from '@fluggi/contracts';
import { marginPct } from '@fluggi/domain';
import { Prisma } from '@fluggi/db';
import type { z } from 'zod';
import type { AuthContext, RequestMeta } from '../../core/auth/auth-context';
import { AuditService } from '../../core/audit/audit.service';
import { businessRule, forbidden, notFound } from '../../core/http/app.exception';
import { dateOnly, parseDate } from '../../core/http/serialize';
import { PrismaService } from '../../core/prisma/prisma.service';
import { OverheadService } from '../finance/overhead.service';
import { ExchangeRateService } from '../references/exchange-rate.service';

const ZERO = new Prisma.Decimal(0);
const workItemDto = (w: Prisma.WorkItemGetPayload<object>): WorkItemDto => ({
  id: w.id,
  code: w.code,
  name: w.name,
  unit: w.unit,
  specialty: w.specialty,
  defaultRate: w.defaultRate.toFixed(2),
  currency: w.currency,
  isActive: w.isActive,
});

const tariffInclude = {
  service: { select: { id: true, nameRu: true } },
  items: { include: { workItem: true }, orderBy: { sort: 'asc' } },
} satisfies Prisma.TariffInclude;
type TariffRow = Prisma.TariffGetPayload<{ include: typeof tariffInclude }>;

const incomeInclude = {
  categoryRef: { select: { name: true } },
  project: { select: { id: true, number: true, name: true } },
  client: { select: { id: true, name: true } },
  createdBy: { select: { id: true, fullName: true } },
} satisfies Prisma.OtherIncomeInclude;
type IncomeRow = Prisma.OtherIncomeGetPayload<{ include: typeof incomeInclude }>;

/**
 * Справочники финансов (CEO): категории доходов и расходов, прочие поступления,
 * единицы работ, личные ставки сотрудников, тарифы услуг с себестоимостью.
 */
@Injectable()
export class CatalogService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly rates: ExchangeRateService,
    private readonly overhead: OverheadService,
    private readonly audit: AuditService,
  ) {}

  private log(
    auth: AuthContext,
    action: string,
    entityType: string,
    entityId: string | null,
    changes: Record<string, AuditChange>,
    meta: RequestMeta,
  ) {
    return this.prisma.$transaction((tx) =>
      this.audit.log(tx, { actorId: auth.userId, action, entityType, entityId, changes, meta }),
    );
  }

  // ─────────────── Категории ───────────────

  async categories(kind?: 'EXPENSE' | 'INCOME'): Promise<FinanceCategoryDto[]> {
    const rows = await this.prisma.financeCategory.findMany({
      where: kind ? { kind } : {},
      include: { _count: { select: { expenses: true, otherIncomes: true } } },
      orderBy: [{ kind: 'asc' }, { sort: 'asc' }, { name: 'asc' }],
    });
    return rows.map((c) => ({
      id: c.id,
      code: c.code,
      kind: c.kind,
      name: c.name,
      accountHint: c.accountHint,
      isOverhead: c.isOverhead,
      isActive: c.isActive,
      sort: c.sort,
      usage: c._count.expenses + c._count.otherIncomes,
    }));
  }

  async createCategory(
    auth: AuthContext,
    input: z.output<typeof financeCategorySchema>,
    meta: RequestMeta,
  ) {
    const c = await this.prisma.financeCategory.create({
      data: {
        code: `C_${randomBytes(4).toString('hex').toUpperCase()}`,
        kind: input.kind,
        name: input.name,
        accountHint: input.accountHint ?? null,
        isOverhead: input.kind === 'EXPENSE' && input.isOverhead,
        isActive: input.isActive,
        sort: input.sort,
      },
    });
    await this.log(
      auth,
      'finance_category.create',
      'finance_category',
      c.id,
      { name: { old: null, new: c.name } },
      meta,
    );
    return (await this.categories()).find((x) => x.id === c.id)!;
  }

  async updateCategory(
    auth: AuthContext,
    id: string,
    input: z.output<typeof financeCategorySchema>,
    meta: RequestMeta,
  ) {
    const before = await this.prisma.financeCategory.findUnique({ where: { id } });
    if (!before) throw notFound('Категория');
    if (before.kind !== input.kind)
      throw businessRule('Тип категории (доход / расход) менять нельзя');
    await this.prisma.financeCategory.update({
      where: { id },
      data: {
        name: input.name,
        accountHint: input.accountHint ?? null,
        isOverhead: input.kind === 'EXPENSE' && input.isOverhead,
        isActive: input.isActive,
        sort: input.sort,
      },
    });
    await this.log(
      auth,
      'finance_category.update',
      'finance_category',
      id,
      {
        name: { old: before.name, new: input.name },
        isOverhead: { old: before.isOverhead, new: input.isOverhead },
      },
      meta,
    );
    return (await this.categories()).find((x) => x.id === id)!;
  }

  async deleteCategory(auth: AuthContext, id: string, meta: RequestMeta) {
    const c = (await this.categories()).find((x) => x.id === id);
    if (!c) throw notFound('Категория');
    if (c.usage > 0) throw businessRule('Категория уже используется — её можно только выключить');
    await this.prisma.financeCategory.delete({ where: { id } });
    await this.log(
      auth,
      'finance_category.delete',
      'finance_category',
      id,
      { name: { old: c.name, new: null } },
      meta,
    );
  }

  // ─────────────── Прочие поступления ───────────────

  private incomeDto(r: IncomeRow): OtherIncomeDto {
    return {
      id: r.id,
      number: formatNumber('INC', r.number),
      category: r.category,
      categoryName: r.categoryRef.name,
      amount: r.amount.toFixed(2),
      currency: r.currency,
      exchangeRate: r.exchangeRate.toString(),
      amountUzs: r.amountUzs.toFixed(2),
      incomeDate: dateOnly(r.incomeDate)!,
      project: r.project
        ? { id: r.project.id, name: r.project.name, number: formatNumber('P', r.project.number) }
        : null,
      client: r.client,
      description: r.description,
      createdBy: { id: r.createdBy.id, name: r.createdBy.fullName },
      createdAt: r.createdAt.toISOString(),
    };
  }

  async incomes(
    q: z.output<typeof otherIncomeListQuerySchema>,
  ): Promise<Paginated<OtherIncomeDto>> {
    const where: Prisma.OtherIncomeWhereInput = {
      deletedAt: null,
      ...(q.category ? { category: q.category } : {}),
      ...(q.dateFrom || q.dateTo
        ? {
            incomeDate: {
              gte: parseDate(q.dateFrom) ?? undefined,
              lte: parseDate(q.dateTo) ?? undefined,
            },
          }
        : {}),
    };
    const [rows, total] = await Promise.all([
      this.prisma.otherIncome.findMany({
        where,
        include: incomeInclude,
        orderBy: [{ incomeDate: 'desc' }, { number: 'desc' }],
        skip: (q.page - 1) * q.pageSize,
        take: q.pageSize,
      }),
      this.prisma.otherIncome.count({ where }),
    ]);
    return { items: rows.map((r) => this.incomeDto(r)), total, page: q.page, pageSize: q.pageSize };
  }

  private async assertIncomeCategory(code: string) {
    const c = await this.prisma.financeCategory.findUnique({ where: { code } });
    if (!c || c.kind !== 'INCOME' || !c.isActive)
      throw businessRule('Выберите категорию дохода', [
        { path: 'category', message: 'Нет такой категории доходов' },
      ]);
  }

  async saveIncome(
    auth: AuthContext,
    id: string | null,
    input: z.output<typeof otherIncomeSchema>,
    meta: RequestMeta,
  ): Promise<OtherIncomeDto> {
    await this.assertIncomeCategory(input.category);
    const { rate, amountUzs } = await this.rates.convert(input.amount, input.currency);
    const data = {
      category: input.category,
      amount: input.amount,
      currency: input.currency,
      exchangeRate: rate,
      amountUzs,
      incomeDate: parseDate(input.incomeDate)!,
      projectId: input.projectId ?? null,
      clientId: input.clientId ?? null,
      description: input.description ?? null,
    };
    let row: IncomeRow;
    if (id) {
      const before = await this.prisma.otherIncome.findFirst({ where: { id, deletedAt: null } });
      if (!before) throw notFound('Поступление');
      row = await this.prisma.otherIncome.update({ where: { id }, data, include: incomeInclude });
      await this.log(
        auth,
        'other_income.update',
        'other_income',
        id,
        { amountUzs: { old: before.amountUzs.toFixed(2), new: amountUzs } },
        meta,
      );
    } else {
      row = await this.prisma.otherIncome.create({
        data: { ...data, createdById: auth.userId },
        include: incomeInclude,
      });
      await this.log(
        auth,
        'other_income.create',
        'other_income',
        row.id,
        { amountUzs: { old: null, new: amountUzs } },
        meta,
      );
    }
    return this.incomeDto(row);
  }

  async deleteIncome(auth: AuthContext, id: string, meta: RequestMeta) {
    const r = await this.prisma.otherIncome.findFirst({ where: { id, deletedAt: null } });
    if (!r) throw notFound('Поступление');
    await this.prisma.otherIncome.update({ where: { id }, data: { deletedAt: new Date() } });
    await this.log(
      auth,
      'other_income.delete',
      'other_income',
      id,
      { amountUzs: { old: r.amountUzs.toFixed(2), new: null } },
      meta,
    );
  }

  // ─────────────── Единицы работ и ставки ───────────────

  async workItems(): Promise<WorkItemDto[]> {
    const rows = await this.prisma.workItem.findMany({
      orderBy: [{ sort: 'asc' }, { name: 'asc' }],
    });
    return rows.map(workItemDto);
  }

  async saveWorkItem(
    auth: AuthContext,
    id: string | null,
    input: z.output<typeof workItemSchema>,
    meta: RequestMeta,
  ) {
    const data = {
      name: input.name,
      unit: input.unit,
      specialty: input.specialty ?? null,
      defaultRate: input.defaultRate,
      currency: input.currency,
      isActive: input.isActive,
    };
    const w = id
      ? await this.prisma.workItem.update({ where: { id }, data })
      : await this.prisma.workItem.create({
          data: { ...data, code: `W_${randomBytes(4).toString('hex').toUpperCase()}`, sort: 100 },
        });
    await this.log(
      auth,
      id ? 'work_item.update' : 'work_item.create',
      'work_item',
      w.id,
      { defaultRate: { old: null, new: w.defaultRate.toFixed(2) } },
      meta,
    );
    return workItemDto(w);
  }

  /** Ставки сотрудника: CEO (зарплаты) — любого, сотрудник — свои. */
  async employeeRates(auth: AuthContext, userId: string): Promise<EmployeeRateDto[]> {
    if (userId !== auth.userId && auth.permissions['payroll.manage'] !== 'ALL') throw forbidden();
    const [items, rates] = await Promise.all([
      this.prisma.workItem.findMany({
        where: { isActive: true },
        orderBy: [{ sort: 'asc' }, { name: 'asc' }],
      }),
      this.prisma.employeeRate.findMany({ where: { userId } }),
    ]);
    return items.map((w) => {
      const r = rates.find((x) => x.workItemId === w.id);
      return {
        workItem: workItemDto(w),
        rate: r ? r.rate.toFixed(2) : null,
        currency: r?.currency ?? null,
      };
    });
  }

  async saveEmployeeRates(
    auth: AuthContext,
    userId: string,
    input: z.output<typeof employeeRatesSchema>,
    meta: RequestMeta,
  ) {
    const user = await this.prisma.user.findFirst({ where: { id: userId, deletedAt: null } });
    if (!user) throw notFound('Сотрудник');
    await this.prisma.$transaction(async (tx) => {
      for (const r of input.rates) {
        const key = { userId_workItemId: { userId, workItemId: r.workItemId } };
        if (r.rate === null)
          await tx.employeeRate.deleteMany({ where: { userId, workItemId: r.workItemId } });
        else
          await tx.employeeRate.upsert({
            where: key,
            update: { rate: r.rate, currency: r.currency },
            create: { userId, workItemId: r.workItemId, rate: r.rate, currency: r.currency },
          });
      }
      await this.audit.log(tx, {
        actorId: auth.userId,
        action: 'employee.rates',
        entityType: 'user',
        entityId: userId,
        changes: { rates: { old: null, new: input.rates } },
        meta,
      });
    });
    return this.employeeRates(auth, userId);
  }

  // ─────────────── Тарифы ───────────────

  private async usdRate() {
    return new Prisma.Decimal(await this.rates.rateFor('USD').catch(() => '0'));
  }

  private async tariffDto(
    t: TariffRow,
    auth: AuthContext,
    ctx?: { usd: Prisma.Decimal; overhead: Prisma.Decimal },
  ): Promise<TariffDto> {
    const usd = ctx?.usd ?? (await this.usdRate());
    const toUzs = (amount: Prisma.Decimal, currency: string) =>
      currency === 'USD' ? amount.mul(usd) : amount;
    const items = t.items.map((i) => {
      const cost =
        i.kind === 'PIECE' && i.workItem
          ? toUzs(i.workItem.defaultRate.mul(i.quantity), i.workItem.currency)
          : toUzs(i.amount ?? ZERO, i.currency);
      return {
        id: i.id,
        kind: i.kind,
        workItem: i.workItem ? workItemDto(i.workItem) : null,
        quantity: i.quantity.toString(),
        specialty: i.specialty,
        amount: i.amount ? i.amount.toFixed(2) : null,
        currency: i.currency,
        label: i.label,
        costUzs: cost.toFixed(2),
      };
    });
    const company = auth.permissions['finance.company.read'] === 'ALL';
    let economics: TariffDto['economics'] = null;
    if (company) {
      const priceUzs = toUzs(t.price, t.currency);
      const executors = items.reduce((s, i) => s.add(i.costUzs), ZERO);
      const overhead = ctx?.overhead ?? (await this.overhead.monthlyShareEstimate());
      const margin = priceUzs.sub(executors).sub(overhead);
      economics = {
        priceUzs: priceUzs.toFixed(2),
        executorsUzs: executors.toFixed(2),
        overheadUzs: overhead.toFixed(2),
        marginUzs: margin.toFixed(2),
        marginPct: marginPct(margin.toString(), priceUzs.toString()),
      };
    }
    return {
      id: t.id,
      service: { id: t.service.id, name: t.service.nameRu },
      name: t.name,
      description: t.description,
      price: t.price.toFixed(2),
      currency: t.currency,
      isActive: t.isActive,
      sort: t.sort,
      items: company ? items : items.map((i) => ({ ...i, costUzs: '0.00', amount: null })),
      economics,
    };
  }

  async tariffs(auth: AuthContext, q: { serviceId?: string; all?: boolean }): Promise<TariffDto[]> {
    const rows = await this.prisma.tariff.findMany({
      where: {
        ...(q.serviceId ? { serviceId: q.serviceId } : {}),
        ...(q.all ? {} : { isActive: true }),
      },
      include: tariffInclude,
      orderBy: [{ service: { sort: 'asc' } }, { sort: 'asc' }, { name: 'asc' }],
    });
    const ctx = { usd: await this.usdRate(), overhead: await this.overhead.monthlyShareEstimate() };
    return Promise.all(rows.map((t) => this.tariffDto(t, auth, ctx)));
  }

  async saveTariff(
    auth: AuthContext,
    id: string | null,
    input: z.output<typeof tariffSchema>,
    meta: RequestMeta,
  ): Promise<TariffDto> {
    const service = await this.prisma.service.findUnique({ where: { id: input.serviceId } });
    if (!service) throw notFound('Услуга');
    const items = input.items.map((i, sort) => ({
      kind: i.kind,
      workItemId: i.kind === 'PIECE' ? i.workItemId! : null,
      quantity: i.quantity,
      specialty: i.kind === 'FIXED' ? i.specialty! : null,
      amount: i.kind === 'FIXED' ? i.amount! : null,
      currency: i.currency,
      label: i.label ?? null,
      sort,
    }));
    const data = {
      serviceId: input.serviceId,
      name: input.name,
      description: input.description ?? null,
      price: input.price,
      currency: input.currency,
      isActive: input.isActive,
      sort: input.sort,
    };
    const before = id ? await this.prisma.tariff.findUnique({ where: { id } }) : null;
    if (id && !before) throw notFound('Тариф');
    const t = await this.prisma.$transaction(async (tx) => {
      if (id) {
        // Состав заменяется целиком. Проекты хранят свои строки себестоимости (копию), ссылку снимаем.
        await tx.projectCostLine.updateMany({
          where: { tariffItem: { tariffId: id } },
          data: { tariffItemId: null },
        });
        await tx.tariffItem.deleteMany({ where: { tariffId: id } });
        const row = await tx.tariff.update({ where: { id }, data });
        await tx.tariffItem.createMany({ data: items.map((i) => ({ ...i, tariffId: id })) });
        return row;
      }
      return tx.tariff.create({ data: { ...data, items: { create: items } } });
    });
    await this.log(
      auth,
      id ? 'tariff.update' : 'tariff.create',
      'tariff',
      t.id,
      {
        price: {
          old: before ? `${before.price.toFixed(2)} ${before.currency}` : null,
          new: `${input.price} ${input.currency}`,
        },
      },
      meta,
    );
    return this.tariffDto(
      await this.prisma.tariff.findUniqueOrThrow({ where: { id: t.id }, include: tariffInclude }),
      auth,
    );
  }
}

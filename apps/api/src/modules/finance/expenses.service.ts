import { Injectable } from '@nestjs/common';
import {
  formatNumber,
  type createExpenseSchema,
  type ExpenseDto,
  type expenseListQuerySchema,
  type Paginated,
  type updateExpenseSchema,
} from '@fluggi/contracts';
import type { Expense, Prisma } from '@fluggi/db';
import type { z } from 'zod';
import type { AuthContext, RequestMeta } from '../../core/auth/auth-context';
import { AuditService, diffFields } from '../../core/audit/audit.service';
import { businessRule, forbidden, notFound } from '../../core/http/app.exception';
import { dateOnly, decReq, parseDate } from '../../core/http/serialize';
import { PrismaService } from '../../core/prisma/prisma.service';
import { ActivityService } from '../crm/activity.service';
import { ProjectAccessService } from '../projects/project-access.service';
import { ExchangeRateService } from '../references/exchange-rate.service';

const include = {
  project: { select: { id: true, number: true, name: true } },
  payee: { select: { id: true, fullName: true } },
  createdBy: { select: { id: true, fullName: true } },
  categoryRef: { select: { name: true } },
} satisfies Prisma.ExpenseInclude;

type Row = Prisma.ExpenseGetPayload<{ include: typeof include }>;

/**
 * Расходы (ТЗ §26). Проектные видит и вносит тот, у кого есть доступ к финансам проекта
 * (РОП — проекты отдела, CEO — все); расходы компании — только с правом finance.company.read.
 */
@Injectable()
export class ExpensesService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly projects: ProjectAccessService,
    private readonly rates: ExchangeRateService,
    private readonly audit: AuditService,
    private readonly activity: ActivityService,
  ) {}

  private companyAllowed(auth: AuthContext) {
    return auth.permissions['finance.company.read'] === 'ALL';
  }

  /** Расходы, которые пользователь видит (право finance.read). */
  where(auth: AuthContext, code: 'finance.read' | 'expense.update' = 'finance.read') {
    const or: Prisma.ExpenseWhereInput[] = [
      { scope: 'PROJECT', project: this.projects.projectWhere(auth, code) },
    ];
    if (this.companyAllowed(auth)) or.push({ scope: 'COMPANY' });
    return { deletedAt: null, OR: or } satisfies Prisma.ExpenseWhereInput;
  }

  /** Категория расхода — действующая категория из справочника. */
  private async assertCategory(code: string) {
    const c = await this.prisma.financeCategory.findUnique({ where: { code } });
    if (!c || c.kind !== 'EXPENSE' || !c.isActive)
      throw businessRule('Выберите категорию расхода из справочника', [
        { path: 'category', message: 'Нет такой категории расходов' },
      ]);
  }

  private async editableIds(auth: AuthContext, rows: Expense[]) {
    if (!auth.permissions['expense.update'] || rows.length === 0) return new Set<string>();
    const ok = await this.prisma.expense.findMany({
      where: { AND: [this.where(auth, 'expense.update'), { id: { in: rows.map((r) => r.id) } }] },
      select: { id: true },
    });
    return new Set(ok.map((r) => r.id));
  }

  private toDto(e: Row, canEdit: boolean): ExpenseDto {
    return {
      id: e.id,
      number: formatNumber('EXP', e.number),
      scope: e.scope,
      project: e.project
        ? { id: e.project.id, name: e.project.name, number: formatNumber('P', e.project.number) }
        : null,
      category: e.category,
      categoryName: e.categoryRef.name,
      amount: decReq(e.amount),
      currency: e.currency,
      exchangeRate: e.exchangeRate.toString(),
      amountUzs: decReq(e.amountUzs),
      expenseDate: dateOnly(e.expenseDate)!,
      payee: e.payee ? { id: e.payee.id, name: e.payee.fullName } : null,
      description: e.description,
      createdBy: { id: e.createdBy.id, name: e.createdBy.fullName },
      createdAt: e.createdAt.toISOString(),
      canEdit,
    };
  }

  async list(
    auth: AuthContext,
    q: z.output<typeof expenseListQuerySchema>,
  ): Promise<Paginated<ExpenseDto> & { totalUzs: string }> {
    const and: Prisma.ExpenseWhereInput[] = [this.where(auth)];
    if (q.scope) and.push({ scope: q.scope });
    if (q.projectId) and.push({ projectId: q.projectId });
    if (q.category) and.push({ category: q.category });
    if (q.dateFrom) and.push({ expenseDate: { gte: parseDate(q.dateFrom)! } });
    if (q.dateTo) and.push({ expenseDate: { lte: parseDate(q.dateTo)! } });
    const where = { AND: and };
    const [rows, total, sum] = await Promise.all([
      this.prisma.expense.findMany({
        where,
        include,
        orderBy: [{ expenseDate: 'desc' }, { createdAt: 'desc' }],
        skip: (q.page - 1) * q.pageSize,
        take: q.pageSize,
      }),
      this.prisma.expense.count({ where }),
      this.prisma.expense.aggregate({ where, _sum: { amountUzs: true } }),
    ]);
    const editable = await this.editableIds(auth, rows);
    return {
      items: rows.map((r) => this.toDto(r, editable.has(r.id))),
      total,
      page: q.page,
      pageSize: q.pageSize,
      totalUzs: (sum._sum.amountUzs ?? 0).toString(),
    };
  }

  private async assertPayee(userId: string | null | undefined) {
    if (!userId) return;
    const u = await this.prisma.user.findFirst({ where: { id: userId, deletedAt: null } });
    if (!u) throw businessRule('Получатель не найден');
  }

  async create(
    auth: AuthContext,
    input: z.output<typeof createExpenseSchema>,
    meta: RequestMeta,
  ): Promise<ExpenseDto> {
    if (input.scope === 'COMPANY') {
      if (!this.companyAllowed(auth)) throw forbidden('Расходы компании вносит CEO');
    } else {
      await this.projects.project(auth, input.projectId!, 'expense.create');
    }
    await this.assertPayee(input.payeeUserId);
    await this.assertCategory(input.category);
    const { rate, amountUzs } = await this.rates.convert(input.amount, input.currency);
    return this.prisma.$transaction(async (tx) => {
      const e = await tx.expense.create({
        data: {
          scope: input.scope,
          projectId: input.scope === 'PROJECT' ? input.projectId! : null,
          category: input.category,
          amount: input.amount,
          currency: input.currency,
          exchangeRate: rate,
          amountUzs,
          expenseDate: parseDate(input.expenseDate)!,
          payeeUserId: input.payeeUserId ?? null,
          description: input.description ?? null,
          createdById: auth.userId,
        },
        include,
      });
      await this.audit.log(tx, {
        actorId: auth.userId,
        action: 'expense.create',
        entityType: 'expense',
        entityId: e.id,
        changes: {
          amountUzs: { old: null, new: amountUzs },
          category: { old: null, new: e.category },
          projectId: { old: null, new: e.projectId },
        },
        meta,
      });
      if (e.projectId)
        await this.activity.log(tx, {
          type: 'expense.created',
          actorId: auth.userId,
          projectId: e.projectId,
          payload: { number: formatNumber('EXP', e.number), amountUzs, category: e.category },
        });
      return this.toDto(e, true);
    });
  }

  private async findEditable(auth: AuthContext, id: string) {
    if (!auth.permissions['expense.update']) throw forbidden();
    const e = await this.prisma.expense.findFirst({
      where: { AND: [this.where(auth, 'expense.update'), { id }] },
    });
    if (!e) throw notFound('Расход');
    return e;
  }

  async update(
    auth: AuthContext,
    id: string,
    input: z.output<typeof updateExpenseSchema>,
    meta: RequestMeta,
  ): Promise<ExpenseDto> {
    const before = await this.findEditable(auth, id);
    if (input.payeeUserId) await this.assertPayee(input.payeeUserId);
    if (input.category && input.category !== before.category)
      await this.assertCategory(input.category);
    const amount = input.amount ?? before.amount.toFixed(2);
    const currency = input.currency ?? before.currency;
    const money =
      input.amount !== undefined || input.currency !== undefined
        ? await this.rates.convert(amount, currency)
        : null;
    const data = {
      category: input.category,
      amount: money ? amount : undefined,
      currency: money ? currency : undefined,
      exchangeRate: money?.rate,
      amountUzs: money?.amountUzs,
      expenseDate: input.expenseDate ? parseDate(input.expenseDate)! : undefined,
      payeeUserId: input.payeeUserId,
      description: input.description,
    };
    return this.prisma.$transaction(async (tx) => {
      const e = await tx.expense.update({ where: { id }, data, include });
      const changes = diffFields(before, data as Partial<Expense>, [
        'category',
        'amount',
        'currency',
        'amountUzs',
        'expenseDate',
        'payeeUserId',
        'description',
      ]);
      if (changes)
        await this.audit.log(tx, {
          actorId: auth.userId,
          action: 'expense.update',
          entityType: 'expense',
          entityId: id,
          changes,
          meta,
        });
      return this.toDto(e, true);
    });
  }

  /** Удаление мягкое: запись остаётся в БД и в журнале аудита. */
  async remove(auth: AuthContext, id: string, meta: RequestMeta): Promise<void> {
    const e = await this.findEditable(auth, id);
    await this.prisma.$transaction(async (tx) => {
      await tx.expense.update({ where: { id }, data: { deletedAt: new Date() } });
      await this.audit.log(tx, {
        actorId: auth.userId,
        action: 'expense.delete',
        entityType: 'expense',
        entityId: id,
        changes: { amountUzs: { old: e.amountUzs.toFixed(2), new: null } },
        meta,
      });
    });
  }
}

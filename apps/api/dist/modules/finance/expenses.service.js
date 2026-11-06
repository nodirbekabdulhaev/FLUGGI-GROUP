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
Object.defineProperty(exports, "__esModule", { value: true });
exports.ExpensesService = void 0;
const common_1 = require("@nestjs/common");
const contracts_1 = require("@fluggi/contracts");
const audit_service_1 = require("../../core/audit/audit.service");
const app_exception_1 = require("../../core/http/app.exception");
const serialize_1 = require("../../core/http/serialize");
const prisma_service_1 = require("../../core/prisma/prisma.service");
const activity_service_1 = require("../crm/activity.service");
const project_access_service_1 = require("../projects/project-access.service");
const exchange_rate_service_1 = require("../references/exchange-rate.service");
const include = {
    project: { select: { id: true, number: true, name: true } },
    payee: { select: { id: true, fullName: true } },
    createdBy: { select: { id: true, fullName: true } },
    categoryRef: { select: { name: true } },
};
/**
 * Расходы (ТЗ §26). Проектные видит и вносит тот, у кого есть доступ к финансам проекта
 * (РОП — проекты отдела, CEO — все); расходы компании — только с правом finance.company.read.
 */
let ExpensesService = class ExpensesService {
    prisma;
    projects;
    rates;
    audit;
    activity;
    constructor(prisma, projects, rates, audit, activity) {
        this.prisma = prisma;
        this.projects = projects;
        this.rates = rates;
        this.audit = audit;
        this.activity = activity;
    }
    companyAllowed(auth) {
        return auth.permissions['finance.company.read'] === 'ALL';
    }
    /** Расходы, которые пользователь видит (право finance.read). */
    where(auth, code = 'finance.read') {
        const or = [
            { scope: 'PROJECT', project: this.projects.projectWhere(auth, code) },
        ];
        if (this.companyAllowed(auth))
            or.push({ scope: 'COMPANY' });
        return { deletedAt: null, OR: or };
    }
    /** Категория расхода — действующая категория из справочника. */
    async assertCategory(code) {
        const c = await this.prisma.financeCategory.findUnique({ where: { code } });
        if (!c || c.kind !== 'EXPENSE' || !c.isActive)
            throw (0, app_exception_1.businessRule)('Выберите категорию расхода из справочника', [
                { path: 'category', message: 'Нет такой категории расходов' },
            ]);
    }
    async editableIds(auth, rows) {
        if (!auth.permissions['expense.update'] || rows.length === 0)
            return new Set();
        const ok = await this.prisma.expense.findMany({
            where: { AND: [this.where(auth, 'expense.update'), { id: { in: rows.map((r) => r.id) } }] },
            select: { id: true },
        });
        return new Set(ok.map((r) => r.id));
    }
    toDto(e, canEdit) {
        return {
            id: e.id,
            number: (0, contracts_1.formatNumber)('EXP', e.number),
            scope: e.scope,
            project: e.project
                ? { id: e.project.id, name: e.project.name, number: (0, contracts_1.formatNumber)('P', e.project.number) }
                : null,
            category: e.category,
            categoryName: e.categoryRef.name,
            amount: (0, serialize_1.decReq)(e.amount),
            currency: e.currency,
            exchangeRate: e.exchangeRate.toString(),
            amountUzs: (0, serialize_1.decReq)(e.amountUzs),
            expenseDate: (0, serialize_1.dateOnly)(e.expenseDate),
            payee: e.payee ? { id: e.payee.id, name: e.payee.fullName } : null,
            description: e.description,
            createdBy: { id: e.createdBy.id, name: e.createdBy.fullName },
            createdAt: e.createdAt.toISOString(),
            canEdit,
        };
    }
    async list(auth, q) {
        const and = [this.where(auth)];
        if (q.scope)
            and.push({ scope: q.scope });
        if (q.projectId)
            and.push({ projectId: q.projectId });
        if (q.category)
            and.push({ category: q.category });
        if (q.dateFrom)
            and.push({ expenseDate: { gte: (0, serialize_1.parseDate)(q.dateFrom) } });
        if (q.dateTo)
            and.push({ expenseDate: { lte: (0, serialize_1.parseDate)(q.dateTo) } });
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
    async assertPayee(userId) {
        if (!userId)
            return;
        const u = await this.prisma.user.findFirst({ where: { id: userId, deletedAt: null } });
        if (!u)
            throw (0, app_exception_1.businessRule)('Получатель не найден');
    }
    async create(auth, input, meta) {
        if (input.scope === 'COMPANY') {
            if (!this.companyAllowed(auth))
                throw (0, app_exception_1.forbidden)('Расходы компании вносит CEO');
        }
        else {
            await this.projects.project(auth, input.projectId, 'expense.create');
        }
        await this.assertPayee(input.payeeUserId);
        await this.assertCategory(input.category);
        const { rate, amountUzs } = await this.rates.convert(input.amount, input.currency);
        return this.prisma.$transaction(async (tx) => {
            const e = await tx.expense.create({
                data: {
                    scope: input.scope,
                    projectId: input.scope === 'PROJECT' ? input.projectId : null,
                    category: input.category,
                    amount: input.amount,
                    currency: input.currency,
                    exchangeRate: rate,
                    amountUzs,
                    expenseDate: (0, serialize_1.parseDate)(input.expenseDate),
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
                    payload: { number: (0, contracts_1.formatNumber)('EXP', e.number), amountUzs, category: e.category },
                });
            return this.toDto(e, true);
        });
    }
    async findEditable(auth, id) {
        if (!auth.permissions['expense.update'])
            throw (0, app_exception_1.forbidden)();
        const e = await this.prisma.expense.findFirst({
            where: { AND: [this.where(auth, 'expense.update'), { id }] },
        });
        if (!e)
            throw (0, app_exception_1.notFound)('Расход');
        return e;
    }
    async update(auth, id, input, meta) {
        const before = await this.findEditable(auth, id);
        if (input.payeeUserId)
            await this.assertPayee(input.payeeUserId);
        if (input.category && input.category !== before.category)
            await this.assertCategory(input.category);
        const amount = input.amount ?? before.amount.toFixed(2);
        const currency = input.currency ?? before.currency;
        const money = input.amount !== undefined || input.currency !== undefined
            ? await this.rates.convert(amount, currency)
            : null;
        const data = {
            category: input.category,
            amount: money ? amount : undefined,
            currency: money ? currency : undefined,
            exchangeRate: money?.rate,
            amountUzs: money?.amountUzs,
            expenseDate: input.expenseDate ? (0, serialize_1.parseDate)(input.expenseDate) : undefined,
            payeeUserId: input.payeeUserId,
            description: input.description,
        };
        return this.prisma.$transaction(async (tx) => {
            const e = await tx.expense.update({ where: { id }, data, include });
            const changes = (0, audit_service_1.diffFields)(before, data, [
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
    async remove(auth, id, meta) {
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
};
exports.ExpensesService = ExpensesService;
exports.ExpensesService = ExpensesService = __decorate([
    (0, common_1.Injectable)(),
    __metadata("design:paramtypes", [prisma_service_1.PrismaService,
        project_access_service_1.ProjectAccessService,
        exchange_rate_service_1.ExchangeRateService,
        audit_service_1.AuditService,
        activity_service_1.ActivityService])
], ExpensesService);
//# sourceMappingURL=expenses.service.js.map
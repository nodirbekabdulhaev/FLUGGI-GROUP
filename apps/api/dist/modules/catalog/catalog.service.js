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
exports.CatalogService = void 0;
const node_crypto_1 = require("node:crypto");
const common_1 = require("@nestjs/common");
const contracts_1 = require("@fluggi/contracts");
const domain_1 = require("@fluggi/domain");
const db_1 = require("@fluggi/db");
const audit_service_1 = require("../../core/audit/audit.service");
const app_exception_1 = require("../../core/http/app.exception");
const serialize_1 = require("../../core/http/serialize");
const prisma_service_1 = require("../../core/prisma/prisma.service");
const overhead_service_1 = require("../finance/overhead.service");
const exchange_rate_service_1 = require("../references/exchange-rate.service");
const ZERO = new db_1.Prisma.Decimal(0);
const workItemDto = (w) => ({
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
};
const incomeInclude = {
    categoryRef: { select: { name: true } },
    project: { select: { id: true, number: true, name: true } },
    client: { select: { id: true, name: true } },
    createdBy: { select: { id: true, fullName: true } },
};
/**
 * Справочники финансов (CEO): категории доходов и расходов, прочие поступления,
 * единицы работ, личные ставки сотрудников, тарифы услуг с себестоимостью.
 */
let CatalogService = class CatalogService {
    prisma;
    rates;
    overhead;
    audit;
    constructor(prisma, rates, overhead, audit) {
        this.prisma = prisma;
        this.rates = rates;
        this.overhead = overhead;
        this.audit = audit;
    }
    log(auth, action, entityType, entityId, changes, meta) {
        return this.prisma.$transaction((tx) => this.audit.log(tx, { actorId: auth.userId, action, entityType, entityId, changes, meta }));
    }
    // ─────────────── Категории ───────────────
    async categories(kind) {
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
    async createCategory(auth, input, meta) {
        const c = await this.prisma.financeCategory.create({
            data: {
                code: `C_${(0, node_crypto_1.randomBytes)(4).toString('hex').toUpperCase()}`,
                kind: input.kind,
                name: input.name,
                accountHint: input.accountHint ?? null,
                isOverhead: input.kind === 'EXPENSE' && input.isOverhead,
                isActive: input.isActive,
                sort: input.sort,
            },
        });
        await this.log(auth, 'finance_category.create', 'finance_category', c.id, { name: { old: null, new: c.name } }, meta);
        return (await this.categories()).find((x) => x.id === c.id);
    }
    async updateCategory(auth, id, input, meta) {
        const before = await this.prisma.financeCategory.findUnique({ where: { id } });
        if (!before)
            throw (0, app_exception_1.notFound)('Категория');
        if (before.kind !== input.kind)
            throw (0, app_exception_1.businessRule)('Тип категории (доход / расход) менять нельзя');
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
        await this.log(auth, 'finance_category.update', 'finance_category', id, {
            name: { old: before.name, new: input.name },
            isOverhead: { old: before.isOverhead, new: input.isOverhead },
        }, meta);
        return (await this.categories()).find((x) => x.id === id);
    }
    async deleteCategory(auth, id, meta) {
        const c = (await this.categories()).find((x) => x.id === id);
        if (!c)
            throw (0, app_exception_1.notFound)('Категория');
        if (c.usage > 0)
            throw (0, app_exception_1.businessRule)('Категория уже используется — её можно только выключить');
        await this.prisma.financeCategory.delete({ where: { id } });
        await this.log(auth, 'finance_category.delete', 'finance_category', id, { name: { old: c.name, new: null } }, meta);
    }
    // ─────────────── Прочие поступления ───────────────
    incomeDto(r) {
        return {
            id: r.id,
            number: (0, contracts_1.formatNumber)('INC', r.number),
            category: r.category,
            categoryName: r.categoryRef.name,
            amount: r.amount.toFixed(2),
            currency: r.currency,
            exchangeRate: r.exchangeRate.toString(),
            amountUzs: r.amountUzs.toFixed(2),
            incomeDate: (0, serialize_1.dateOnly)(r.incomeDate),
            project: r.project
                ? { id: r.project.id, name: r.project.name, number: (0, contracts_1.formatNumber)('P', r.project.number) }
                : null,
            client: r.client,
            description: r.description,
            createdBy: { id: r.createdBy.id, name: r.createdBy.fullName },
            createdAt: r.createdAt.toISOString(),
        };
    }
    async incomes(q) {
        const where = {
            deletedAt: null,
            ...(q.category ? { category: q.category } : {}),
            ...(q.dateFrom || q.dateTo
                ? {
                    incomeDate: {
                        gte: (0, serialize_1.parseDate)(q.dateFrom) ?? undefined,
                        lte: (0, serialize_1.parseDate)(q.dateTo) ?? undefined,
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
    async assertIncomeCategory(code) {
        const c = await this.prisma.financeCategory.findUnique({ where: { code } });
        if (!c || c.kind !== 'INCOME' || !c.isActive)
            throw (0, app_exception_1.businessRule)('Выберите категорию дохода', [
                { path: 'category', message: 'Нет такой категории доходов' },
            ]);
    }
    async saveIncome(auth, id, input, meta) {
        await this.assertIncomeCategory(input.category);
        const { rate, amountUzs } = await this.rates.convert(input.amount, input.currency);
        const data = {
            category: input.category,
            amount: input.amount,
            currency: input.currency,
            exchangeRate: rate,
            amountUzs,
            incomeDate: (0, serialize_1.parseDate)(input.incomeDate),
            projectId: input.projectId ?? null,
            clientId: input.clientId ?? null,
            description: input.description ?? null,
        };
        let row;
        if (id) {
            const before = await this.prisma.otherIncome.findFirst({ where: { id, deletedAt: null } });
            if (!before)
                throw (0, app_exception_1.notFound)('Поступление');
            row = await this.prisma.otherIncome.update({ where: { id }, data, include: incomeInclude });
            await this.log(auth, 'other_income.update', 'other_income', id, { amountUzs: { old: before.amountUzs.toFixed(2), new: amountUzs } }, meta);
        }
        else {
            row = await this.prisma.otherIncome.create({
                data: { ...data, createdById: auth.userId },
                include: incomeInclude,
            });
            await this.log(auth, 'other_income.create', 'other_income', row.id, { amountUzs: { old: null, new: amountUzs } }, meta);
        }
        return this.incomeDto(row);
    }
    async deleteIncome(auth, id, meta) {
        const r = await this.prisma.otherIncome.findFirst({ where: { id, deletedAt: null } });
        if (!r)
            throw (0, app_exception_1.notFound)('Поступление');
        await this.prisma.otherIncome.update({ where: { id }, data: { deletedAt: new Date() } });
        await this.log(auth, 'other_income.delete', 'other_income', id, { amountUzs: { old: r.amountUzs.toFixed(2), new: null } }, meta);
    }
    // ─────────────── Единицы работ и ставки ───────────────
    async workItems() {
        const rows = await this.prisma.workItem.findMany({
            orderBy: [{ sort: 'asc' }, { name: 'asc' }],
        });
        return rows.map(workItemDto);
    }
    async saveWorkItem(auth, id, input, meta) {
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
                data: { ...data, code: `W_${(0, node_crypto_1.randomBytes)(4).toString('hex').toUpperCase()}`, sort: 100 },
            });
        await this.log(auth, id ? 'work_item.update' : 'work_item.create', 'work_item', w.id, { defaultRate: { old: null, new: w.defaultRate.toFixed(2) } }, meta);
        return workItemDto(w);
    }
    /** Направление бизнеса: создать или переименовать (код генерируется). */
    async saveDirection(auth, id, input, meta) {
        const before = id ? await this.prisma.direction.findUnique({ where: { id } }) : null;
        if (id && !before)
            throw (0, app_exception_1.notFound)('Направление');
        const d = id
            ? await this.prisma.direction.update({ where: { id }, data: input })
            : await this.prisma.direction.create({
                data: { ...input, code: `D_${(0, node_crypto_1.randomBytes)(4).toString('hex').toUpperCase()}` },
            });
        await this.log(auth, id ? 'direction.update' : 'direction.create', 'direction', d.id, { name: { old: before?.name ?? null, new: d.name } }, meta);
        return { id: d.id, code: d.code, name: d.name, sort: d.sort, isActive: d.isActive };
    }
    /** Ставки сотрудника: CEO (зарплаты) — любого, сотрудник — свои. */
    async employeeRates(auth, userId) {
        if (userId !== auth.userId && auth.permissions['payroll.manage'] !== 'ALL')
            throw (0, app_exception_1.forbidden)();
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
    async saveEmployeeRates(auth, userId, input, meta) {
        const user = await this.prisma.user.findFirst({ where: { id: userId, deletedAt: null } });
        if (!user)
            throw (0, app_exception_1.notFound)('Сотрудник');
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
    async usdRate() {
        return new db_1.Prisma.Decimal(await this.rates.rateFor('USD').catch(() => '0'));
    }
    async tariffDto(t, auth, ctx) {
        const usd = ctx?.usd ?? (await this.usdRate());
        const toUzs = (amount, currency) => currency === 'USD' ? amount.mul(usd) : amount;
        const items = t.items.map((i) => {
            const cost = i.kind === 'PIECE' && i.workItem
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
        let economics = null;
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
                marginPct: (0, domain_1.marginPct)(margin.toString(), priceUzs.toString()),
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
    async tariffs(auth, q) {
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
    async saveTariff(auth, id, input, meta) {
        const service = await this.prisma.service.findUnique({ where: { id: input.serviceId } });
        if (!service)
            throw (0, app_exception_1.notFound)('Услуга');
        const items = input.items.map((i, sort) => ({
            kind: i.kind,
            workItemId: i.kind === 'PIECE' ? i.workItemId : null,
            quantity: i.quantity,
            specialty: i.kind === 'FIXED' ? i.specialty : null,
            amount: i.kind === 'FIXED' ? i.amount : null,
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
        if (id && !before)
            throw (0, app_exception_1.notFound)('Тариф');
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
        await this.log(auth, id ? 'tariff.update' : 'tariff.create', 'tariff', t.id, {
            price: {
                old: before ? `${before.price.toFixed(2)} ${before.currency}` : null,
                new: `${input.price} ${input.currency}`,
            },
        }, meta);
        return this.tariffDto(await this.prisma.tariff.findUniqueOrThrow({ where: { id: t.id }, include: tariffInclude }), auth);
    }
};
exports.CatalogService = CatalogService;
exports.CatalogService = CatalogService = __decorate([
    (0, common_1.Injectable)(),
    __metadata("design:paramtypes", [prisma_service_1.PrismaService,
        exchange_rate_service_1.ExchangeRateService,
        overhead_service_1.OverheadService,
        audit_service_1.AuditService])
], CatalogService);
//# sourceMappingURL=catalog.service.js.map
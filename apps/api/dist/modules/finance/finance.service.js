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
exports.FinanceService = void 0;
const common_1 = require("@nestjs/common");
const contracts_1 = require("@fluggi/contracts");
const domain_1 = require("@fluggi/domain");
const db_1 = require("@fluggi/db");
const prisma_service_1 = require("../../core/prisma/prisma.service");
const crm_access_service_1 = require("../crm/crm-access.service");
const project_access_service_1 = require("../projects/project-access.service");
const exchange_rate_service_1 = require("../references/exchange-rate.service");
const expenses_service_1 = require("./expenses.service");
const overhead_service_1 = require("./overhead.service");
const ZERO = new db_1.Prisma.Decimal(0);
const dec = (v) => v ?? ZERO;
const money = (v) => v.toFixed(2);
/** Финансы проекта и компании (ТЗ §25, §27; формулы — docs/BUSINESS_RULES.md §4). */
let FinanceService = class FinanceService {
    prisma;
    projects;
    crm;
    expenses;
    overhead;
    rates;
    constructor(prisma, projects, crm, expenses, overhead, rates) {
        this.prisma = prisma;
        this.projects = projects;
        this.crm = crm;
        this.expenses = expenses;
        this.overhead = overhead;
        this.rates = rates;
    }
    /** Названия категорий по коду (справочник редактирует CEO). */
    async categoryNames() {
        const rows = await this.prisma.financeCategory.findMany({ select: { code: true, name: true } });
        return new Map(rows.map((r) => [r.code, r.name]));
    }
    /** Плановые (ещё не начисленные) расходы по тарифу, UZS. */
    async plannedCost(projectId) {
        const lines = await this.prisma.projectCostLine.findMany({
            where: { projectId, status: 'PLANNED' },
            select: { quantity: true, rate: true, currency: true },
        });
        if (!lines.length)
            return ZERO;
        const usd = lines.some((l) => l.currency === 'USD')
            ? new db_1.Prisma.Decimal(await this.rates.rateFor('USD').catch(() => '0'))
            : ZERO;
        return lines.reduce((s, l) => s.add(l.quantity.mul(l.rate).mul(l.currency === 'USD' ? usd : 1)), ZERO);
    }
    /** Получено по сделкам: оплаты минус возвраты, по каждой сделке. */
    async collectedByDeal(dealIds) {
        const rows = await this.prisma.payment.groupBy({
            by: ['dealId', 'type'],
            where: { dealId: { in: dealIds }, status: 'PAID' },
            _sum: { amountUzs: true },
        });
        const map = new Map();
        for (const r of rows) {
            const v = dec(r._sum.amountUzs);
            map.set(r.dealId, (map.get(r.dealId) ?? ZERO).add(r.type === 'REFUND' ? v.neg() : v));
        }
        return map;
    }
    async expensesByProject(projectIds) {
        const rows = await this.prisma.expense.groupBy({
            by: ['projectId'],
            where: { projectId: { in: projectIds }, deletedAt: null },
            _sum: { amountUzs: true },
        });
        return new Map(rows.map((r) => [r.projectId, dec(r._sum.amountUzs)]));
    }
    /** Финансовая карточка проекта (ТЗ §25). */
    async project(auth, id) {
        const p = await this.projects.project(auth, id, 'finance.read');
        const [collected, byCat, commissions, names, overhead, planned] = await Promise.all([
            this.collectedByDeal([p.dealId]),
            this.prisma.expense.groupBy({
                by: ['category'],
                where: { projectId: id, deletedAt: null },
                _sum: { amountUzs: true },
            }),
            this.prisma.commission.aggregate({
                where: { dealId: p.dealId, status: { not: 'CANCELLED' } },
                _sum: { amountUzs: true },
            }),
            this.categoryNames(),
            this.overhead.forProjects([p]),
            this.plannedCost(id),
        ]);
        const overheadUzs = overhead.get(p.id) ?? ZERO;
        const expenses = byCat.reduce((s, r) => s.add(dec(r._sum.amountUzs)), ZERO);
        const got = collected.get(p.dealId) ?? ZERO;
        const f = (0, domain_1.projectFinance)(p.priceUzs.toString(), expenses.toString());
        return {
            revenueUzs: money(p.priceUzs),
            collectedUzs: money(got),
            receivableUzs: money(db_1.Prisma.Decimal.max(p.priceUzs.sub(got), ZERO)),
            expensesUzs: money(expenses),
            byCategory: byCat
                .map((r) => ({
                category: r.category,
                name: names.get(r.category) ?? r.category,
                amountUzs: money(dec(r._sum.amountUzs)),
            }))
                .sort((a, b) => Number(b.amountUzs) - Number(a.amountUzs)),
            grossProfitUzs: f.grossProfit,
            marginPct: f.marginPct,
            commissionsUzs: money(dec(commissions._sum.amountUzs)),
            overheadUzs: money(overheadUzs),
            netProfitUzs: money(new db_1.Prisma.Decimal(f.grossProfit).sub(overheadUzs)),
            plannedCostUzs: money(planned),
        };
    }
    /**
     * Финансовый дашборд (ТЗ §27) за период. РОП видит показатели своего отдела,
     * CEO — компании целиком, включая расходы компании и операционную прибыль.
     */
    async summary(auth, q) {
        const { from, to } = (0, contracts_1.resolvePeriodQuery)(q);
        const deals = this.crm.dealWhere(auth, 'finance.read');
        const projectScope = this.projects.projectWhere(auth, 'finance.read');
        const company = auth.permissions['finance.company.read'] === 'ALL';
        const inRange = { gte: from, lt: to };
        // Дата расхода — календарная (без времени); границы периода — полночь по Ташкенту.
        const dateRange = {
            gte: new Date(from.getTime() + 5 * 3_600_000),
            lt: new Date(to.getTime() + 5 * 3_600_000),
        };
        const [revenue, collected, refunds, projectExp, companyExp, commissions, byCat, otherIncome, names,] = await Promise.all([
            this.prisma.contract.aggregate({
                where: { status: 'SIGNED', signedAt: inRange, deal: deals },
                _sum: { amountUzs: true },
            }),
            this.prisma.payment.aggregate({
                where: { status: 'PAID', type: { not: 'REFUND' }, paidAt: inRange, deal: deals },
                _sum: { amountUzs: true },
            }),
            this.prisma.payment.aggregate({
                where: { status: 'PAID', type: 'REFUND', paidAt: inRange, deal: deals },
                _sum: { amountUzs: true },
            }),
            this.prisma.expense.aggregate({
                where: {
                    deletedAt: null,
                    scope: 'PROJECT',
                    expenseDate: dateRange,
                    project: projectScope,
                },
                _sum: { amountUzs: true },
            }),
            company
                ? this.prisma.expense.aggregate({
                    where: { deletedAt: null, scope: 'COMPANY', expenseDate: dateRange },
                    _sum: { amountUzs: true },
                })
                : null,
            this.prisma.commission.aggregate({
                where: { status: { not: 'CANCELLED' }, createdAt: inRange, deal: deals },
                _sum: { amountUzs: true },
            }),
            this.prisma.expense.groupBy({
                by: ['category'],
                where: { AND: [this.expenses.where(auth), { expenseDate: dateRange }] },
                _sum: { amountUzs: true },
            }),
            company
                ? this.prisma.otherIncome.aggregate({
                    where: { deletedAt: null, incomeDate: dateRange },
                    _sum: { amountUzs: true },
                })
                : null,
            this.categoryNames(),
        ]);
        const f = (0, domain_1.companyFinance)({
            collected: dec(collected._sum.amountUzs).toString(),
            refunds: dec(refunds._sum.amountUzs).toString(),
            projectExpenses: dec(projectExp._sum.amountUzs).toString(),
            companyExpenses: dec(companyExp?._sum.amountUzs).toString(),
            commissions: dec(commissions._sum.amountUzs).toString(),
            otherIncome: dec(otherIncome?._sum.amountUzs).toString(),
        });
        const expensesByCategory = byCat
            .map((r) => ({
            category: r.category,
            name: names.get(r.category) ?? r.category,
            amountUzs: money(dec(r._sum.amountUzs)),
        }))
            .sort((a, b) => Number(b.amountUzs) - Number(a.amountUzs));
        return {
            from: from.toISOString(),
            to: to.toISOString(),
            revenueUzs: money(dec(revenue._sum.amountUzs)),
            collectedUzs: money(dec(collected._sum.amountUzs)),
            refundsUzs: money(dec(refunds._sum.amountUzs)),
            receivablesUzs: money(await this.receivables(deals)),
            projectExpensesUzs: money(dec(projectExp._sum.amountUzs)),
            companyExpensesUzs: company ? money(dec(companyExp?._sum.amountUzs)) : null,
            commissionsUzs: money(dec(commissions._sum.amountUzs)),
            otherIncomeUzs: company ? money(dec(otherIncome?._sum.amountUzs)) : null,
            grossProfitUzs: f.grossProfit,
            operatingProfitUzs: company ? f.operatingProfit : null,
            marginPct: f.marginPct,
            expensesByCategory,
        };
    }
    /** Дебиторка на сегодня: по каждой сделке подписано − получено (не меньше нуля). */
    async receivables(deals) {
        const signed = await this.prisma.contract.groupBy({
            by: ['dealId'],
            where: { status: 'SIGNED', deal: deals },
            _sum: { amountUzs: true },
        });
        const got = await this.collectedByDeal(signed.map((s) => s.dealId));
        return signed.reduce((s, r) => s.add(db_1.Prisma.Decimal.max(dec(r._sum.amountUzs).sub(got.get(r.dealId) ?? ZERO), ZERO)), ZERO);
    }
    /** Прибыльность проектов: выручка, получено, расходы, валовая прибыль и маржа. */
    async projectsProfit(auth, q) {
        const where = {
            AND: [
                this.projects.projectWhere(auth, 'finance.read'),
                q.q
                    ? {
                        OR: [{ name: { contains: q.q } }, { client: { name: { contains: q.q } } }],
                    }
                    : {},
            ],
        };
        const [rows, total] = await Promise.all([
            this.prisma.project.findMany({
                where,
                include: { client: { select: { id: true, name: true } } },
                orderBy: { createdAt: 'desc' },
                skip: (q.page - 1) * q.pageSize,
                take: q.pageSize,
            }),
            this.prisma.project.count({ where }),
        ]);
        const [expenses, collected, overhead] = await Promise.all([
            this.expensesByProject(rows.map((r) => r.id)),
            this.collectedByDeal(rows.map((r) => r.dealId)),
            this.overhead.forProjects(rows),
        ]);
        return {
            items: rows.map((p) => {
                const exp = expenses.get(p.id) ?? ZERO;
                const f = (0, domain_1.projectFinance)(p.priceUzs.toString(), exp.toString());
                return {
                    project: { id: p.id, name: p.name, number: (0, contracts_1.formatNumber)('P', p.number) },
                    client: p.client,
                    status: p.status,
                    revenueUzs: money(p.priceUzs),
                    collectedUzs: money(collected.get(p.dealId) ?? ZERO),
                    expensesUzs: money(exp),
                    grossProfitUzs: f.grossProfit,
                    marginPct: f.marginPct,
                    overheadUzs: money(overhead.get(p.id) ?? ZERO),
                    netProfitUzs: money(new db_1.Prisma.Decimal(f.grossProfit).sub(overhead.get(p.id) ?? ZERO)),
                };
            }),
            total,
            page: q.page,
            pageSize: q.pageSize,
        };
    }
};
exports.FinanceService = FinanceService;
exports.FinanceService = FinanceService = __decorate([
    (0, common_1.Injectable)(),
    __metadata("design:paramtypes", [prisma_service_1.PrismaService,
        project_access_service_1.ProjectAccessService,
        crm_access_service_1.CrmAccessService,
        expenses_service_1.ExpensesService,
        overhead_service_1.OverheadService,
        exchange_rate_service_1.ExchangeRateService])
], FinanceService);
//# sourceMappingURL=finance.service.js.map
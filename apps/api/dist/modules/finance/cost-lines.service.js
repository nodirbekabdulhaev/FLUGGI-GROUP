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
exports.CostLinesService = void 0;
const common_1 = require("@nestjs/common");
const contracts_1 = require("@fluggi/contracts");
const domain_1 = require("@fluggi/domain");
const app_exception_1 = require("../../core/http/app.exception");
const outbox_dispatcher_1 = require("../../core/outbox/outbox.dispatcher");
const prisma_service_1 = require("../../core/prisma/prisma.service");
const project_access_service_1 = require("../projects/project-access.service");
const expenses_service_1 = require("./expenses.service");
const include = {
    assignee: { select: { id: true, fullName: true } },
    expense: { select: { id: true, number: true, deletedAt: true } },
    workItem: { select: { defaultRate: true, currency: true } },
};
/**
 * Плановая себестоимость проекта по тарифу: из позиций тарифа (8 рилсов, 8 обложек…)
 * получаются строки «что и кому заплатить». Ставка — личная ставка назначенного исполнителя
 * (карточка сотрудника), иначе базовая. «Начислить» превращает строку в расход проекта.
 */
let CostLinesService = class CostLinesService {
    prisma;
    projects;
    expenses;
    dispatcher;
    constructor(prisma, projects, expenses, dispatcher) {
        this.prisma = prisma;
        this.projects = projects;
        this.expenses = expenses;
        this.dispatcher = dispatcher;
    }
    onModuleInit() {
        this.dispatcher.on('project.created', async (e) => {
            await this.prisma.$transaction((tx) => this.generate(tx, e.projectId));
        });
        this.dispatcher.on('project.member_added', async (e) => {
            await this.prisma.$transaction((tx) => this.assign(tx, e.projectId, e.userId));
        });
    }
    toDto(l) {
        const accrued = l.status === 'ACCRUED' && l.expense && !l.expense.deletedAt;
        return {
            id: l.id,
            kind: l.kind,
            label: l.label,
            specialty: l.specialty,
            quantity: l.quantity.toString(),
            rate: l.rate.toFixed(2),
            currency: l.currency,
            amount: l.quantity.mul(l.rate).toFixed(2),
            personalRate: l.kind === 'PIECE' && Boolean(l.workItem) && !l.rate.equals(l.workItem.defaultRate),
            assignee: l.assignee ? { id: l.assignee.id, name: l.assignee.fullName } : null,
            // Расход удалили — строку можно начислить снова
            status: l.status === 'ACCRUED' && !accrued ? 'PLANNED' : l.status,
            expense: accrued
                ? { id: l.expense.id, number: (0, contracts_1.formatNumber)('EXP', l.expense.number) }
                : null,
        };
    }
    /** Строки из тарифов принятого КП сделки проекта. Повторный вызов ничего не делает. */
    async generate(tx, projectId) {
        if (await tx.projectCostLine.count({ where: { projectId } }))
            return 0;
        const project = await tx.project.findUnique({ where: { id: projectId } });
        if (!project)
            return 0;
        const proposal = await tx.proposal.findFirst({
            where: { dealId: project.dealId, status: 'ACCEPTED' },
            orderBy: { acceptedAt: 'desc' },
            include: {
                items: {
                    where: { tariffId: { not: null } },
                    include: {
                        tariff: {
                            include: { items: { include: { workItem: true }, orderBy: { sort: 'asc' } } },
                        },
                    },
                    orderBy: { sort: 'asc' },
                },
            },
        });
        if (!proposal)
            return 0;
        const data = [];
        for (const pi of proposal.items) {
            for (const ti of pi.tariff.items) {
                const qty = ti.quantity.mul(pi.quantity);
                if (ti.kind === 'PIECE' && ti.workItem)
                    data.push({
                        projectId,
                        tariffId: pi.tariffId,
                        tariffItemId: ti.id,
                        kind: 'PIECE',
                        workItemId: ti.workItemId,
                        specialty: ti.workItem.specialty,
                        label: ti.label ?? ti.workItem.name,
                        quantity: qty,
                        rate: ti.workItem.defaultRate,
                        currency: ti.workItem.currency,
                    });
                else if (ti.kind === 'FIXED' && ti.amount)
                    data.push({
                        projectId,
                        tariffId: pi.tariffId,
                        tariffItemId: ti.id,
                        kind: 'FIXED',
                        specialty: ti.specialty,
                        label: ti.label ?? 'Оплата исполнителю',
                        quantity: pi.quantity,
                        rate: ti.amount,
                        currency: ti.currency,
                    });
            }
        }
        // Порядок строк = порядок в тарифе: у пакетной вставки одинаковое время создания
        const base = Date.now();
        if (data.length)
            await tx.projectCostLine.createMany({
                data: data.map((d, i) => ({ ...d, createdAt: new Date(base + i) })),
            });
        // Исполнители, уже назначенные в команду
        const members = await tx.projectMember.findMany({ where: { projectId, status: 'ACTIVE' } });
        for (const m of members)
            await this.assign(tx, projectId, m.userId);
        return data.length;
    }
    /** Новый исполнитель в команде: ему — плановые строки его роли, с его личными ставками. */
    async assign(tx, projectId, userId) {
        const member = await tx.projectMember.findUnique({
            where: { projectId_userId: { projectId, userId } },
        });
        if (!member?.role || member.status !== 'ACTIVE')
            return;
        const lines = await tx.projectCostLine.findMany({
            where: { projectId, status: 'PLANNED', specialty: member.role, assigneeId: null },
        });
        for (const l of lines) {
            const personal = l.kind === 'PIECE' && l.workItemId
                ? await tx.employeeRate.findUnique({
                    where: { userId_workItemId: { userId, workItemId: l.workItemId } },
                })
                : null;
            await tx.projectCostLine.update({
                where: { id: l.id },
                data: {
                    assigneeId: userId,
                    ...(personal ? { rate: personal.rate, currency: personal.currency } : {}),
                },
            });
        }
    }
    async list(auth, projectId) {
        await this.projects.project(auth, projectId, 'finance.read');
        const rows = await this.prisma.projectCostLine.findMany({
            where: { projectId, status: { not: 'CANCELLED' } },
            include,
            orderBy: { createdAt: 'asc' },
        });
        return rows.map((r) => this.toDto(r));
    }
    async line(auth, id) {
        const l = await this.prisma.projectCostLine.findUnique({ where: { id }, include });
        if (!l)
            throw (0, app_exception_1.notFound)('Строка себестоимости');
        await this.projects.project(auth, l.projectId, 'expense.update');
        return l;
    }
    async update(auth, id, input) {
        const l = await this.line(auth, id);
        if (this.toDto(l).status !== 'PLANNED')
            throw (0, app_exception_1.businessRule)('Строка уже начислена');
        if (input.assigneeId) {
            const m = await this.prisma.projectMember.findUnique({
                where: { projectId_userId: { projectId: l.projectId, userId: input.assigneeId } },
            });
            if (!m || m.status !== 'ACTIVE')
                throw (0, app_exception_1.businessRule)('Исполнитель должен быть в команде проекта');
        }
        const r = await this.prisma.projectCostLine.update({
            where: { id },
            data: {
                quantity: input.quantity,
                rate: input.rate,
                assigneeId: input.assigneeId,
                status: 'PLANNED',
                expenseId: null,
            },
            include,
        });
        return this.toDto(r);
    }
    /** Начислить исполнителю: строка становится расходом проекта (категория «Исполнитель»). */
    async accrue(auth, id, meta) {
        const l = await this.line(auth, id);
        if (this.toDto(l).status !== 'PLANNED')
            throw (0, app_exception_1.businessRule)('Строка уже начислена');
        if (!l.assigneeId)
            throw (0, app_exception_1.businessRule)('Сначала назначьте исполнителя');
        const expense = await this.expenses.create(auth, {
            scope: 'PROJECT',
            projectId: l.projectId,
            category: 'EXECUTOR',
            amount: l.quantity.mul(l.rate).toFixed(2),
            currency: l.currency,
            expenseDate: (0, domain_1.companyDate)(new Date()),
            payeeUserId: l.assigneeId,
            description: `${l.label}: ${l.quantity.toString()} × ${l.rate.toFixed(2)} ${l.currency}`,
        }, meta);
        const r = await this.prisma.projectCostLine.update({
            where: { id },
            data: { status: 'ACCRUED', expenseId: expense.id },
            include,
        });
        return this.toDto(r);
    }
    async cancel(auth, id) {
        const l = await this.line(auth, id);
        if (this.toDto(l).status !== 'PLANNED')
            throw (0, app_exception_1.businessRule)('Начисленную строку отменить нельзя — удалите расход');
        await this.prisma.projectCostLine.update({ where: { id }, data: { status: 'CANCELLED' } });
    }
};
exports.CostLinesService = CostLinesService;
exports.CostLinesService = CostLinesService = __decorate([
    (0, common_1.Injectable)(),
    __metadata("design:paramtypes", [prisma_service_1.PrismaService,
        project_access_service_1.ProjectAccessService,
        expenses_service_1.ExpensesService,
        outbox_dispatcher_1.OutboxDispatcher])
], CostLinesService);
//# sourceMappingURL=cost-lines.service.js.map
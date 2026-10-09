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
exports.CommissionsService = exports.toCommissionDto = exports.commissionInclude = void 0;
const common_1 = require("@nestjs/common");
const contracts_1 = require("@fluggi/contracts");
const audit_service_1 = require("../../core/audit/audit.service");
const app_exception_1 = require("../../core/http/app.exception");
const prisma_service_1 = require("../../core/prisma/prisma.service");
const scope_1 = require("../../core/rbac/scope");
exports.commissionInclude = {
    user: { select: { id: true, fullName: true } },
    payment: { select: { id: true, number: true } },
    deal: { select: { id: true, number: true, title: true } },
    rule: { select: { id: true, name: true } },
    approvedBy: { select: { id: true, fullName: true } },
};
const toCommissionDto = (c) => ({
    id: c.id,
    user: { id: c.user.id, name: c.user.fullName },
    role: c.role,
    payment: {
        id: c.payment.id,
        name: (0, contracts_1.formatNumber)('PAY', c.payment.number),
        number: (0, contracts_1.formatNumber)('PAY', c.payment.number),
    },
    deal: { id: c.deal.id, name: c.deal.title, number: (0, contracts_1.formatNumber)('D', c.deal.number) },
    rule: { id: c.rule.id, name: c.rule.name },
    period: c.period,
    baseAmountUzs: c.baseAmountUzs.toFixed(2),
    rate: c.rate.toString(),
    amountUzs: c.amountUzs.toFixed(2),
    status: c.status,
    approvedBy: c.approvedBy ? { id: c.approvedBy.id, name: c.approvedBy.fullName } : null,
    approvedAt: c.approvedAt?.toISOString() ?? null,
    paidAt: c.paidAt?.toISOString() ?? null,
    createdAt: c.createdAt.toISOString(),
});
exports.toCommissionDto = toCommissionDto;
let CommissionsService = class CommissionsService {
    prisma;
    audit;
    constructor(prisma, audit) {
        this.prisma = prisma;
        this.audit = audit;
    }
    /** Свои комиссии видит каждый; РОП — отдела; CEO — все (ТЗ §3, Rule 10). */
    async list(auth, q) {
        const where = {
            AND: [
                (0, scope_1.scopeWhere)(auth, 'commission.read', {
                    own: (userId) => ({ userId }),
                    team: (teamIds, userId) => ({ OR: [{ userId }, { deal: { teamId: { in: teamIds } } }] }),
                }),
                q.period ? { period: q.period } : {},
                q.userId ? { userId: q.userId } : {},
                q.status ? { status: q.status } : {},
            ],
        };
        const [items, total, agg] = await Promise.all([
            this.prisma.commission.findMany({
                where,
                include: exports.commissionInclude,
                orderBy: { createdAt: 'desc' },
                skip: (q.page - 1) * q.pageSize,
                take: q.pageSize,
            }),
            this.prisma.commission.count({ where }),
            this.prisma.commission.aggregate({
                where: { AND: [where, { status: { not: 'CANCELLED' } }] },
                _sum: { amountUzs: true },
            }),
        ]);
        return {
            items: items.map(exports.toCommissionDto),
            total,
            page: q.page,
            pageSize: q.pageSize,
            totalUzs: (agg._sum.amountUzs ?? 0).toString(),
        };
    }
    /**
     * Утверждение и выплата (ТЗ §32–33): начислена → утверждена → выплачена.
     * Только CEO (право commission.approve); каждое действие — в журнале аудита.
     */
    async transition(auth, ids, to, meta) {
        const from = to === 'APPROVED' ? 'ACCRUED' : 'APPROVED';
        return this.prisma.$transaction(async (tx) => {
            const rows = await tx.commission.findMany({ where: { id: { in: ids } } });
            if (rows.length !== ids.length)
                throw (0, app_exception_1.notFound)('Комиссия');
            const wrong = rows.find((r) => r.status !== from);
            if (wrong)
                throw (0, app_exception_1.businessRule)(to === 'APPROVED'
                    ? 'Утвердить можно только начисленные комиссии'
                    : 'Выплатить можно только утверждённые комиссии');
            const now = new Date();
            await tx.commission.updateMany({
                where: { id: { in: ids }, status: from },
                data: to === 'APPROVED'
                    ? { status: to, approvedById: auth.userId, approvedAt: now }
                    : { status: to, paidById: auth.userId, paidAt: now },
            });
            for (const r of rows)
                await this.audit.log(tx, {
                    actorId: auth.userId,
                    action: to === 'APPROVED' ? 'commission.approve' : 'commission.pay',
                    entityType: 'commission',
                    entityId: r.id,
                    changes: {
                        status: { old: from, new: to },
                        amountUzs: { old: null, new: r.amountUzs.toFixed(2) },
                    },
                    meta,
                });
            return { updated: rows.length };
        });
    }
    async rules() {
        const rows = await this.prisma.commissionRule.findMany({
            include: { commissions: { take: 0 } },
            orderBy: [{ appliesTo: 'asc' }, { priority: 'desc' }],
        });
        const users = new Map((await this.prisma.user.findMany({
            where: { id: { in: rows.map((r) => r.userId).filter((x) => !!x) } },
        })).map((u) => [u.id, u]));
        return rows.map((r) => ({
            id: r.id,
            name: r.name,
            appliesTo: r.appliesTo,
            user: r.userId && users.get(r.userId)
                ? { id: r.userId, name: users.get(r.userId).fullName }
                : null,
            calcType: r.calcType,
            value: r.value.toString(),
            conditions: r.conditions ?? null,
            priority: r.priority,
            isActive: r.isActive,
        }));
    }
    async upsertRule(auth, id, input, meta) {
        if (input.calcType !== 'FIXED_PER_DEAL' && input.value > 100)
            throw (0, app_exception_1.businessRule)('Процент не может быть больше 100');
        const data = {
            name: input.name,
            appliesTo: input.appliesTo,
            userId: input.userId ?? null,
            calcType: input.calcType,
            value: input.value,
            conditions: (input.conditions ?? undefined),
            priority: input.priority,
            isActive: input.isActive,
        };
        return this.prisma.$transaction(async (tx) => {
            const before = id ? await tx.commissionRule.findUnique({ where: { id } }) : null;
            if (id && !before)
                throw (0, app_exception_1.notFound)('Правило');
            const saved = id
                ? await tx.commissionRule.update({ where: { id }, data })
                : await tx.commissionRule.create({ data });
            await this.audit.log(tx, {
                actorId: auth.userId,
                action: id ? 'commission_rule.update' : 'commission_rule.create',
                entityType: 'commission_rule',
                entityId: saved.id,
                changes: {
                    value: { old: before?.value.toString() ?? null, new: saved.value.toString() },
                    conditions: { old: before?.conditions ?? null, new: saved.conditions ?? null },
                    isActive: { old: before?.isActive ?? null, new: saved.isActive },
                },
                meta,
            });
            return saved.id;
        });
    }
};
exports.CommissionsService = CommissionsService;
exports.CommissionsService = CommissionsService = __decorate([
    (0, common_1.Injectable)(),
    __metadata("design:paramtypes", [prisma_service_1.PrismaService,
        audit_service_1.AuditService])
], CommissionsService);
//# sourceMappingURL=commissions.service.js.map
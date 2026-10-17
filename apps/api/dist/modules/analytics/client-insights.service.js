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
exports.ClientInsightsService = void 0;
const common_1 = require("@nestjs/common");
const contracts_1 = require("@fluggi/contracts");
const domain_1 = require("@fluggi/domain");
const db_1 = require("@fluggi/db");
const serialize_1 = require("../../core/http/serialize");
const prisma_service_1 = require("../../core/prisma/prisma.service");
const crm_access_service_1 = require("../crm/crm-access.service");
const ZERO = new db_1.Prisma.Decimal(0);
const DAY = 86_400_000;
const LEVEL_ORDER = {
    RISK: 0,
    ATTENTION: 1,
    LOST: 2,
    HEALTHY: 3,
};
/**
 * LTV и «здоровье» клиентов (ТЗ §43). Считается по факту: оплаты, контакты, просрочки, проекты.
 */
let ClientInsightsService = class ClientInsightsService {
    prisma;
    crm;
    constructor(prisma, crm) {
        this.prisma = prisma;
        this.crm = crm;
    }
    /** Показатели для набора клиентов. */
    async compute(clientIds, now = new Date()) {
        const out = new Map();
        if (!clientIds.length)
            return out;
        const today = (0, serialize_1.parseDate)((0, domain_1.companyDate)(now));
        const ids = { in: clientIds };
        const [clients, payments, paidDeals, contacts, overdue, projects, openDeals, lost] = await Promise.all([
            this.prisma.client.findMany({ where: { id: ids }, select: { id: true, createdAt: true } }),
            this.prisma.payment.groupBy({
                by: ['clientId', 'type'],
                where: { clientId: ids, status: 'PAID' },
                _sum: { amountUzs: true },
                _min: { paidAt: true },
                _max: { paidAt: true },
            }),
            this.prisma.payment.groupBy({
                by: ['clientId', 'dealId'],
                where: { clientId: ids, status: 'PAID', type: { not: 'REFUND' } },
            }),
            // Последний контакт: активность по клиенту, его сделкам и лидам, проведённая встреча или оплата
            this.prisma.$queryRaw `
          SELECT client_id, max(at) AS at FROM (
            SELECT coalesce(a.client_id, d.client_id, l.client_id) AS client_id, a.created_at AS at
              FROM activities a
              LEFT JOIN deals d ON d.id = a.deal_id
              LEFT JOIN leads l ON l.id = a.lead_id
            UNION ALL
            SELECT coalesce(m.client_id, d.client_id), m.starts_at
              FROM meetings m LEFT JOIN deals d ON d.id = m.deal_id
              WHERE m.status = 'DONE'
            UNION ALL
            SELECT client_id, paid_at FROM payments WHERE status = 'PAID'
          ) x
          WHERE client_id IN (${db_1.Prisma.join(clientIds)})
          GROUP BY client_id`,
            this.prisma.payment.groupBy({
                by: ['clientId'],
                where: {
                    clientId: ids,
                    status: 'PENDING',
                    type: { not: 'REFUND' },
                    dueDate: { lt: today },
                },
                _count: true,
            }),
            this.prisma.project.findMany({
                where: { clientId: ids, deletedAt: null, status: { in: [...contracts_1.ACTIVE_PROJECT_STATUSES] } },
                select: { clientId: true, deadline: true },
            }),
            this.prisma.deal.groupBy({
                by: ['clientId'],
                where: { clientId: ids, deletedAt: null, status: 'OPEN' },
                _count: true,
            }),
            this.prisma.deal.groupBy({
                by: ['clientId'],
                where: {
                    clientId: ids,
                    status: 'LOST',
                    closedAt: { gte: (0, serialize_1.parseDate)((0, domain_1.addDays)((0, domain_1.companyDate)(now), -90)) },
                },
                _count: true,
            }),
        ]);
        const daysSince = (d) => d ? Math.max(0, Math.floor((now.getTime() - d.getTime()) / DAY)) : null;
        for (const c of clients) {
            const mine = payments.filter((p) => p.clientId === c.id);
            const plus = mine.filter((p) => p.type !== 'REFUND');
            const ltv = mine.reduce((a, p) => p.type === 'REFUND' ? a.sub(p._sum.amountUzs ?? ZERO) : a.add(p._sum.amountUzs ?? ZERO), ZERO);
            const deals = paidDeals.filter((p) => p.clientId === c.id).length;
            const first = plus
                .map((p) => p._min.paidAt)
                .filter(Boolean)
                .sort()[0] ?? null;
            const last = plus
                .map((p) => p._max.paidAt)
                .filter(Boolean)
                .sort()
                .at(-1) ?? null;
            const contact = contacts.find((x) => x.client_id === c.id)?.at ?? null;
            const active = projects.filter((p) => p.clientId === c.id);
            const health = (0, domain_1.clientHealth)({
                daysSinceContact: daysSince(contact),
                ageDays: daysSince(c.createdAt) ?? 0,
                overduePayments: overdue.find((o) => o.clientId === c.id)?._count ?? 0,
                overdueProjects: active.filter((p) => p.deadline && p.deadline < today).length,
                activeProjects: active.length,
                openDeals: openDeals.find((o) => o.clientId === c.id)?._count ?? 0,
                paidDeals: deals,
                recentLostDeals: lost.find((o) => o.clientId === c.id)?._count ?? 0,
            });
            out.set(c.id, {
                id: c.id,
                ltv: ltv.toFixed(2),
                paidDeals: deals,
                avgCheck: (deals ? ltv.div(deals) : ZERO).toFixed(2),
                firstPaymentAt: first?.toISOString() ?? null,
                lastPaymentAt: last?.toISOString() ?? null,
                lastContactAt: contact ? new Date(contact).toISOString() : null,
                health,
            });
        }
        return out;
    }
    async list(auth, q) {
        const clients = await this.prisma.client.findMany({
            where: this.crm.clientWhere(auth, 'analytics.read'),
            select: { id: true, name: true, owner: { select: { id: true, fullName: true } } },
            take: 5000,
        });
        const metrics = await this.compute(clients.map((c) => c.id));
        const all = clients.map((c) => ({
            ...metrics.get(c.id),
            name: c.name,
            owner: { id: c.owner.id, name: c.owner.fullName },
        }));
        const byHealth = Object.fromEntries(contracts_1.CLIENT_HEALTH_LEVELS.map((l) => [l, 0]));
        for (const c of all)
            byHealth[c.health.level] += 1;
        const paying = all.filter((c) => c.paidDeals > 0);
        const sorted = all
            .filter((c) => !q.health || c.health.level === q.health)
            .sort((a, b) => q.sort === 'health'
            ? LEVEL_ORDER[a.health.level] - LEVEL_ORDER[b.health.level] ||
                a.health.score - b.health.score
            : q.sort === 'lastPayment'
                ? (b.lastPaymentAt ?? '').localeCompare(a.lastPaymentAt ?? '')
                : Number(b.ltv) - Number(a.ltv));
        return {
            items: sorted.slice((q.page - 1) * q.pageSize, q.page * q.pageSize),
            total: sorted.length,
            page: q.page,
            pageSize: q.pageSize,
            summary: {
                clients: all.length,
                avgLtv: (paying.length
                    ? paying.reduce((a, c) => a.add(c.ltv), ZERO).div(paying.length)
                    : ZERO).toFixed(2),
                repeatRate: paying.length
                    ? Math.round((paying.filter((c) => c.paidDeals >= 2).length / paying.length) * 1000) / 10
                    : 0,
                byHealth,
            },
        };
    }
    /** Показатели одного клиента (карточка клиента). */
    async one(auth, id) {
        const c = await this.crm.client(auth, id);
        const m = (await this.compute([c.id])).get(c.id);
        return m;
    }
    /**
     * Пересчёт уровня здоровья всех клиентов (задача планировщика). Возвращает клиентов,
     * которые только что перешли в «Риск», — их менеджеру уходит уведомление.
     */
    async refreshAll(now = new Date()) {
        const clients = await this.prisma.client.findMany({
            where: { deletedAt: null },
            select: { id: true, name: true, ownerId: true, health: true },
        });
        const metrics = await this.compute(clients.map((c) => c.id), now);
        const toRisk = [];
        let changed = 0;
        for (const c of clients) {
            const h = metrics.get(c.id).health;
            if (h.level === c.health)
                continue;
            await this.prisma.client.update({ where: { id: c.id }, data: { health: h.level } });
            changed += 1;
            if (h.level === 'RISK')
                toRisk.push({ id: c.id, name: c.name, ownerId: c.ownerId, reasons: h.reasons });
        }
        return { changed, toRisk };
    }
};
exports.ClientInsightsService = ClientInsightsService;
exports.ClientInsightsService = ClientInsightsService = __decorate([
    (0, common_1.Injectable)(),
    __metadata("design:paramtypes", [prisma_service_1.PrismaService,
        crm_access_service_1.CrmAccessService])
], ClientInsightsService);
//# sourceMappingURL=client-insights.service.js.map
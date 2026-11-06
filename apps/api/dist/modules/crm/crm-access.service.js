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
exports.CrmAccessService = exports.ownership = void 0;
const common_1 = require("@nestjs/common");
const app_exception_1 = require("../../core/http/app.exception");
const prisma_service_1 = require("../../core/prisma/prisma.service");
const scope_1 = require("../../core/rbac/scope");
/** Записи CRM принадлежат менеджеру (ownerId) и отделу (teamId). */
exports.ownership = {
    own: (userId) => ({ ownerId: userId }),
    team: (teamIds, userId) => ({
        OR: [{ teamId: { in: teamIds } }, { ownerId: userId }],
    }),
};
/**
 * Единая точка разграничения данных CRM (ТЗ §65): каждая выборка лидов, сделок,
 * клиентов и встреч проходит через эти фильтры.
 */
let CrmAccessService = class CrmAccessService {
    prisma;
    constructor(prisma) {
        this.prisma = prisma;
    }
    leadWhere(auth, code = 'lead.read') {
        return { deletedAt: null, ...(0, scope_1.scopeWhere)(auth, code, exports.ownership) };
    }
    dealWhere(auth, code = 'deal.read') {
        return { deletedAt: null, ...(0, scope_1.scopeWhere)(auth, code, exports.ownership) };
    }
    clientWhere(auth, code = 'client.read') {
        return { deletedAt: null, ...(0, scope_1.scopeWhere)(auth, code, exports.ownership) };
    }
    meetingWhere(auth, code = 'meeting.read') {
        return (0, scope_1.scopeWhere)(auth, code, {
            own: (userId) => ({ managerId: userId }),
            team: (teamIds, userId) => ({
                OR: [{ teamId: { in: teamIds } }, { managerId: userId }, { ropId: userId }],
            }),
        });
    }
    async lead(auth, id, code = 'lead.read', tx = this.prisma) {
        const lead = await tx.lead.findFirst({ where: { AND: [this.leadWhere(auth, code), { id }] } });
        if (!lead)
            throw (0, app_exception_1.notFound)('Лид');
        return lead;
    }
    async deal(auth, id, code = 'deal.read', tx = this.prisma) {
        const deal = await tx.deal.findFirst({ where: { AND: [this.dealWhere(auth, code), { id }] } });
        if (!deal)
            throw (0, app_exception_1.notFound)('Сделка');
        return deal;
    }
    async client(auth, id, code = 'client.read', tx = this.prisma) {
        const client = await tx.client.findFirst({
            where: { AND: [this.clientWhere(auth, code), { id }] },
        });
        if (!client)
            throw (0, app_exception_1.notFound)('Клиент');
        return client;
    }
    /**
     * Новый ответственный должен быть в зоне видимости назначающего:
     * менеджер — только себя, РОП — свой отдел, CEO — любого активного менеджера/РОП.
     */
    /**
     * Ответственный за лид, сделку, клиента — только менеджер или РОП (CEO получает уведомления).
     * Если ответственный не указан, а создаёт не менеджер/РОП (CEO, HR) — назначается менеджер
     * с наименьшим числом открытых лидов.
     */
    async assignableOwner(auth, ownerId, code) {
        const targetId = ownerId ??
            (OWNER_ROLES.includes(auth.roleCode) ? auth.userId : await this.leastLoadedOwner());
        const scope = auth.permissions[code];
        const user = await this.prisma.user.findFirst({
            where: { id: targetId, deletedAt: null, status: 'ACTIVE' },
            include: { role: true },
        });
        if (user && !OWNER_ROLES.includes(user.role.code))
            throw (0, app_exception_1.businessRule)('Ответственным может быть только менеджер или РОП', [
                { path: 'ownerId', message: 'Выберите менеджера или РОП' },
            ]);
        const allowedRole = Boolean(user);
        const inScope = scope === 'ALL' ||
            targetId === auth.userId ||
            (scope === 'TEAM' &&
                !!user?.teamId &&
                [...auth.headedTeamIds, auth.teamId].includes(user.teamId));
        if (!user || !allowedRole || !inScope) {
            throw (0, app_exception_1.notFound)('Ответственный');
        }
        return user;
    }
    /** Менеджер с наименьшим числом открытых лидов; если менеджеров нет — РОП. */
    async leastLoadedOwner(teamId) {
        for (const role of ['MANAGER', 'ROP']) {
            const users = await this.prisma.user.findMany({
                where: {
                    status: 'ACTIVE',
                    deletedAt: null,
                    role: { code: role },
                    ...(teamId ? { teamId } : {}),
                },
                select: {
                    id: true,
                    _count: { select: { ownedLeads: { where: { status: 'OPEN', deletedAt: null } } } },
                },
            });
            if (users.length) {
                users.sort((a, b) => a._count.ownedLeads - b._count.ownedLeads || a.id.localeCompare(b.id));
                return users[0].id;
            }
        }
        if (teamId)
            return this.leastLoadedOwner(null);
        throw (0, app_exception_1.businessRule)('Нет менеджеров и РОП — добавьте сотрудника, чтобы назначать лиды');
    }
};
exports.CrmAccessService = CrmAccessService;
exports.CrmAccessService = CrmAccessService = __decorate([
    (0, common_1.Injectable)(),
    __metadata("design:paramtypes", [prisma_service_1.PrismaService])
], CrmAccessService);
const OWNER_ROLES = ['MANAGER', 'ROP'];
//# sourceMappingURL=crm-access.service.js.map
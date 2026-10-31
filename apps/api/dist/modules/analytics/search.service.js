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
exports.SearchService = void 0;
exports.parseNumber = parseNumber;
const common_1 = require("@nestjs/common");
const contracts_1 = require("@fluggi/contracts");
const scope_1 = require("../../core/rbac/scope");
const prisma_service_1 = require("../../core/prisma/prisma.service");
const crm_access_service_1 = require("../crm/crm-access.service");
const project_access_service_1 = require("../projects/project-access.service");
const LIMIT = 5;
// В MySQL сравнение строк без учёта регистра (collation utf8mb4_unicode_ci)
const ci = (q) => ({ contains: q });
/** «D-00012», «ДГ-12», «12» → 12; иначе null. */
function parseNumber(q) {
    const m = /^(?:[a-zа-я]{1,3}\s*-?\s*)?0*(\d{1,9})$/i.exec(q.trim());
    return m ? Number(m[1]) : null;
}
/**
 * Глобальный поиск (ТЗ §44): клиенты, лиды, сделки, проекты, договоры, задачи, сотрудники.
 * Каждая сущность ищется только в пределах прав пользователя; без права — пропускается.
 */
let SearchService = class SearchService {
    prisma;
    crm;
    projects;
    constructor(prisma, crm, projects) {
        this.prisma = prisma;
        this.crm = crm;
        this.projects = projects;
    }
    async search(auth, q) {
        const n = parseNumber(q);
        const num = n !== null ? [{ number: n }] : [];
        const can = (code) => Boolean(auth.permissions[code]);
        const phone = q.replace(/[^\d+]/g, '');
        const phoneOr = phone.length >= 5 ? [{ phone: { contains: phone } }] : [];
        const tasks = [];
        if (can('client.read'))
            tasks.push(this.prisma.client
                .findMany({
                where: {
                    AND: [
                        this.crm.clientWhere(auth),
                        {
                            OR: [{ name: ci(q) }, { email: ci(q) }, { telegram: ci(q) }, ...phoneOr, ...num],
                        },
                    ],
                },
                select: { id: true, number: true, name: true, phone: true },
                take: LIMIT,
                orderBy: { updatedAt: 'desc' },
            })
                .then((rows) => rows.map((r) => ({
                kind: 'client',
                id: r.id,
                title: r.name,
                subtitle: [(0, contracts_1.formatNumber)('C', r.number), r.phone].filter(Boolean).join(' · '),
                link: `/clients/${r.id}`,
            }))));
        if (can('lead.read'))
            tasks.push(this.prisma.lead
                .findMany({
                where: {
                    AND: [
                        this.crm.leadWhere(auth),
                        {
                            OR: [
                                { title: ci(q) },
                                { contactName: ci(q) },
                                { companyName: ci(q) },
                                { email: ci(q) },
                                { telegram: ci(q) },
                                ...phoneOr,
                                ...num,
                            ],
                        },
                    ],
                },
                select: { id: true, number: true, title: true, contactName: true, phone: true },
                take: LIMIT,
                orderBy: { updatedAt: 'desc' },
            })
                .then((rows) => rows.map((r) => ({
                kind: 'lead',
                id: r.id,
                title: r.title,
                subtitle: [(0, contracts_1.formatNumber)('L', r.number), r.contactName, r.phone]
                    .filter(Boolean)
                    .join(' · '),
                link: `/sales/leads/${r.id}`,
            }))));
        if (can('deal.read'))
            tasks.push(this.prisma.deal
                .findMany({
                where: {
                    AND: [
                        this.crm.dealWhere(auth),
                        { OR: [{ title: ci(q) }, { client: { name: ci(q) } }, ...num] },
                    ],
                },
                select: { id: true, number: true, title: true, client: { select: { name: true } } },
                take: LIMIT,
                orderBy: { updatedAt: 'desc' },
            })
                .then((rows) => rows.map((r) => ({
                kind: 'deal',
                id: r.id,
                title: r.title,
                subtitle: `${(0, contracts_1.formatNumber)('D', r.number)} · ${r.client.name}`,
                link: `/sales/deals/${r.id}`,
            }))));
        if (can('project.read'))
            tasks.push(this.prisma.project
                .findMany({
                where: {
                    AND: [
                        this.projects.projectWhere(auth),
                        { OR: [{ name: ci(q) }, { client: { name: ci(q) } }, ...num] },
                    ],
                },
                select: { id: true, number: true, name: true, client: { select: { name: true } } },
                take: LIMIT,
                orderBy: { updatedAt: 'desc' },
            })
                .then((rows) => rows.map((r) => ({
                kind: 'project',
                id: r.id,
                title: r.name,
                subtitle: `${(0, contracts_1.formatNumber)('P', r.number)} · ${r.client.name}`,
                link: `/projects/${r.id}`,
            }))));
        if (can('contract.read'))
            tasks.push(this.prisma.contract
                .findMany({
                where: {
                    AND: [
                        { deal: this.crm.dealWhere(auth, 'contract.read') },
                        { OR: [...num, { deal: { client: { name: ci(q) } } }] },
                    ],
                },
                select: {
                    id: true,
                    number: true,
                    dealId: true,
                    status: true,
                    deal: { select: { client: { select: { name: true } } } },
                },
                take: LIMIT,
                orderBy: { createdAt: 'desc' },
            })
                .then((rows) => rows.map((r) => ({
                kind: 'contract',
                id: r.id,
                title: `Договор ${(0, contracts_1.formatNumber)('C', r.number).replace(/^C-/, 'ДГ-')}`,
                subtitle: r.deal.client.name,
                link: `/sales/deals/${r.dealId}`,
            }))));
        if (can('task.read'))
            tasks.push(this.prisma.task
                .findMany({
                where: { AND: [this.projects.taskWhere(auth), { OR: [{ title: ci(q) }, ...num] }] },
                select: {
                    id: true,
                    number: true,
                    title: true,
                    project: { select: { id: true, name: true } },
                },
                take: LIMIT,
                orderBy: { updatedAt: 'desc' },
            })
                .then((rows) => rows.map((r) => ({
                kind: 'task',
                id: r.id,
                title: r.title,
                subtitle: `${(0, contracts_1.formatNumber)('T', r.number)} · ${r.project.name}`,
                link: `/projects/${r.project.id}?task=${r.id}`,
            }))));
        if (can('employee.read'))
            tasks.push(this.prisma.user
                .findMany({
                where: {
                    AND: [
                        { deletedAt: null },
                        (0, scope_1.scopeWhere)(auth, 'employee.read', {
                            own: (userId) => ({ id: userId }),
                            team: (teamIds, userId) => ({
                                OR: [{ teamId: { in: teamIds } }, { id: userId }],
                            }),
                        }),
                        { OR: [{ fullName: ci(q) }, { email: ci(q) }] },
                    ],
                },
                select: { id: true, fullName: true, email: true, role: { select: { name: true } } },
                take: LIMIT,
            })
                .then((rows) => rows.map((r) => ({
                kind: 'user',
                id: r.id,
                title: r.fullName,
                subtitle: `${r.role.name} · ${r.email}`,
                link: `/team/employees?q=${encodeURIComponent(r.email)}`,
            }))));
        return (await Promise.all(tasks)).flat();
    }
};
exports.SearchService = SearchService;
exports.SearchService = SearchService = __decorate([
    (0, common_1.Injectable)(),
    __metadata("design:paramtypes", [prisma_service_1.PrismaService,
        crm_access_service_1.CrmAccessService,
        project_access_service_1.ProjectAccessService])
], SearchService);
//# sourceMappingURL=search.service.js.map
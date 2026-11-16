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
exports.TodosService = exports.todoInclude = void 0;
const common_1 = require("@nestjs/common");
const contracts_1 = require("@fluggi/contracts");
const app_exception_1 = require("../../core/http/app.exception");
const prisma_service_1 = require("../../core/prisma/prisma.service");
const crm_access_service_1 = require("../crm/crm-access.service");
const notifications_service_1 = require("../notifications/notifications.service");
exports.todoInclude = {
    owner: { select: { id: true, fullName: true } },
    creator: { select: { id: true, fullName: true } },
    client: { select: { id: true, name: true } },
    deal: { select: { id: true, number: true, title: true } },
    lead: { select: { id: true, number: true, title: true } },
};
/** Все дела видит только тот, у кого задачи на всю компанию (CEO). */
const seesAll = (auth) => auth.permissions['task.read'] === 'ALL';
/** Отделы, дела которых видит РОП (task.read на уровне отдела). */
const teamScope = (auth) => auth.permissions['task.read'] === 'TEAM'
    ? auth.headedTeamIds.length
        ? auth.headedTeamIds
        : auth.teamId
            ? [auth.teamId]
            : []
    : [];
/**
 * Личные дела (ТЗ: «задачи не только по проектам»): звонки, письма, отчёты, платежи.
 * Видит и меняет дело исполнитель и тот, кто поручил; CEO — все.
 */
let TodosService = class TodosService {
    prisma;
    crm;
    notifications;
    constructor(prisma, crm, notifications) {
        this.prisma = prisma;
        this.crm = crm;
        this.notifications = notifications;
    }
    toDto(t, auth, now = new Date()) {
        return {
            id: t.id,
            number: (0, contracts_1.formatNumber)('TD', t.number),
            title: t.title,
            description: t.description,
            kind: t.kind,
            priority: t.priority,
            status: t.status,
            dueAt: t.dueAt?.toISOString() ?? null,
            overdue: t.status === 'OPEN' && Boolean(t.dueAt && t.dueAt < now),
            owner: { id: t.owner.id, name: t.owner.fullName },
            creator: { id: t.creator.id, name: t.creator.fullName },
            client: t.client,
            deal: t.deal
                ? { id: t.deal.id, number: (0, contracts_1.formatNumber)('D', t.deal.number), name: t.deal.title }
                : null,
            lead: t.lead
                ? { id: t.lead.id, number: (0, contracts_1.formatNumber)('L', t.lead.number), name: t.lead.title }
                : null,
            recurring: Boolean(t.recurringId),
            completedAt: t.completedAt?.toISOString() ?? null,
            createdAt: t.createdAt.toISOString(),
            can: { update: this.canEdit(auth, t) },
        };
    }
    canEdit(auth, t) {
        return t.ownerId === auth.userId || t.creatorId === auth.userId || seesAll(auth);
    }
    /** Поручить дело можно себе; другим — в пределах права task.create (РОП — отделу, CEO — всем). */
    async assertAssignable(auth, ownerId) {
        if (ownerId === auth.userId)
            return;
        const scope = auth.permissions['task.create'];
        const user = await this.prisma.user.findFirst({
            where: { id: ownerId, status: 'ACTIVE', deletedAt: null },
            select: { teamId: true },
        });
        if (!user)
            throw (0, app_exception_1.notFound)('Сотрудник');
        const teams = [...auth.headedTeamIds, ...(auth.teamId ? [auth.teamId] : [])];
        if (scope === 'ALL' || (scope === 'TEAM' && user.teamId && teams.includes(user.teamId)))
            return;
        throw (0, app_exception_1.forbidden)('Поручать дела можно только себе и своему отделу');
    }
    /** Связь с клиентом / сделкой / лидом — только с теми, что пользователь видит. */
    async links(auth, input) {
        const out = {};
        if (input.dealId) {
            const deal = await this.crm.deal(auth, input.dealId);
            out.dealId = deal.id;
            out.clientId = deal.clientId;
        }
        else if (input.dealId === null)
            out.dealId = null;
        if (input.clientId)
            out.clientId = (await this.crm.client(auth, input.clientId)).id;
        else if (input.clientId === null && !input.dealId)
            out.clientId = null;
        if (input.leadId)
            out.leadId = (await this.crm.lead(auth, input.leadId)).id;
        else if (input.leadId === null)
            out.leadId = null;
        return out;
    }
    async row(auth, id) {
        const t = await this.prisma.todo.findFirst({
            where: { id, deletedAt: null },
            include: exports.todoInclude,
        });
        if (!t || !this.canEdit(auth, t))
            throw (0, app_exception_1.notFound)('Дело');
        return t;
    }
    async list(auth, q) {
        const and = [{ deletedAt: null }];
        // «Все»: CEO — вся компания, РОП — сотрудники своего отдела
        if (q.view === 'all' && !seesAll(auth)) {
            const teams = teamScope(auth);
            if (!teams.length)
                throw (0, app_exception_1.forbidden)();
            and.push({ OR: [{ owner: { teamId: { in: teams } } }, { ownerId: auth.userId }] });
        }
        if (q.view === 'mine')
            and.push({ ownerId: auth.userId });
        if (q.view === 'assigned')
            and.push({ creatorId: auth.userId, ownerId: { not: auth.userId } });
        if (q.status)
            and.push({ status: q.status });
        if (q.clientId)
            and.push({ clientId: q.clientId });
        if (q.dealId)
            and.push({ dealId: q.dealId });
        const where = { AND: and };
        const [rows, total] = await Promise.all([
            this.prisma.todo.findMany({
                where,
                include: exports.todoInclude,
                orderBy: [
                    { status: 'asc' },
                    { dueAt: { sort: 'asc', nulls: 'last' } },
                    { createdAt: 'desc' },
                ],
                skip: (q.page - 1) * q.pageSize,
                take: q.pageSize,
            }),
            this.prisma.todo.count({ where }),
        ]);
        return {
            items: rows.map((r) => this.toDto(r, auth)),
            total,
            page: q.page,
            pageSize: q.pageSize,
        };
    }
    /** Панель «Список дел»: только мои открытые личные дела (задачи проектов — в разделе «Задачи»). */
    async dock(auth) {
        const now = new Date();
        const todos = await this.prisma.todo.findMany({
            where: { ownerId: auth.userId, status: 'OPEN', deletedAt: null },
            include: exports.todoInclude,
            orderBy: [{ dueAt: { sort: 'asc', nulls: 'last' } }, { createdAt: 'desc' }],
            take: 200,
        });
        return { todos: todos.map((t) => this.toDto(t, auth, now)) };
    }
    async create(auth, input) {
        const ownerId = input.ownerId ?? auth.userId;
        await this.assertAssignable(auth, ownerId);
        const links = await this.links(auth, input);
        const t = await this.prisma.todo.create({
            data: {
                title: input.title,
                description: input.description ?? null,
                kind: input.kind,
                priority: input.priority,
                dueAt: input.dueAt ? new Date(input.dueAt) : null,
                ownerId,
                creatorId: auth.userId,
                ...links,
            },
            include: exports.todoInclude,
        });
        if (ownerId !== auth.userId)
            await this.notifications.notify([ownerId], {
                type: 'todo.assigned',
                title: `${t.creator.fullName} поручил дело`,
                body: t.title,
                link: '/todos',
            });
        return this.toDto(t, auth);
    }
    async update(auth, id, input) {
        const before = await this.row(auth, id);
        if (input.ownerId && input.ownerId !== before.ownerId)
            await this.assertAssignable(auth, input.ownerId);
        const links = await this.links(auth, input);
        const t = await this.prisma.todo.update({
            where: { id },
            data: {
                title: input.title,
                description: input.description,
                kind: input.kind,
                priority: input.priority,
                dueAt: input.dueAt === undefined ? undefined : input.dueAt ? new Date(input.dueAt) : null,
                ownerId: input.ownerId,
                ...links,
            },
            include: exports.todoInclude,
        });
        if (input.ownerId && input.ownerId !== before.ownerId && input.ownerId !== auth.userId)
            await this.notifications.notify([input.ownerId], {
                type: 'todo.assigned',
                title: 'Вам передано дело',
                body: t.title,
                link: '/todos',
            });
        return this.toDto(t, auth);
    }
    async setStatus(auth, id, status) {
        const before = await this.row(auth, id);
        if (before.status === status)
            throw (0, app_exception_1.businessRule)('Статус уже установлен');
        const t = await this.prisma.todo.update({
            where: { id },
            data: { status, completedAt: status === 'DONE' ? new Date() : null },
            include: exports.todoInclude,
        });
        // Поручившему — что дело выполнено
        if (status === 'DONE' && t.creatorId !== auth.userId)
            await this.notifications.notify([t.creatorId], {
                type: 'todo.assigned',
                title: `${t.owner.fullName} выполнил дело`,
                body: t.title,
                link: '/todos?view=assigned',
            });
        return this.toDto(t, auth);
    }
    async remove(auth, id) {
        const t = await this.row(auth, id);
        if (t.creatorId !== auth.userId && !seesAll(auth))
            throw (0, app_exception_1.forbidden)('Удалить дело может тот, кто его создал');
        await this.prisma.todo.update({ where: { id }, data: { deletedAt: new Date() } });
    }
};
exports.TodosService = TodosService;
exports.TodosService = TodosService = __decorate([
    (0, common_1.Injectable)(),
    __metadata("design:paramtypes", [prisma_service_1.PrismaService,
        crm_access_service_1.CrmAccessService,
        notifications_service_1.NotificationsService])
], TodosService);
//# sourceMappingURL=todos.service.js.map
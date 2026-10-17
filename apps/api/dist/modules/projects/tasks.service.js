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
exports.TasksService = void 0;
const common_1 = require("@nestjs/common");
const contracts_1 = require("@fluggi/contracts");
const domain_1 = require("@fluggi/domain");
const audit_service_1 = require("../../core/audit/audit.service");
const app_exception_1 = require("../../core/http/app.exception");
const serialize_1 = require("../../core/http/serialize");
const outbox_service_1 = require("../../core/outbox/outbox.service");
const prisma_service_1 = require("../../core/prisma/prisma.service");
const activity_service_1 = require("../crm/activity.service");
const project_access_service_1 = require("./project-access.service");
const projects_service_1 = require("./projects.service");
const include = {
    project: { select: { id: true, number: true, name: true, ropId: true, managerId: true } },
    assignee: { select: { id: true, fullName: true } },
    creator: { select: { id: true, fullName: true } },
    _count: { select: { comments: { where: { deletedAt: null } } } },
};
const task = (n) => (0, contracts_1.formatNumber)('T', n);
let TasksService = class TasksService {
    prisma;
    access;
    activity;
    audit;
    outbox;
    constructor(prisma, access, activity, audit, outbox) {
        this.prisma = prisma;
        this.access = access;
        this.activity = activity;
        this.audit = audit;
        this.outbox = outbox;
    }
    /** Проекты, где пользователь управляет задачами (право task.create над проектом). */
    async managedProjectIds(auth, projectIds) {
        if (!auth.permissions['task.create'] || projectIds.length === 0)
            return new Set();
        const rows = await this.prisma.project.findMany({
            where: { AND: [this.access.projectWhere(auth, 'task.create'), { id: { in: projectIds } }] },
            select: { id: true },
        });
        return new Set(rows.map((r) => r.id));
    }
    toDto(t, canEdit, now) {
        return {
            id: t.id,
            number: task(t.number),
            project: {
                id: t.project.id,
                name: t.project.name,
                number: (0, contracts_1.formatNumber)('P', t.project.number),
            },
            title: t.title,
            description: t.description,
            assignee: { id: t.assignee.id, name: t.assignee.fullName },
            waitingForRole: Boolean(t.templateRole) && t.assigneeId === t.project.ropId && t.status === 'TODO',
            creator: { id: t.creator.id, name: t.creator.fullName },
            templateRole: t.templateRole,
            priority: t.priority,
            status: t.status,
            startDate: (0, serialize_1.dateOnly)(t.startDate),
            deadline: (0, serialize_1.iso)(t.deadline),
            overdueDays: (0, domain_1.taskOverdueDays)(t.deadline, contracts_1.OPEN_TASK_STATUSES.includes(t.status), now),
            progressPct: t.progressPct,
            sortOrder: t.sortOrder,
            reworkCount: t.reworkCount,
            startedAt: (0, serialize_1.iso)(t.startedAt),
            completedAt: (0, serialize_1.iso)(t.completedAt),
            commentsCount: t._count.comments,
            createdAt: t.createdAt.toISOString(),
            canEdit,
        };
    }
    async toDtos(auth, rows) {
        const now = new Date();
        const managed = await this.managedProjectIds(auth, [...new Set(rows.map((r) => r.projectId))]);
        return rows.map((r) => this.toDto(r, managed.has(r.projectId) || r.creatorId === auth.userId, now));
    }
    async list(auth, q) {
        const now = new Date();
        const and = [this.access.taskWhere(auth)];
        if (q.projectId)
            and.push({ projectId: q.projectId });
        if (q.assigneeId)
            and.push({ assigneeId: q.assigneeId });
        if (q.mine)
            and.push({ assigneeId: auth.userId });
        if (q.status)
            and.push({ status: q.status });
        if (q.q)
            and.push({ title: { contains: q.q } });
        switch (q.view) {
            case 'today': {
                // Сегодня: дедлайн до конца дня (включая просроченные) или задача начинается сегодня.
                const today = (0, domain_1.companyDate)(now);
                const tomorrow = new Date((0, domain_1.companyDayStart)(today).getTime() + 86_400_000);
                and.push({
                    status: { in: [...contracts_1.OPEN_TASK_STATUSES] },
                    OR: [{ deadline: { lt: tomorrow } }, { startDate: (0, serialize_1.parseDate)(today) }],
                });
                break;
            }
            case 'overdue':
                and.push((0, projects_service_1.overdueTaskWhere)(now));
                break;
            case 'in_progress':
                and.push({ status: 'IN_PROGRESS' });
                break;
            case 'review':
                and.push({ status: 'REVIEW' });
                break;
            case 'done':
                and.push({ status: 'DONE' });
                break;
            default:
                if (!q.status && !q.projectId)
                    and.push({ status: { not: 'CANCELLED' } });
        }
        const where = { AND: and };
        const [rows, total] = await Promise.all([
            this.prisma.task.findMany({
                where,
                include,
                orderBy: q.projectId
                    ? [{ sortOrder: 'asc' }]
                    : q.view === 'done'
                        ? [{ completedAt: 'desc' }]
                        : [{ deadline: { sort: 'asc', nulls: 'last' } }, { createdAt: 'asc' }],
                skip: (q.page - 1) * q.pageSize,
                take: q.pageSize,
            }),
            this.prisma.task.count({ where }),
        ]);
        return { items: await this.toDtos(auth, rows), total, page: q.page, pageSize: q.pageSize };
    }
    async get(auth, id) {
        await this.access.task(auth, id);
        const [row, comments, history] = await Promise.all([
            this.prisma.task.findUniqueOrThrow({ where: { id }, include }),
            this.prisma.taskComment.findMany({
                where: { taskId: id, deletedAt: null },
                include: { author: { select: { id: true, fullName: true } } },
                orderBy: { createdAt: 'asc' },
            }),
            this.prisma.taskStatusHistory.findMany({
                where: { taskId: id },
                include: { changedBy: { select: { id: true, fullName: true } } },
                orderBy: { createdAt: 'desc' },
            }),
        ]);
        const [dto] = await this.toDtos(auth, [row]);
        return {
            ...dto,
            comments: comments.map((c) => ({
                id: c.id,
                author: { id: c.author.id, name: c.author.fullName },
                body: c.body,
                createdAt: c.createdAt.toISOString(),
            })),
            history: history.map((h) => ({
                id: h.id,
                from: h.fromStatus,
                to: h.toStatus,
                changedBy: { id: h.changedBy.id, name: h.changedBy.fullName },
                createdAt: h.createdAt.toISOString(),
            })),
        };
    }
    assertProjectOpen(p) {
        if (!contracts_1.ACTIVE_PROJECT_STATUSES.includes(p.status))
            throw (0, app_exception_1.businessRule)('Проект закрыт — задачи нельзя менять');
    }
    /** Ответственный — участник команды, РОП или менеджер проекта. */
    async assertAssignee(p, userId) {
        if (!userId || userId === p.ropId || userId === p.managerId)
            return;
        const m = await this.prisma.projectMember.findFirst({
            where: { projectId: p.id, userId, status: 'ACTIVE' },
        });
        if (!m)
            throw (0, app_exception_1.businessRule)('Ответственный должен быть в команде проекта');
    }
    async create(auth, input, meta) {
        const p = await this.access.project(auth, input.projectId, 'task.create');
        this.assertProjectOpen(p);
        await this.assertAssignee(p, input.assigneeId);
        const deadline = input.deadline ? new Date(input.deadline) : null;
        const id = await this.prisma.$transaction(async (tx) => {
            const last = await tx.task.aggregate({
                where: { projectId: p.id, status: 'TODO', deletedAt: null },
                _max: { sortOrder: true },
            });
            const t = await tx.task.create({
                data: {
                    projectId: p.id,
                    title: input.title,
                    description: input.description ?? null,
                    assigneeId: input.assigneeId,
                    creatorId: auth.userId,
                    priority: input.priority,
                    startDate: (0, serialize_1.parseDate)(input.startDate) ?? null,
                    deadline,
                    sortOrder: (0, domain_1.sortBetween)(last._max.sortOrder, null),
                },
            });
            await tx.taskStatusHistory.create({
                data: { taskId: t.id, toStatus: 'TODO', changedById: auth.userId },
            });
            await this.activity.log(tx, {
                type: 'task.created',
                actorId: auth.userId,
                projectId: p.id,
                taskId: t.id,
                payload: { number: task(t.number), title: t.title },
            });
            await this.audit.log(tx, {
                actorId: auth.userId,
                action: 'task.create',
                entityType: 'task',
                entityId: t.id,
                changes: {
                    title: { old: null, new: t.title },
                    assigneeId: { old: null, new: t.assigneeId },
                },
                meta,
            });
            if (t.assigneeId !== auth.userId)
                await this.outbox.publish(tx, 'task.assigned', { taskId: t.id, assigneeId: t.assigneeId }, auth.userId);
            return t.id;
        });
        return this.get(auth, id);
    }
    /**
     * Поля задачи меняет тот, кто управляет задачами проекта, или автор задачи.
     * Ответственный может менять только процент выполнения (и статус — через move).
     */
    async update(auth, id, input, meta) {
        const t = await this.access.task(auth, id, 'task.update');
        this.assertProjectOpen(t.project);
        const canEdit = await this.canEdit(auth, t);
        const onlyProgress = Object.keys(input).every((k) => k === 'progressPct');
        if (!canEdit && !(onlyProgress && t.assigneeId === auth.userId))
            throw (0, app_exception_1.forbidden)();
        if (input.assigneeId !== undefined)
            await this.assertAssignee(t.project, input.assigneeId);
        const deadline = input.deadline !== undefined ? (input.deadline ? new Date(input.deadline) : null) : undefined;
        const data = {
            title: input.title,
            description: input.description,
            assigneeId: input.assigneeId,
            priority: input.priority,
            startDate: input.startDate !== undefined ? (0, serialize_1.parseDate)(input.startDate) : undefined,
            deadline,
            progressPct: input.progressPct,
        };
        // Новый дедлайн — напоминания о просрочке начинаются заново.
        if (deadline !== undefined && deadline?.getTime() !== t.deadline?.getTime())
            data.overdueNotifiedAt = null;
        await this.prisma.$transaction(async (tx) => {
            await tx.task.update({ where: { id }, data });
            const changes = (0, audit_service_1.diffFields)(t, data, [
                'title',
                'description',
                'assigneeId',
                'priority',
                'startDate',
                'deadline',
                'progressPct',
            ]);
            if (!changes)
                return;
            await this.audit.log(tx, {
                actorId: auth.userId,
                action: 'task.update',
                entityType: 'task',
                entityId: id,
                changes,
                meta,
            });
            await this.activity.log(tx, {
                type: 'task.updated',
                actorId: auth.userId,
                projectId: t.projectId,
                taskId: id,
                payload: { number: task(t.number), title: t.title, fields: Object.keys(changes) },
            });
            if (changes.deadline)
                await this.outbox.publish(tx, 'task.deadline_changed', { taskId: id }, auth.userId);
            if (changes.assigneeId && input.assigneeId && input.assigneeId !== auth.userId)
                await this.outbox.publish(tx, 'task.assigned', { taskId: id, assigneeId: input.assigneeId }, auth.userId);
        });
        return this.get(auth, id);
    }
    async canEdit(auth, t) {
        if (t.creatorId === auth.userId)
            return true;
        return this.access.can(auth, t.projectId, 'task.create');
    }
    /**
     * Перемещение карточки Kanban (ТЗ §23): статус + позиция в колонке.
     * Закрытую или отменённую задачу возвращает в работу только тот, кто управляет задачами.
     */
    async move(auth, id, input, meta) {
        const t = await this.access.task(auth, id, 'task.update');
        this.assertProjectOpen(t.project);
        const from = t.status;
        const to = input.status;
        const canEdit = await this.canEdit(auth, t);
        if (!canEdit) {
            if (t.assigneeId !== auth.userId)
                throw (0, app_exception_1.forbidden)();
            if (to === 'CANCELLED')
                throw (0, app_exception_1.businessRule)('Отменить задачу может руководитель проекта');
            if (from === 'DONE' || from === 'CANCELLED')
                throw (0, app_exception_1.businessRule)('Вернуть закрытую задачу в работу может руководитель проекта');
        }
        await this.prisma.$transaction(async (tx) => {
            const sortOrder = await this.positionIn(tx, t.projectId, to, input.beforeId ?? null, id);
            const data = { status: to, sortOrder };
            if (from !== to) {
                if (to === 'IN_PROGRESS' && !t.startedAt)
                    data.startedAt = new Date();
                if (to === 'DONE') {
                    data.completedAt = new Date();
                    data.progressPct = 100;
                }
                else if (from === 'DONE')
                    data.completedAt = null;
                if ((0, domain_1.isRework)(from, to))
                    data.reworkCount = { increment: 1 };
            }
            await tx.task.update({ where: { id }, data });
            if (from === to)
                return;
            await tx.taskStatusHistory.create({
                data: { taskId: id, fromStatus: from, toStatus: to, changedById: auth.userId },
            });
            await this.activity.log(tx, {
                type: 'task.status_changed',
                actorId: auth.userId,
                projectId: t.projectId,
                taskId: id,
                payload: { number: task(t.number), title: t.title, from, to },
            });
            await this.audit.log(tx, {
                actorId: auth.userId,
                action: 'task.status',
                entityType: 'task',
                entityId: id,
                changes: { status: { old: from, new: to } },
                meta,
            });
            // Первая задача в работе — проект переходит в «В работе».
            if (to === 'IN_PROGRESS' && ['NEW', 'PLANNING'].includes(t.project.status)) {
                await tx.project.update({ where: { id: t.projectId }, data: { status: 'IN_PROGRESS' } });
                await this.activity.log(tx, {
                    type: 'project.status_changed',
                    actorId: auth.userId,
                    projectId: t.projectId,
                    payload: { from: t.project.status, to: 'IN_PROGRESS', auto: true },
                });
            }
            await this.outbox.publish(tx, 'task.status_changed', { taskId: id, projectId: t.projectId, from, to }, auth.userId);
        });
        return this.get(auth, id);
    }
    /** sort_order для карточки перед beforeId (или в конце колонки). */
    async positionIn(tx, projectId, status, beforeId, selfId) {
        const column = { projectId, status, deletedAt: null, id: { not: selfId } };
        if (beforeId) {
            const before = await tx.task.findFirst({ where: { ...column, id: beforeId } });
            if (before) {
                const prev = await tx.task.findFirst({
                    where: { ...column, sortOrder: { lt: before.sortOrder } },
                    orderBy: { sortOrder: 'desc' },
                });
                return (0, domain_1.sortBetween)(prev?.sortOrder ?? null, before.sortOrder);
            }
        }
        const last = await tx.task.aggregate({ where: column, _max: { sortOrder: true } });
        return (0, domain_1.sortBetween)(last._max.sortOrder, null);
    }
    /** Удаление (soft) — только тот, кто управляет задачами проекта. */
    async remove(auth, id, meta) {
        const t = await this.access.task(auth, id, 'task.update');
        this.assertProjectOpen(t.project);
        if (!(await this.access.can(auth, t.projectId, 'task.create')))
            throw (0, app_exception_1.forbidden)();
        await this.prisma.$transaction(async (tx) => {
            await tx.task.update({ where: { id }, data: { deletedAt: new Date() } });
            await this.activity.log(tx, {
                type: 'task.deleted',
                actorId: auth.userId,
                projectId: t.projectId,
                taskId: id,
                payload: { number: task(t.number), title: t.title },
            });
            await this.audit.log(tx, {
                actorId: auth.userId,
                action: 'task.delete',
                entityType: 'task',
                entityId: id,
                changes: { title: { old: t.title, new: null } },
                meta,
            });
        });
    }
    /** Комментарий может оставить любой, кто видит задачу (ТЗ §3.4). */
    async comment(auth, id, body) {
        const t = await this.access.task(auth, id);
        return this.prisma.$transaction(async (tx) => {
            const c = await tx.taskComment.create({
                data: { taskId: id, authorId: auth.userId, body },
                include: { author: { select: { id: true, fullName: true } } },
            });
            await this.activity.log(tx, {
                type: 'task.commented',
                actorId: auth.userId,
                projectId: t.projectId,
                taskId: id,
                payload: { number: task(t.number), title: t.title },
            });
            return {
                id: c.id,
                author: { id: c.author.id, name: c.author.fullName },
                body: c.body,
                createdAt: c.createdAt.toISOString(),
            };
        });
    }
};
exports.TasksService = TasksService;
exports.TasksService = TasksService = __decorate([
    (0, common_1.Injectable)(),
    __metadata("design:paramtypes", [prisma_service_1.PrismaService,
        project_access_service_1.ProjectAccessService,
        activity_service_1.ActivityService,
        audit_service_1.AuditService,
        outbox_service_1.OutboxService])
], TasksService);
//# sourceMappingURL=tasks.service.js.map
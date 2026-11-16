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
exports.TemplatesService = void 0;
const common_1 = require("@nestjs/common");
const domain_1 = require("@fluggi/domain");
const audit_service_1 = require("../../core/audit/audit.service");
const app_exception_1 = require("../../core/http/app.exception");
const serialize_1 = require("../../core/http/serialize");
const prisma_service_1 = require("../../core/prisma/prisma.service");
const activity_service_1 = require("../crm/activity.service");
const include = {
    service: { select: { id: true, nameRu: true } },
    tasks: { orderBy: { sort: 'asc' } },
};
const toDto = (t) => ({
    id: t.id,
    name: t.name,
    service: t.service ? { id: t.service.id, name: t.service.nameRu } : null,
    description: t.description,
    isActive: t.isActive,
    tasks: t.tasks.map((x) => ({
        id: x.id,
        title: x.title,
        description: x.description,
        role: x.role,
        startOffsetDays: x.startOffsetDays,
        durationDays: x.durationDays,
        priority: x.priority,
    })),
});
/** Шаблоны проектов и задач (ТЗ §62). */
let TemplatesService = class TemplatesService {
    prisma;
    audit;
    activity;
    constructor(prisma, audit, activity) {
        this.prisma = prisma;
        this.audit = audit;
        this.activity = activity;
    }
    async list(activeOnly) {
        const rows = await this.prisma.projectTemplate.findMany({
            where: activeOnly ? { isActive: true } : {},
            include,
            orderBy: { name: 'asc' },
        });
        return rows.map(toDto);
    }
    taskRows(input) {
        return input.tasks.map((t, sort) => ({
            title: t.title,
            description: t.description ?? null,
            role: t.role ?? null,
            startOffsetDays: t.startOffsetDays,
            durationDays: t.durationDays,
            priority: t.priority,
            sort,
        }));
    }
    async create(auth, input, meta) {
        return this.prisma.$transaction(async (tx) => {
            const t = await tx.projectTemplate.create({
                data: {
                    name: input.name,
                    serviceId: input.serviceId ?? null,
                    description: input.description ?? null,
                    isActive: input.isActive,
                    tasks: { create: this.taskRows(input) },
                },
                include,
            });
            await this.audit.log(tx, {
                actorId: auth.userId,
                action: 'project_template.create',
                entityType: 'project_template',
                entityId: t.id,
                changes: { name: { old: null, new: t.name } },
                meta,
            });
            return toDto(t);
        });
    }
    /** Изменение шаблона не затрагивает уже созданные задачи проектов. */
    async update(auth, id, input, meta) {
        const before = await this.prisma.projectTemplate.findUnique({ where: { id } });
        if (!before)
            throw (0, app_exception_1.notFound)('Шаблон');
        return this.prisma.$transaction(async (tx) => {
            await tx.taskTemplate.deleteMany({ where: { projectTemplateId: id } });
            const t = await tx.projectTemplate.update({
                where: { id },
                data: {
                    name: input.name,
                    serviceId: input.serviceId ?? null,
                    description: input.description ?? null,
                    isActive: input.isActive,
                    tasks: { create: this.taskRows(input) },
                },
                include,
            });
            await this.audit.log(tx, {
                actorId: auth.userId,
                action: 'project_template.update',
                entityType: 'project_template',
                entityId: id,
                changes: {
                    name: { old: before.name, new: t.name },
                    isActive: { old: before.isActive, new: t.isActive },
                    tasks: { old: null, new: t.tasks.length },
                },
                meta,
            });
            return toDto(t);
        });
    }
    /** Активный шаблон для услуги сделки (первый по названию). */
    async forService(tx, serviceId) {
        if (!serviceId)
            return null;
        return tx.projectTemplate.findFirst({
            where: { serviceId, isActive: true },
            orderBy: { name: 'asc' },
        });
    }
    /**
     * Создаёт задачи проекта из шаблона. Сроки — от даты начала проекта (или сегодня),
     * исполнитель — активный участник команды с ролью задачи, иначе РОП проекта
     * (ТЗ §77, Rule 5): задача перейдёт к исполнителю, когда его добавят в команду.
     */
    async apply(tx, project, templateId, actorId) {
        const template = await tx.projectTemplate.findUnique({
            where: { id: templateId },
            include: { tasks: { orderBy: { sort: 'asc' } } },
        });
        if (!template || !template.isActive)
            throw (0, app_exception_1.notFound)('Шаблон');
        if (template.tasks.length === 0)
            throw (0, app_exception_1.businessRule)('В шаблоне нет задач');
        const start = (0, serialize_1.dateOnly)(project.startDate) ?? (0, domain_1.companyDate)(new Date());
        const members = await tx.projectMember.findMany({
            where: { projectId: project.id, status: 'ACTIVE', role: { not: null } },
            orderBy: { assignedAt: 'asc' },
        });
        const byRole = new Map(members.map((m) => [m.role, m.userId]));
        const last = await tx.task.aggregate({
            where: { projectId: project.id, status: 'TODO' },
            _max: { sortOrder: true },
        });
        let sortOrder = last._max.sortOrder ?? 0;
        for (const t of template.tasks) {
            const dates = (0, domain_1.templateTaskDates)(start, t.startOffsetDays, t.durationDays);
            sortOrder += 1000;
            const task = await tx.task.create({
                data: {
                    projectId: project.id,
                    title: t.title,
                    description: t.description,
                    templateRole: t.role,
                    assigneeId: (t.role ? byRole.get(t.role) : undefined) ?? project.ropId,
                    creatorId: actorId,
                    priority: t.priority,
                    startDate: (0, serialize_1.parseDate)(dates.startDate),
                    deadline: dates.deadline,
                    sortOrder,
                },
            });
            await tx.taskStatusHistory.create({
                data: { taskId: task.id, toStatus: 'TODO', changedById: actorId },
            });
        }
        await tx.project.update({
            where: { id: project.id },
            data: {
                templateId: project.templateId ?? template.id,
                startDate: project.startDate ?? (0, serialize_1.parseDate)(start),
            },
        });
        await this.activity.log(tx, {
            type: 'project.template_applied',
            actorId,
            projectId: project.id,
            payload: { template: template.name, tasks: template.tasks.length },
        });
        return { created: template.tasks.length };
    }
};
exports.TemplatesService = TemplatesService;
exports.TemplatesService = TemplatesService = __decorate([
    (0, common_1.Injectable)(),
    __metadata("design:paramtypes", [prisma_service_1.PrismaService,
        audit_service_1.AuditService,
        activity_service_1.ActivityService])
], TemplatesService);
//# sourceMappingURL=templates.service.js.map
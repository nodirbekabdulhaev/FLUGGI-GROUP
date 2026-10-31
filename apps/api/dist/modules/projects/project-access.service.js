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
exports.ProjectAccessService = void 0;
const common_1 = require("@nestjs/common");
const app_exception_1 = require("../../core/http/app.exception");
const prisma_service_1 = require("../../core/prisma/prisma.service");
const scope_1 = require("../../core/rbac/scope");
/**
 * Видимость проектов и задач (docs/PERMISSIONS.md, ТЗ §65).
 *  Проект: OWN — я менеджер/РОП проекта или участник команды; TEAM — проекты моих отделов.
 *  Задача: OWN — я ответственный или автор, либо менеджер проекта; TEAM — задачи проектов отдела.
 * Участие в команде даёт только чтение: менять проект можно по праву project.update/assign.
 * Направления: у сотрудника с направлениями (проект-менеджер) область TEAM включает
 * все проекты этих направлений (например, вся «Медиа»), а проекты других направлений не видны.
 */
let ProjectAccessService = class ProjectAccessService {
    prisma;
    constructor(prisma) {
        this.prisma = prisma;
    }
    teamIds(auth) {
        return [...new Set([...auth.headedTeamIds, ...(auth.teamId ? [auth.teamId] : [])])];
    }
    /** Проекты, на которые у пользователя есть право code. */
    projectWhere(auth, code = 'project.read') {
        const scope = (0, scope_1.scopeOf)(auth, code);
        const me = auth.userId;
        const lead = [{ managerId: me }, { ropId: me }];
        // Участник команды видит проект (но не управляет им).
        const member = code === 'project.read' || code === 'task.read'
            ? [{ members: { some: { userId: me, status: { not: 'REMOVED' } } } }]
            : [];
        if (scope === 'ALL')
            return { deletedAt: null };
        if (scope === 'TEAM')
            return {
                deletedAt: null,
                OR: [
                    { teamId: { in: this.teamIds(auth) } },
                    ...this.directionScope(auth),
                    ...lead,
                    ...member,
                ],
            };
        return { deletedAt: null, OR: [...lead, ...member] };
    }
    /** Проекты направлений сотрудника (пусто — у сотрудника нет направлений). */
    directionScope(auth) {
        return auth.directionIds.length ? [{ directionId: { in: auth.directionIds } }] : [];
    }
    /** Задачи, на которые у пользователя есть право code. */
    taskWhere(auth, code = 'task.read') {
        const scope = (0, scope_1.scopeOf)(auth, code);
        const me = auth.userId;
        const base = { deletedAt: null, project: { deletedAt: null } };
        if (scope === 'ALL')
            return base;
        const mine = [
            { assigneeId: me },
            { creatorId: me },
            { project: { OR: [{ managerId: me }, { ropId: me }] } },
        ];
        if (scope === 'TEAM')
            return {
                ...base,
                OR: [
                    { project: { teamId: { in: this.teamIds(auth) } } },
                    ...this.directionScope(auth).map((project) => ({ project })),
                    ...mine,
                ],
            };
        return { ...base, OR: mine };
    }
    async project(auth, id, code = 'project.read', tx = this.prisma) {
        const p = await tx.project.findFirst({
            where: { AND: [this.projectWhere(auth, code), { id }] },
        });
        if (!p)
            throw (0, app_exception_1.notFound)('Проект');
        return p;
    }
    /** Есть ли у пользователя право code на этот проект (без исключения). */
    async can(auth, projectId, code) {
        if (!auth.permissions[code])
            return false;
        const n = await this.prisma.project.count({
            where: { AND: [this.projectWhere(auth, code), { id: projectId }] },
        });
        return n > 0;
    }
    async task(auth, id, code = 'task.read') {
        const t = await this.prisma.task.findFirst({
            where: { AND: [this.taskWhere(auth, code), { id }] },
            include: { project: true },
        });
        if (!t)
            throw (0, app_exception_1.notFound)('Задача');
        return t;
    }
};
exports.ProjectAccessService = ProjectAccessService;
exports.ProjectAccessService = ProjectAccessService = __decorate([
    (0, common_1.Injectable)(),
    __metadata("design:paramtypes", [prisma_service_1.PrismaService])
], ProjectAccessService);
//# sourceMappingURL=project-access.service.js.map
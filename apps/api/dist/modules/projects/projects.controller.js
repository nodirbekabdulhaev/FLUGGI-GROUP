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
var __param = (this && this.__param) || function (paramIndex, decorator) {
    return function (target, key) { decorator(target, key, paramIndex); }
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.ProjectsController = void 0;
const common_1 = require("@nestjs/common");
const contracts_1 = require("@fluggi/contracts");
const decorators_1 = require("../../core/auth/decorators");
const uuid_pipe_1 = require("../../core/http/uuid.pipe");
const zod_pipe_1 = require("../../core/http/zod.pipe");
const prisma_service_1 = require("../../core/prisma/prisma.service");
const project_access_service_1 = require("./project-access.service");
const projects_service_1 = require("./projects.service");
/** Проекты (ТЗ §19–21). Видимость — ProjectAccessService. */
let ProjectsController = class ProjectsController {
    projects;
    access;
    prisma;
    constructor(projects, access, prisma) {
        this.projects = projects;
        this.access = access;
        this.prisma = prisma;
    }
    list(auth, q) {
        return this.projects.list(auth, q);
    }
    get(auth, id) {
        return this.projects.get(auth, id);
    }
    update(auth, id, body, meta) {
        return this.projects.update(auth, id, body, meta);
    }
    status(auth, id, body, meta) {
        return this.projects.setStatus(auth, id, body, meta);
    }
    applyTemplate(auth, id, body, meta) {
        return this.projects.applyTemplate(auth, id, body.templateId, meta);
    }
    timeline(auth, id) {
        return this.projects.timeline(auth, id);
    }
    /** Кого можно добавить в команду: активные исполнители, менеджеры и РОП. */
    async candidates(auth, id) {
        await this.access.project(auth, id, 'project.assign');
        const users = await this.prisma.user.findMany({
            where: {
                status: 'ACTIVE',
                deletedAt: null,
                role: { code: { in: ['EXECUTOR', 'MANAGER', 'ROP'] } },
            },
            include: { role: true, employee: true },
            orderBy: { fullName: 'asc' },
        });
        return users.map((u) => ({
            id: u.id,
            name: u.fullName,
            role: u.role.code,
            specialty: u.employee?.specialty ?? null,
        }));
    }
    addMember(auth, id, body, meta) {
        return this.projects.addMember(auth, id, body, meta);
    }
    updateMember(auth, id, memberId, body, meta) {
        return this.projects.updateMember(auth, id, memberId, body, meta);
    }
};
exports.ProjectsController = ProjectsController;
__decorate([
    (0, common_1.Get)(),
    (0, decorators_1.RequirePermission)('project.read'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Query)((0, zod_pipe_1.zod)(contracts_1.projectListQuerySchema))),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object]),
    __metadata("design:returntype", Promise)
], ProjectsController.prototype, "list", null);
__decorate([
    (0, common_1.Get)(':id'),
    (0, decorators_1.RequirePermission)('project.read'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String]),
    __metadata("design:returntype", Promise)
], ProjectsController.prototype, "get", null);
__decorate([
    (0, common_1.Patch)(':id'),
    (0, decorators_1.RequirePermission)('project.update'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __param(2, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.updateProjectSchema))),
    __param(3, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String, Object, Object]),
    __metadata("design:returntype", Promise)
], ProjectsController.prototype, "update", null);
__decorate([
    (0, common_1.Post)(':id/status'),
    (0, common_1.HttpCode)(200),
    (0, decorators_1.RequirePermission)('project.update'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __param(2, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.projectStatusSchema))),
    __param(3, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String, Object, Object]),
    __metadata("design:returntype", Promise)
], ProjectsController.prototype, "status", null);
__decorate([
    (0, common_1.Post)(':id/apply-template'),
    (0, common_1.HttpCode)(200),
    (0, decorators_1.RequirePermission)('project.update'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __param(2, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.applyTemplateSchema))),
    __param(3, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String, Object, Object]),
    __metadata("design:returntype", Promise)
], ProjectsController.prototype, "applyTemplate", null);
__decorate([
    (0, common_1.Get)(':id/timeline'),
    (0, decorators_1.RequirePermission)('project.read'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String]),
    __metadata("design:returntype", Promise)
], ProjectsController.prototype, "timeline", null);
__decorate([
    (0, common_1.Get)(':id/candidates'),
    (0, decorators_1.RequirePermission)('project.assign'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String]),
    __metadata("design:returntype", Promise)
], ProjectsController.prototype, "candidates", null);
__decorate([
    (0, common_1.Post)(':id/members'),
    (0, decorators_1.RequirePermission)('project.assign'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __param(2, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.addMemberSchema))),
    __param(3, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String, Object, Object]),
    __metadata("design:returntype", Promise)
], ProjectsController.prototype, "addMember", null);
__decorate([
    (0, common_1.Patch)(':id/members/:memberId'),
    (0, decorators_1.RequirePermission)('project.assign'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __param(2, (0, common_1.Param)('memberId', uuid_pipe_1.UuidPipe)),
    __param(3, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.updateMemberSchema))),
    __param(4, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String, String, Object, Object]),
    __metadata("design:returntype", Promise)
], ProjectsController.prototype, "updateMember", null);
exports.ProjectsController = ProjectsController = __decorate([
    (0, common_1.Controller)('projects'),
    __metadata("design:paramtypes", [projects_service_1.ProjectsService,
        project_access_service_1.ProjectAccessService,
        prisma_service_1.PrismaService])
], ProjectsController);
//# sourceMappingURL=projects.controller.js.map
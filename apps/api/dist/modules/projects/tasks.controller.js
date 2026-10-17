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
exports.TasksController = void 0;
const common_1 = require("@nestjs/common");
const contracts_1 = require("@fluggi/contracts");
const decorators_1 = require("../../core/auth/decorators");
const uuid_pipe_1 = require("../../core/http/uuid.pipe");
const zod_pipe_1 = require("../../core/http/zod.pipe");
const tasks_service_1 = require("./tasks.service");
/** Задачи (ТЗ §22–24). */
let TasksController = class TasksController {
    tasks;
    constructor(tasks) {
        this.tasks = tasks;
    }
    list(auth, q) {
        return this.tasks.list(auth, q);
    }
    get(auth, id) {
        return this.tasks.get(auth, id);
    }
    create(auth, body, meta) {
        return this.tasks.create(auth, body, meta);
    }
    update(auth, id, body, meta) {
        return this.tasks.update(auth, id, body, meta);
    }
    move(auth, id, body, meta) {
        return this.tasks.move(auth, id, body, meta);
    }
    remove(auth, id, meta) {
        return this.tasks.remove(auth, id, meta);
    }
    comment(auth, id, body) {
        return this.tasks.comment(auth, id, body.body);
    }
};
exports.TasksController = TasksController;
__decorate([
    (0, common_1.Get)(),
    (0, decorators_1.RequirePermission)('task.read'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Query)((0, zod_pipe_1.zod)(contracts_1.taskListQuerySchema))),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object]),
    __metadata("design:returntype", Promise)
], TasksController.prototype, "list", null);
__decorate([
    (0, common_1.Get)(':id'),
    (0, decorators_1.RequirePermission)('task.read'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String]),
    __metadata("design:returntype", Promise)
], TasksController.prototype, "get", null);
__decorate([
    (0, common_1.Post)(),
    (0, decorators_1.RequirePermission)('task.create'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.createTaskSchema))),
    __param(2, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object, Object]),
    __metadata("design:returntype", Promise)
], TasksController.prototype, "create", null);
__decorate([
    (0, common_1.Patch)(':id'),
    (0, decorators_1.RequirePermission)('task.update'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __param(2, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.updateTaskSchema))),
    __param(3, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String, Object, Object]),
    __metadata("design:returntype", Promise)
], TasksController.prototype, "update", null);
__decorate([
    (0, common_1.Post)(':id/move'),
    (0, common_1.HttpCode)(200),
    (0, decorators_1.RequirePermission)('task.update'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __param(2, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.moveTaskSchema))),
    __param(3, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String, Object, Object]),
    __metadata("design:returntype", Promise)
], TasksController.prototype, "move", null);
__decorate([
    (0, common_1.Delete)(':id'),
    (0, common_1.HttpCode)(204),
    (0, decorators_1.RequirePermission)('task.update'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __param(2, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String, Object]),
    __metadata("design:returntype", Promise)
], TasksController.prototype, "remove", null);
__decorate([
    (0, common_1.Post)(':id/comments'),
    (0, decorators_1.RequirePermission)('task.read'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __param(2, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.taskCommentSchema))),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String, Object]),
    __metadata("design:returntype", Promise)
], TasksController.prototype, "comment", null);
exports.TasksController = TasksController = __decorate([
    (0, common_1.Controller)('tasks'),
    __metadata("design:paramtypes", [tasks_service_1.TasksService])
], TasksController);
//# sourceMappingURL=tasks.controller.js.map
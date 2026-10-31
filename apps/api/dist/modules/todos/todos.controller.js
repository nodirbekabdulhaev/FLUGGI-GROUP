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
exports.TodosController = void 0;
const common_1 = require("@nestjs/common");
const contracts_1 = require("@fluggi/contracts");
const decorators_1 = require("../../core/auth/decorators");
const uuid_pipe_1 = require("../../core/http/uuid.pipe");
const zod_pipe_1 = require("../../core/http/zod.pipe");
const recurring_service_1 = require("./recurring.service");
const todos_service_1 = require("./todos.service");
/**
 * Личные дела и регулярные дела. Доступны каждому сотруднику для себя;
 * поручить другому — по праву task.create (проверка в сервисе).
 */
let TodosController = class TodosController {
    todos;
    recurring;
    constructor(todos, recurring) {
        this.todos = todos;
        this.recurring = recurring;
    }
    list(auth, q) {
        return this.todos.list(auth, q);
    }
    dock(auth) {
        return this.todos.dock(auth);
    }
    create(auth, body) {
        return this.todos.create(auth, body);
    }
    update(auth, id, body) {
        return this.todos.update(auth, id, body);
    }
    complete(auth, id) {
        return this.todos.setStatus(auth, id, 'DONE');
    }
    reopen(auth, id) {
        return this.todos.setStatus(auth, id, 'OPEN');
    }
    remove(auth, id) {
        return this.todos.remove(auth, id);
    }
    recurringList(auth) {
        return this.recurring.list(auth);
    }
    recurringCreate(auth, body) {
        return this.recurring.create(auth, body);
    }
    taxCalendar(auth) {
        return this.recurring.addTaxCalendar(auth);
    }
    recurringUpdate(auth, id, body) {
        return this.recurring.update(auth, id, body);
    }
    recurringRemove(auth, id) {
        return this.recurring.remove(auth, id);
    }
};
exports.TodosController = TodosController;
__decorate([
    (0, common_1.Get)('todos'),
    (0, decorators_1.AuthenticatedOnly)(),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Query)((0, zod_pipe_1.zod)(contracts_1.todoListQuerySchema))),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object]),
    __metadata("design:returntype", Promise)
], TodosController.prototype, "list", null);
__decorate([
    (0, common_1.Get)('todos/dock'),
    (0, decorators_1.AuthenticatedOnly)(),
    __param(0, (0, decorators_1.CurrentUser)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object]),
    __metadata("design:returntype", Promise)
], TodosController.prototype, "dock", null);
__decorate([
    (0, common_1.Post)('todos'),
    (0, decorators_1.AuthenticatedOnly)(),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.createTodoSchema))),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object]),
    __metadata("design:returntype", Promise)
], TodosController.prototype, "create", null);
__decorate([
    (0, common_1.Patch)('todos/:id'),
    (0, decorators_1.AuthenticatedOnly)(),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __param(2, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.updateTodoSchema))),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String, Object]),
    __metadata("design:returntype", Promise)
], TodosController.prototype, "update", null);
__decorate([
    (0, common_1.Post)('todos/:id/complete'),
    (0, common_1.HttpCode)(200),
    (0, decorators_1.AuthenticatedOnly)(),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String]),
    __metadata("design:returntype", Promise)
], TodosController.prototype, "complete", null);
__decorate([
    (0, common_1.Post)('todos/:id/reopen'),
    (0, common_1.HttpCode)(200),
    (0, decorators_1.AuthenticatedOnly)(),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String]),
    __metadata("design:returntype", Promise)
], TodosController.prototype, "reopen", null);
__decorate([
    (0, common_1.Delete)('todos/:id'),
    (0, common_1.HttpCode)(204),
    (0, decorators_1.AuthenticatedOnly)(),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String]),
    __metadata("design:returntype", Promise)
], TodosController.prototype, "remove", null);
__decorate([
    (0, common_1.Get)('recurring-todos'),
    (0, decorators_1.AuthenticatedOnly)(),
    __param(0, (0, decorators_1.CurrentUser)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object]),
    __metadata("design:returntype", Promise)
], TodosController.prototype, "recurringList", null);
__decorate([
    (0, common_1.Post)('recurring-todos'),
    (0, decorators_1.AuthenticatedOnly)(),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.recurringTodoSchema))),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object]),
    __metadata("design:returntype", Promise)
], TodosController.prototype, "recurringCreate", null);
__decorate([
    (0, common_1.Post)('recurring-todos/tax-calendar'),
    (0, common_1.HttpCode)(200),
    (0, decorators_1.AuthenticatedOnly)(),
    __param(0, (0, decorators_1.CurrentUser)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object]),
    __metadata("design:returntype", Promise)
], TodosController.prototype, "taxCalendar", null);
__decorate([
    (0, common_1.Put)('recurring-todos/:id'),
    (0, decorators_1.AuthenticatedOnly)(),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __param(2, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.recurringTodoSchema))),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String, Object]),
    __metadata("design:returntype", Promise)
], TodosController.prototype, "recurringUpdate", null);
__decorate([
    (0, common_1.Delete)('recurring-todos/:id'),
    (0, common_1.HttpCode)(204),
    (0, decorators_1.AuthenticatedOnly)(),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String]),
    __metadata("design:returntype", Promise)
], TodosController.prototype, "recurringRemove", null);
exports.TodosController = TodosController = __decorate([
    (0, common_1.Controller)(),
    __metadata("design:paramtypes", [todos_service_1.TodosService,
        recurring_service_1.RecurringTodosService])
], TodosController);
//# sourceMappingURL=todos.controller.js.map
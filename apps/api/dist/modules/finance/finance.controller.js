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
exports.FinanceController = void 0;
const common_1 = require("@nestjs/common");
const contracts_1 = require("@fluggi/contracts");
const decorators_1 = require("../../core/auth/decorators");
const uuid_pipe_1 = require("../../core/http/uuid.pipe");
const zod_pipe_1 = require("../../core/http/zod.pipe");
const expenses_service_1 = require("./expenses.service");
const finance_service_1 = require("./finance.service");
/** Финансы (ТЗ §25–27): расходы, карточка проекта, дашборд, прибыльность проектов. */
let FinanceController = class FinanceController {
    expenses;
    finance;
    constructor(expenses, finance) {
        this.expenses = expenses;
        this.finance = finance;
    }
    list(auth, q) {
        return this.expenses.list(auth, q);
    }
    create(auth, body, meta) {
        return this.expenses.create(auth, body, meta);
    }
    update(auth, id, body, meta) {
        return this.expenses.update(auth, id, body, meta);
    }
    remove(auth, id, meta) {
        return this.expenses.remove(auth, id, meta);
    }
    summary(auth, q) {
        return this.finance.summary(auth, q);
    }
    projects(auth, q) {
        return this.finance.projectsProfit(auth, q);
    }
    project(auth, id) {
        return this.finance.project(auth, id);
    }
};
exports.FinanceController = FinanceController;
__decorate([
    (0, common_1.Get)('expenses'),
    (0, decorators_1.RequirePermission)('finance.read'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Query)((0, zod_pipe_1.zod)(contracts_1.expenseListQuerySchema))),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object]),
    __metadata("design:returntype", Promise)
], FinanceController.prototype, "list", null);
__decorate([
    (0, common_1.Post)('expenses'),
    (0, decorators_1.RequirePermission)('expense.create'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.createExpenseSchema))),
    __param(2, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object, Object]),
    __metadata("design:returntype", Promise)
], FinanceController.prototype, "create", null);
__decorate([
    (0, common_1.Patch)('expenses/:id'),
    (0, decorators_1.RequirePermission)('expense.update'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __param(2, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.updateExpenseSchema))),
    __param(3, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String, Object, Object]),
    __metadata("design:returntype", Promise)
], FinanceController.prototype, "update", null);
__decorate([
    (0, common_1.Delete)('expenses/:id'),
    (0, common_1.HttpCode)(204),
    (0, decorators_1.RequirePermission)('expense.update'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __param(2, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String, Object]),
    __metadata("design:returntype", Promise)
], FinanceController.prototype, "remove", null);
__decorate([
    (0, common_1.Get)('finance/summary'),
    (0, decorators_1.RequirePermission)('finance.read'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Query)((0, zod_pipe_1.zod)(contracts_1.periodQuerySchema))),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object]),
    __metadata("design:returntype", Promise)
], FinanceController.prototype, "summary", null);
__decorate([
    (0, common_1.Get)('finance/projects'),
    (0, decorators_1.RequirePermission)('finance.read'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Query)((0, zod_pipe_1.zod)(contracts_1.projectProfitQuerySchema))),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object]),
    __metadata("design:returntype", Promise)
], FinanceController.prototype, "projects", null);
__decorate([
    (0, common_1.Get)('projects/:id/finance'),
    (0, decorators_1.RequirePermission)('finance.read'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String]),
    __metadata("design:returntype", Promise)
], FinanceController.prototype, "project", null);
exports.FinanceController = FinanceController = __decorate([
    (0, common_1.Controller)(),
    __metadata("design:paramtypes", [expenses_service_1.ExpensesService,
        finance_service_1.FinanceService])
], FinanceController);
//# sourceMappingURL=finance.controller.js.map
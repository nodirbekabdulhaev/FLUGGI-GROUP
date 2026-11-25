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
exports.CatalogController = void 0;
const common_1 = require("@nestjs/common");
const contracts_1 = require("@fluggi/contracts");
const zod_1 = require("zod");
const decorators_1 = require("../../core/auth/decorators");
const uuid_pipe_1 = require("../../core/http/uuid.pipe");
const zod_pipe_1 = require("../../core/http/zod.pipe");
const cost_lines_service_1 = require("../finance/cost-lines.service");
const overhead_service_1 = require("../finance/overhead.service");
const catalog_service_1 = require("./catalog.service");
const kindQuery = zod_1.z.object({ kind: zod_1.z.enum(['EXPENSE', 'INCOME']).optional() });
const tariffQuery = zod_1.z.object({
    serviceId: zod_1.z.uuid().optional(),
    all: zod_1.z
        .enum(['true', 'false'])
        .optional()
        .transform((v) => v === 'true'),
});
/** Справочники финансов, тарифы и себестоимость (ТЗ: тарификация, ценообразование). */
let CatalogController = class CatalogController {
    catalog;
    overhead;
    costLines;
    constructor(catalog, overhead, costLines) {
        this.catalog = catalog;
        this.overhead = overhead;
        this.costLines = costLines;
    }
    // ── Категории доходов и расходов
    categories(q) {
        return this.catalog.categories(q.kind);
    }
    createCategory(auth, body, meta) {
        return this.catalog.createCategory(auth, body, meta);
    }
    updateCategory(auth, id, body, meta) {
        return this.catalog.updateCategory(auth, id, body, meta);
    }
    deleteCategory(auth, id, meta) {
        return this.catalog.deleteCategory(auth, id, meta);
    }
    // ── Прочие поступления (CEO)
    incomes(q) {
        return this.catalog.incomes(q);
    }
    createIncome(auth, body, meta) {
        return this.catalog.saveIncome(auth, null, body, meta);
    }
    updateIncome(auth, id, body, meta) {
        return this.catalog.saveIncome(auth, id, body, meta);
    }
    deleteIncome(auth, id, meta) {
        return this.catalog.deleteIncome(auth, id, meta);
    }
    // ── Финансовые настройки (делитель накладных)
    financeSettings() {
        return this.overhead.settings();
    }
    saveFinanceSettings(auth, body) {
        return this.overhead.saveSettings(body, auth.userId);
    }
    // ── Направления бизнеса (IT, Медиа, Маркетинг)
    createDirection(auth, body, meta) {
        return this.catalog.saveDirection(auth, null, body, meta);
    }
    updateDirection(auth, id, body, meta) {
        return this.catalog.saveDirection(auth, id, body, meta);
    }
    // ── Единицы работ
    workItems() {
        return this.catalog.workItems();
    }
    createWorkItem(auth, body, meta) {
        return this.catalog.saveWorkItem(auth, null, body, meta);
    }
    updateWorkItem(auth, id, body, meta) {
        return this.catalog.saveWorkItem(auth, id, body, meta);
    }
    // ── Личные ставки сотрудника (карточка сотрудника)
    rates(auth, id) {
        return this.catalog.employeeRates(auth, id);
    }
    saveRates(auth, id, body, meta) {
        return this.catalog.saveEmployeeRates(auth, id, body, meta);
    }
    // ── Тарифы
    tariffs(auth, q) {
        return this.catalog.tariffs(auth, q);
    }
    createTariff(auth, body, meta) {
        return this.catalog.saveTariff(auth, null, body, meta);
    }
    updateTariff(auth, id, body, meta) {
        return this.catalog.saveTariff(auth, id, body, meta);
    }
    // ── Себестоимость проекта по тарифу
    costLinesList(auth, id) {
        return this.costLines.list(auth, id);
    }
    costLineUpdate(auth, id, body) {
        return this.costLines.update(auth, id, body);
    }
    costLineAccrue(auth, id, meta) {
        return this.costLines.accrue(auth, id, meta);
    }
    costLineCancel(auth, id) {
        return this.costLines.cancel(auth, id);
    }
};
exports.CatalogController = CatalogController;
__decorate([
    (0, common_1.Get)('finance-categories'),
    (0, decorators_1.AuthenticatedOnly)(),
    __param(0, (0, common_1.Query)((0, zod_pipe_1.zod)(kindQuery))),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object]),
    __metadata("design:returntype", Promise)
], CatalogController.prototype, "categories", null);
__decorate([
    (0, common_1.Post)('finance-categories'),
    (0, decorators_1.RequirePermission)('reference.manage', 'ALL'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.financeCategorySchema))),
    __param(2, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object, Object]),
    __metadata("design:returntype", void 0)
], CatalogController.prototype, "createCategory", null);
__decorate([
    (0, common_1.Put)('finance-categories/:id'),
    (0, decorators_1.RequirePermission)('reference.manage', 'ALL'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __param(2, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.financeCategorySchema))),
    __param(3, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String, Object, Object]),
    __metadata("design:returntype", void 0)
], CatalogController.prototype, "updateCategory", null);
__decorate([
    (0, common_1.Delete)('finance-categories/:id'),
    (0, common_1.HttpCode)(204),
    (0, decorators_1.RequirePermission)('reference.manage', 'ALL'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __param(2, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String, Object]),
    __metadata("design:returntype", void 0)
], CatalogController.prototype, "deleteCategory", null);
__decorate([
    (0, common_1.Get)('other-incomes'),
    (0, decorators_1.RequirePermission)('finance.company.read', 'ALL'),
    __param(0, (0, common_1.Query)((0, zod_pipe_1.zod)(contracts_1.otherIncomeListQuerySchema))),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object]),
    __metadata("design:returntype", Promise)
], CatalogController.prototype, "incomes", null);
__decorate([
    (0, common_1.Post)('other-incomes'),
    (0, decorators_1.RequirePermission)('finance.company.read', 'ALL'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.otherIncomeSchema))),
    __param(2, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object, Object]),
    __metadata("design:returntype", Promise)
], CatalogController.prototype, "createIncome", null);
__decorate([
    (0, common_1.Put)('other-incomes/:id'),
    (0, decorators_1.RequirePermission)('finance.company.read', 'ALL'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __param(2, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.otherIncomeSchema))),
    __param(3, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String, Object, Object]),
    __metadata("design:returntype", Promise)
], CatalogController.prototype, "updateIncome", null);
__decorate([
    (0, common_1.Delete)('other-incomes/:id'),
    (0, common_1.HttpCode)(204),
    (0, decorators_1.RequirePermission)('finance.company.read', 'ALL'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __param(2, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String, Object]),
    __metadata("design:returntype", void 0)
], CatalogController.prototype, "deleteIncome", null);
__decorate([
    (0, common_1.Get)('settings/finance'),
    (0, decorators_1.RequirePermission)('finance.company.read', 'ALL'),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", []),
    __metadata("design:returntype", Promise)
], CatalogController.prototype, "financeSettings", null);
__decorate([
    (0, common_1.Put)('settings/finance'),
    (0, decorators_1.RequirePermission)('finance.company.read', 'ALL'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.financeSettingsSchema))),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object]),
    __metadata("design:returntype", Promise)
], CatalogController.prototype, "saveFinanceSettings", null);
__decorate([
    (0, common_1.Post)('directions'),
    (0, decorators_1.RequirePermission)('reference.manage', 'ALL'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.directionSchema))),
    __param(2, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object, Object]),
    __metadata("design:returntype", Promise)
], CatalogController.prototype, "createDirection", null);
__decorate([
    (0, common_1.Put)('directions/:id'),
    (0, decorators_1.RequirePermission)('reference.manage', 'ALL'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __param(2, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.directionSchema))),
    __param(3, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String, Object, Object]),
    __metadata("design:returntype", Promise)
], CatalogController.prototype, "updateDirection", null);
__decorate([
    (0, common_1.Get)('work-items'),
    (0, decorators_1.AuthenticatedOnly)(),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", []),
    __metadata("design:returntype", Promise)
], CatalogController.prototype, "workItems", null);
__decorate([
    (0, common_1.Post)('work-items'),
    (0, decorators_1.RequirePermission)('reference.manage', 'ALL'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.workItemSchema))),
    __param(2, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object, Object]),
    __metadata("design:returntype", Promise)
], CatalogController.prototype, "createWorkItem", null);
__decorate([
    (0, common_1.Put)('work-items/:id'),
    (0, decorators_1.RequirePermission)('reference.manage', 'ALL'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __param(2, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.workItemSchema))),
    __param(3, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String, Object, Object]),
    __metadata("design:returntype", Promise)
], CatalogController.prototype, "updateWorkItem", null);
__decorate([
    (0, common_1.Get)('users/:id/rates'),
    (0, decorators_1.AuthenticatedOnly)(),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String]),
    __metadata("design:returntype", Promise)
], CatalogController.prototype, "rates", null);
__decorate([
    (0, common_1.Put)('users/:id/rates'),
    (0, decorators_1.RequirePermission)('payroll.manage', 'ALL'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __param(2, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.employeeRatesSchema))),
    __param(3, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String, Object, Object]),
    __metadata("design:returntype", Promise)
], CatalogController.prototype, "saveRates", null);
__decorate([
    (0, common_1.Get)('tariffs'),
    (0, decorators_1.AuthenticatedOnly)(),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Query)((0, zod_pipe_1.zod)(tariffQuery))),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object]),
    __metadata("design:returntype", Promise)
], CatalogController.prototype, "tariffs", null);
__decorate([
    (0, common_1.Post)('tariffs'),
    (0, decorators_1.RequirePermission)('reference.manage', 'ALL'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.tariffSchema))),
    __param(2, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object, Object]),
    __metadata("design:returntype", Promise)
], CatalogController.prototype, "createTariff", null);
__decorate([
    (0, common_1.Put)('tariffs/:id'),
    (0, decorators_1.RequirePermission)('reference.manage', 'ALL'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __param(2, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.tariffSchema))),
    __param(3, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String, Object, Object]),
    __metadata("design:returntype", Promise)
], CatalogController.prototype, "updateTariff", null);
__decorate([
    (0, common_1.Get)('projects/:id/cost-lines'),
    (0, decorators_1.AuthenticatedOnly)(),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String]),
    __metadata("design:returntype", Promise)
], CatalogController.prototype, "costLinesList", null);
__decorate([
    (0, common_1.Patch)('cost-lines/:id'),
    (0, decorators_1.AuthenticatedOnly)(),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __param(2, (0, common_1.Body)((0, zod_pipe_1.zod)(contracts_1.updateCostLineSchema))),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String, Object]),
    __metadata("design:returntype", Promise)
], CatalogController.prototype, "costLineUpdate", null);
__decorate([
    (0, common_1.Post)('cost-lines/:id/accrue'),
    (0, common_1.HttpCode)(200),
    (0, decorators_1.AuthenticatedOnly)(),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __param(2, (0, decorators_1.ReqMeta)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String, Object]),
    __metadata("design:returntype", Promise)
], CatalogController.prototype, "costLineAccrue", null);
__decorate([
    (0, common_1.Post)('cost-lines/:id/cancel'),
    (0, common_1.HttpCode)(204),
    (0, decorators_1.AuthenticatedOnly)(),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String]),
    __metadata("design:returntype", Promise)
], CatalogController.prototype, "costLineCancel", null);
exports.CatalogController = CatalogController = __decorate([
    (0, common_1.Controller)(),
    __metadata("design:paramtypes", [catalog_service_1.CatalogService,
        overhead_service_1.OverheadService,
        cost_lines_service_1.CostLinesService])
], CatalogController);
//# sourceMappingURL=catalog.controller.js.map
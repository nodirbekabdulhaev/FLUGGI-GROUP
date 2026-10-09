"use strict";
var __decorate = (this && this.__decorate) || function (decorators, target, key, desc) {
    var c = arguments.length, r = c < 3 ? target : desc === null ? desc = Object.getOwnPropertyDescriptor(target, key) : desc, d;
    if (typeof Reflect === "object" && typeof Reflect.decorate === "function") r = Reflect.decorate(decorators, target, key, desc);
    else for (var i = decorators.length - 1; i >= 0; i--) if (d = decorators[i]) r = (c < 3 ? d(r) : c > 3 ? d(target, key, r) : d(target, key)) || r;
    return c > 3 && r && Object.defineProperty(target, key, r), r;
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.FinanceModule = void 0;
const common_1 = require("@nestjs/common");
const projects_module_1 = require("../projects/projects.module");
const expenses_service_1 = require("./expenses.service");
const finance_controller_1 = require("./finance.controller");
const finance_service_1 = require("./finance.service");
const cost_lines_service_1 = require("./cost-lines.service");
const overhead_service_1 = require("./overhead.service");
let FinanceModule = class FinanceModule {
};
exports.FinanceModule = FinanceModule;
exports.FinanceModule = FinanceModule = __decorate([
    (0, common_1.Module)({
        imports: [projects_module_1.ProjectsModule],
        controllers: [finance_controller_1.FinanceController],
        providers: [expenses_service_1.ExpensesService, finance_service_1.FinanceService, overhead_service_1.OverheadService, cost_lines_service_1.CostLinesService],
        exports: [finance_service_1.FinanceService, expenses_service_1.ExpensesService, overhead_service_1.OverheadService, cost_lines_service_1.CostLinesService],
    })
], FinanceModule);
//# sourceMappingURL=finance.module.js.map
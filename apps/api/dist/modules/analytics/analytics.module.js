"use strict";
var __decorate = (this && this.__decorate) || function (decorators, target, key, desc) {
    var c = arguments.length, r = c < 3 ? target : desc === null ? desc = Object.getOwnPropertyDescriptor(target, key) : desc, d;
    if (typeof Reflect === "object" && typeof Reflect.decorate === "function") r = Reflect.decorate(decorators, target, key, desc);
    else for (var i = decorators.length - 1; i >= 0; i--) if (d = decorators[i]) r = (c < 3 ? d(r) : c > 3 ? d(target, key, r) : d(target, key)) || r;
    return c > 3 && r && Object.defineProperty(target, key, r), r;
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.ClientInsightsModule = exports.AnalyticsModule = void 0;
const common_1 = require("@nestjs/common");
const finance_module_1 = require("../finance/finance.module");
const projects_module_1 = require("../projects/projects.module");
const analytics_controller_1 = require("./analytics.controller");
const accountant_package_service_1 = require("./accountant-package.service");
const analytics_service_1 = require("./analytics.service");
const client_insights_service_1 = require("./client-insights.service");
const export_service_1 = require("./export.service");
const search_service_1 = require("./search.service");
let AnalyticsModule = class AnalyticsModule {
};
exports.AnalyticsModule = AnalyticsModule;
exports.AnalyticsModule = AnalyticsModule = __decorate([
    (0, common_1.Module)({
        imports: [projects_module_1.ProjectsModule, finance_module_1.FinanceModule],
        controllers: [analytics_controller_1.AnalyticsController],
        providers: [
            analytics_service_1.AnalyticsService,
            client_insights_service_1.ClientInsightsService,
            search_service_1.SearchService,
            export_service_1.ExportService,
            accountant_package_service_1.AccountantPackageService,
        ],
        exports: [client_insights_service_1.ClientInsightsService],
    })
], AnalyticsModule);
/** Для worker и планировщика: пересчёт здоровья клиентов без HTTP-контроллера. */
let ClientInsightsModule = class ClientInsightsModule {
};
exports.ClientInsightsModule = ClientInsightsModule;
exports.ClientInsightsModule = ClientInsightsModule = __decorate([
    (0, common_1.Module)({ providers: [client_insights_service_1.ClientInsightsService], exports: [client_insights_service_1.ClientInsightsService] })
], ClientInsightsModule);
//# sourceMappingURL=analytics.module.js.map
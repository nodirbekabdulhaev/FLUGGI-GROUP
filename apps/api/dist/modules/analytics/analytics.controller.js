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
exports.AnalyticsController = void 0;
const common_1 = require("@nestjs/common");
const contracts_1 = require("@fluggi/contracts");
const zod_1 = require("zod");
const decorators_1 = require("../../core/auth/decorators");
const app_exception_1 = require("../../core/http/app.exception");
const uuid_pipe_1 = require("../../core/http/uuid.pipe");
const zod_pipe_1 = require("../../core/http/zod.pipe");
const accountant_package_service_1 = require("./accountant-package.service");
const analytics_service_1 = require("./analytics.service");
const client_insights_service_1 = require("./client-insights.service");
const export_service_1 = require("./export.service");
const search_service_1 = require("./search.service");
const entityPipe = (0, zod_pipe_1.zod)(zod_1.z.enum(contracts_1.EXPORT_ENTITIES));
const packageQuery = zod_1.z.object({ year: zod_1.z.coerce.number().int().min(2020).max(2100) });
/** Аналитика, поиск и экспорт (ТЗ §39–44). */
let AnalyticsController = class AnalyticsController {
    analytics;
    clients;
    searchService;
    exports;
    accountant;
    constructor(analytics, clients, searchService, exports, accountant) {
        this.analytics = analytics;
        this.clients = clients;
        this.searchService = searchService;
        this.exports = exports;
        this.accountant = accountant;
    }
    sales(auth, q) {
        return this.analytics.sales(auth, q);
    }
    funnel(auth, q) {
        return this.analytics.funnel(auth, q);
    }
    sources(auth, q) {
        return this.analytics.breakdown(auth, q, 'source');
    }
    services(auth, q) {
        return this.analytics.breakdown(auth, q, 'service');
    }
    losses(auth, q) {
        return this.analytics.losses(auth, q);
    }
    forecast(auth, q) {
        return this.analytics.forecast(auth, q);
    }
    clientList(auth, q) {
        return this.clients.list(auth, q);
    }
    /** LTV и здоровье одного клиента — для карточки клиента. */
    insight(auth, id) {
        return this.clients.one(auth, id);
    }
    search(auth, q) {
        return this.searchService.search(auth, q.q);
    }
    /** Годовой пакет для бухгалтера (CEO): договоры, оплаты, расчёты с клиентами, расходы, зарплата. */
    async accountantPackage(auth, q, meta, res) {
        if (!auth.permissions['export.run'])
            throw (0, app_exception_1.forbidden)();
        const f = await this.accountant.file(auth, q.year, meta);
        res.setHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        res.setHeader('Content-Disposition', `attachment; filename="${f.filename}"`);
        res.setHeader('Cache-Control', 'no-store');
        return new common_1.StreamableFile(f.buffer);
    }
    async export(auth, entity, q, meta, res) {
        const f = await this.exports.file(auth, entity, q, meta);
        res.setHeader('Content-Type', f.contentType);
        res.setHeader('Content-Disposition', `attachment; filename="${f.filename}"`);
        res.setHeader('X-Export-Rows', String(f.rows));
        res.setHeader('Cache-Control', 'no-store');
        return new common_1.StreamableFile(f.buffer);
    }
};
exports.AnalyticsController = AnalyticsController;
__decorate([
    (0, common_1.Get)('analytics/sales'),
    (0, decorators_1.RequirePermission)('analytics.read'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Query)((0, zod_pipe_1.zod)(contracts_1.analyticsQuerySchema))),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object]),
    __metadata("design:returntype", Promise)
], AnalyticsController.prototype, "sales", null);
__decorate([
    (0, common_1.Get)('analytics/funnel'),
    (0, decorators_1.RequirePermission)('analytics.read'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Query)((0, zod_pipe_1.zod)(contracts_1.analyticsQuerySchema))),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object]),
    __metadata("design:returntype", Promise)
], AnalyticsController.prototype, "funnel", null);
__decorate([
    (0, common_1.Get)('analytics/sources'),
    (0, decorators_1.RequirePermission)('analytics.read'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Query)((0, zod_pipe_1.zod)(contracts_1.analyticsQuerySchema))),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object]),
    __metadata("design:returntype", Promise)
], AnalyticsController.prototype, "sources", null);
__decorate([
    (0, common_1.Get)('analytics/services'),
    (0, decorators_1.RequirePermission)('analytics.read'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Query)((0, zod_pipe_1.zod)(contracts_1.analyticsQuerySchema))),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object]),
    __metadata("design:returntype", Promise)
], AnalyticsController.prototype, "services", null);
__decorate([
    (0, common_1.Get)('analytics/losses'),
    (0, decorators_1.RequirePermission)('analytics.read'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Query)((0, zod_pipe_1.zod)(contracts_1.analyticsQuerySchema))),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object]),
    __metadata("design:returntype", Promise)
], AnalyticsController.prototype, "losses", null);
__decorate([
    (0, common_1.Get)('analytics/forecast'),
    (0, decorators_1.RequirePermission)('analytics.read'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Query)((0, zod_pipe_1.zod)(contracts_1.analyticsQuerySchema))),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object]),
    __metadata("design:returntype", Promise)
], AnalyticsController.prototype, "forecast", null);
__decorate([
    (0, common_1.Get)('analytics/clients'),
    (0, decorators_1.RequirePermission)('analytics.read'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Query)((0, zod_pipe_1.zod)(contracts_1.clientAnalyticsQuerySchema))),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object]),
    __metadata("design:returntype", Promise)
], AnalyticsController.prototype, "clientList", null);
__decorate([
    (0, common_1.Get)('clients/:id/insight'),
    (0, decorators_1.RequirePermission)('client.read'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('id', uuid_pipe_1.UuidPipe)),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String]),
    __metadata("design:returntype", Promise)
], AnalyticsController.prototype, "insight", null);
__decorate([
    (0, common_1.Get)('search'),
    (0, decorators_1.AuthenticatedOnly)(),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Query)((0, zod_pipe_1.zod)(contracts_1.searchQuerySchema))),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object]),
    __metadata("design:returntype", Promise)
], AnalyticsController.prototype, "search", null);
__decorate([
    (0, common_1.Get)('exports/accountant-package'),
    (0, decorators_1.RequirePermission)('finance.company.read', 'ALL'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Query)((0, zod_pipe_1.zod)(packageQuery))),
    __param(2, (0, decorators_1.ReqMeta)()),
    __param(3, (0, common_1.Res)({ passthrough: true })),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object, Object, Object]),
    __metadata("design:returntype", Promise)
], AnalyticsController.prototype, "accountantPackage", null);
__decorate([
    (0, common_1.Get)('exports/:entity'),
    (0, decorators_1.RequirePermission)('export.run'),
    __param(0, (0, decorators_1.CurrentUser)()),
    __param(1, (0, common_1.Param)('entity', entityPipe)),
    __param(2, (0, common_1.Query)((0, zod_pipe_1.zod)(contracts_1.exportQuerySchema))),
    __param(3, (0, decorators_1.ReqMeta)()),
    __param(4, (0, common_1.Res)({ passthrough: true })),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, String, Object, Object, Object]),
    __metadata("design:returntype", Promise)
], AnalyticsController.prototype, "export", null);
exports.AnalyticsController = AnalyticsController = __decorate([
    (0, common_1.Controller)(),
    __metadata("design:paramtypes", [analytics_service_1.AnalyticsService,
        client_insights_service_1.ClientInsightsService,
        search_service_1.SearchService,
        export_service_1.ExportService,
        accountant_package_service_1.AccountantPackageService])
], AnalyticsController);
//# sourceMappingURL=analytics.controller.js.map
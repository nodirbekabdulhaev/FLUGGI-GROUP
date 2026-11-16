"use strict";
var __decorate = (this && this.__decorate) || function (decorators, target, key, desc) {
    var c = arguments.length, r = c < 3 ? target : desc === null ? desc = Object.getOwnPropertyDescriptor(target, key) : desc, d;
    if (typeof Reflect === "object" && typeof Reflect.decorate === "function") r = Reflect.decorate(decorators, target, key, desc);
    else for (var i = decorators.length - 1; i >= 0; i--) if (d = decorators[i]) r = (c < 3 ? d(r) : c > 3 ? d(target, key, r) : d(target, key)) || r;
    return c > 3 && r && Object.defineProperty(target, key, r), r;
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.AppModule = void 0;
const common_1 = require("@nestjs/common");
const core_1 = require("@nestjs/core");
const throttler_1 = require("@nestjs/throttler");
const node_crypto_1 = require("node:crypto");
const nestjs_pino_1 = require("nestjs-pino");
const env_1 = require("./config/env");
const audit_module_1 = require("./core/audit/audit.module");
const auth_module_1 = require("./core/auth/auth.module");
const csrf_guard_1 = require("./core/auth/csrf.guard");
const session_guard_1 = require("./core/auth/session.guard");
const all_exceptions_filter_1 = require("./core/http/all-exceptions.filter");
const outbox_module_1 = require("./core/outbox/outbox.module");
const prisma_module_1 = require("./core/prisma/prisma.module");
const permission_guard_1 = require("./core/rbac/permission.guard");
const client_errors_controller_1 = require("./modules/health/client-errors.controller");
const health_controller_1 = require("./modules/health/health.controller");
const roles_module_1 = require("./modules/roles/roles.module");
const teams_module_1 = require("./modules/teams/teams.module");
const users_module_1 = require("./modules/users/users.module");
const clients_module_1 = require("./modules/clients/clients.module");
const crm_module_1 = require("./modules/crm/crm.module");
const deals_module_1 = require("./modules/deals/deals.module");
const leads_module_1 = require("./modules/leads/leads.module");
const meetings_module_1 = require("./modules/meetings/meetings.module");
const notifications_module_1 = require("./modules/notifications/notifications.module");
const pipeline_module_1 = require("./modules/pipeline/pipeline.module");
const references_module_1 = require("./modules/references/references.module");
const commissions_module_1 = require("./modules/commissions/commissions.module");
const contracts_module_1 = require("./modules/contracts/contracts.module");
const files_module_1 = require("./modules/files/files.module");
const payments_module_1 = require("./modules/payments/payments.module");
const finance_module_1 = require("./modules/finance/finance.module");
const people_module_1 = require("./modules/people/people.module");
const automation_module_1 = require("./modules/automation/automation.module");
const analytics_module_1 = require("./modules/analytics/analytics.module");
const catalog_module_1 = require("./modules/catalog/catalog.module");
const documents_module_1 = require("./modules/documents/documents.module");
const integrations_module_1 = require("./modules/integrations/integrations.module");
const chat_module_1 = require("./modules/chat/chat.module");
const todos_module_1 = require("./modules/todos/todos.module");
const telegram_module_1 = require("./modules/telegram/telegram.module");
const cron_controller_1 = require("./modules/cron/cron.controller");
const settings_service_1 = require("./core/settings/settings.service");
const overdue_module_1 = require("./modules/projects/overdue.module");
const projects_module_1 = require("./modules/projects/projects.module");
const proposals_module_1 = require("./modules/proposals/proposals.module");
const storage_module_1 = require("./core/storage/storage.module");
const idempotency_interceptor_1 = require("./core/idempotency/idempotency.interceptor");
const env = (0, env_1.loadEnv)();
let AppModule = class AppModule {
};
exports.AppModule = AppModule;
exports.AppModule = AppModule = __decorate([
    (0, common_1.Module)({
        imports: [
            nestjs_pino_1.LoggerModule.forRoot({
                pinoHttp: {
                    level: env.LOG_LEVEL,
                    genReqId: (req) => req.headers['x-request-id'] ?? (0, node_crypto_1.randomUUID)(),
                    redact: ['req.headers.cookie', 'req.headers["x-csrf-token"]', 'res.headers["set-cookie"]'],
                    transport: env.NODE_ENV === 'development'
                        ? { target: 'pino-pretty', options: { singleLine: true } }
                        : undefined,
                    autoLogging: { ignore: (req) => req.url === '/api/v1/health' },
                    // В лог — только необходимое: без cookie, заголовков и тел запросов.
                    serializers: {
                        req: (req) => ({
                            id: req.id,
                            method: req.method,
                            url: req.url,
                        }),
                        res: (res) => ({ statusCode: res.statusCode }),
                    },
                },
            }),
            throttler_1.ThrottlerModule.forRoot({
                throttlers: [{ name: 'default', ttl: 60_000, limit: 300 }],
                skipIf: () => env.RATE_LIMIT_DISABLED,
            }),
            prisma_module_1.PrismaModule,
            audit_module_1.AuditModule,
            outbox_module_1.OutboxModule,
            auth_module_1.AuthModule,
            users_module_1.UsersModule,
            teams_module_1.TeamsModule,
            roles_module_1.RolesModule,
            references_module_1.ReferencesModule,
            crm_module_1.CrmModule,
            leads_module_1.LeadsModule,
            clients_module_1.ClientsModule,
            deals_module_1.DealsModule,
            meetings_module_1.MeetingsModule,
            pipeline_module_1.PipelineModule,
            notifications_module_1.NotificationsModule,
            storage_module_1.StorageModule,
            files_module_1.FilesModule,
            proposals_module_1.ProposalsModule,
            contracts_module_1.ContractsModule,
            commissions_module_1.CommissionsModule,
            payments_module_1.PaymentsModule,
            projects_module_1.ProjectsModule,
            overdue_module_1.OverdueModule,
            finance_module_1.FinanceModule,
            people_module_1.PeopleModule,
            settings_service_1.SettingsModule,
            telegram_module_1.TelegramModule,
            automation_module_1.AutomationModule,
            cron_controller_1.CronModule,
            analytics_module_1.AnalyticsModule,
            todos_module_1.TodosModule,
            chat_module_1.ChatModule,
            catalog_module_1.CatalogModule,
            documents_module_1.DocumentsModule,
            integrations_module_1.IntegrationsModule,
        ],
        controllers: [health_controller_1.HealthController, client_errors_controller_1.ClientErrorsController],
        providers: [
            { provide: core_1.APP_FILTER, useClass: all_exceptions_filter_1.AllExceptionsFilter },
            // Порядок важен: лимит → сессия → CSRF → права.
            { provide: core_1.APP_GUARD, useClass: throttler_1.ThrottlerGuard },
            { provide: core_1.APP_GUARD, useClass: session_guard_1.SessionGuard },
            { provide: core_1.APP_GUARD, useClass: csrf_guard_1.CsrfGuard },
            { provide: core_1.APP_GUARD, useClass: permission_guard_1.PermissionGuard },
            { provide: core_1.APP_INTERCEPTOR, useClass: idempotency_interceptor_1.IdempotencyInterceptor },
        ],
    })
], AppModule);
//# sourceMappingURL=app.module.js.map
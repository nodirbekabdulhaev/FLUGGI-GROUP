"use strict";
var __decorate = (this && this.__decorate) || function (decorators, target, key, desc) {
    var c = arguments.length, r = c < 3 ? target : desc === null ? desc = Object.getOwnPropertyDescriptor(target, key) : desc, d;
    if (typeof Reflect === "object" && typeof Reflect.decorate === "function") r = Reflect.decorate(decorators, target, key, desc);
    else for (var i = decorators.length - 1; i >= 0; i--) if (d = decorators[i]) r = (c < 3 ? d(r) : c > 3 ? d(target, key, r) : d(target, key)) || r;
    return c > 3 && r && Object.defineProperty(target, key, r), r;
};
Object.defineProperty(exports, "__esModule", { value: true });
/**
 * Worker-процесс: доставка доменных событий из outbox подписчикам (уведомления, автоматизация),
 * отправка сообщений в Telegram и планировщик задач (отчёты, напоминания, просрочки).
 * В production API запускается с OUTBOX_IN_API=false, и фоновой работой занимается только worker.
 */
require("reflect-metadata");
const common_1 = require("@nestjs/common");
const core_1 = require("@nestjs/core");
const audit_module_1 = require("./core/audit/audit.module");
const outbox_module_1 = require("./core/outbox/outbox.module");
const prisma_module_1 = require("./core/prisma/prisma.module");
const settings_service_1 = require("./core/settings/settings.service");
const automation_module_1 = require("./modules/automation/automation.module");
const crm_module_1 = require("./modules/crm/crm.module");
const finance_module_1 = require("./modules/finance/finance.module");
const references_module_1 = require("./modules/references/references.module");
const telegram_module_1 = require("./modules/telegram/telegram.module");
// Worker обрабатывает outbox всегда, независимо от настройки API.
process.env.OUTBOX_IN_API = 'true';
let WorkerModule = class WorkerModule {
};
WorkerModule = __decorate([
    (0, common_1.Module)({
        imports: [
            prisma_module_1.PrismaModule,
            audit_module_1.AuditModule,
            outbox_module_1.OutboxModule,
            settings_service_1.SettingsModule,
            telegram_module_1.TelegramWorkerModule,
            crm_module_1.CrmModule,
            references_module_1.ReferencesModule,
            automation_module_1.AutomationModule,
            // Себестоимость проекта по тарифу (события project.created / project.member_added)
            finance_module_1.FinanceModule,
        ],
    })
], WorkerModule);
// Подписчики уведомлений приходят через AutomationModule → NotificationsModule (одним экземпляром).
async function bootstrap() {
    const app = await core_1.NestFactory.createApplicationContext(WorkerModule);
    app.enableShutdownHooks();
    new common_1.Logger('Worker').log('Worker started');
}
void bootstrap();
//# sourceMappingURL=worker.js.map
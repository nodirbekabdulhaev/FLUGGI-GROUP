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
var CronController_1;
Object.defineProperty(exports, "__esModule", { value: true });
exports.CronModule = exports.CronController = void 0;
const node_crypto_1 = require("node:crypto");
const common_1 = require("@nestjs/common");
const throttler_1 = require("@nestjs/throttler");
const env_1 = require("../../config/env");
const decorators_1 = require("../../core/auth/decorators");
const outbox_dispatcher_1 = require("../../core/outbox/outbox.dispatcher");
const outbox_module_1 = require("../../core/outbox/outbox.module");
const automation_module_1 = require("../automation/automation.module");
const scheduler_service_1 = require("../automation/scheduler.service");
const telegram_module_1 = require("../telegram/telegram.module");
const telegram_runners_1 = require("../telegram/telegram.runners");
/** Сколько секунд один вызов может разбирать очередь (cron вызывает раз в минуту). */
const BUDGET_MS = 40_000;
function secretMatches(expected, given) {
    if (!given)
        return false;
    const a = Buffer.from(expected);
    const b = Buffer.from(given);
    return a.length === b.length && (0, node_crypto_1.timingSafeEqual)(a, b);
}
/**
 * Фоновые задачи по внешнему cron — для виртуального хостинга, где нет постоянного
 * worker-процесса (Passenger останавливает приложение без запросов). Раз в минуту:
 * доставка событий outbox, отправка в Telegram, задачи планировщика.
 * Повторные и параллельные вызовы безопасны: строки блокируются FOR UPDATE SKIP LOCKED,
 * а каждая задача планировщика выполняется один раз за свой слот (таблица job_runs).
 */
let CronController = CronController_1 = class CronController {
    outbox;
    sender;
    telegram;
    scheduler;
    logger = new common_1.Logger(CronController_1.name);
    constructor(outbox, sender, telegram, scheduler) {
        this.outbox = outbox;
        this.sender = sender;
        this.telegram = telegram;
        this.scheduler = scheduler;
    }
    async run(secret) {
        const env = (0, env_1.loadEnv)();
        // Без секрета эндпоинт не существует — его нельзя дёргать извне
        if (!env.CRON_SECRET || !secretMatches(env.CRON_SECRET, secret))
            throw new common_1.NotFoundException();
        const started = Date.now();
        let events = 0;
        let telegram = 0;
        for (let n = 1; n > 0 && Date.now() - started < BUDGET_MS;) {
            n = await this.outbox.processBatch();
            events += n;
        }
        if (env.TELEGRAM_BOT_TOKEN) {
            for (let n = 1; n > 0 && Date.now() - started < BUDGET_MS;) {
                n = await this.sender.processBatch();
                telegram += n;
            }
            await this.telegram.check();
        }
        if (env.SCHEDULER_ENABLED)
            await this.scheduler.tick();
        const result = { events, telegram, ms: Date.now() - started };
        if (events || telegram)
            this.logger.log(`Cron: событий ${events}, Telegram ${telegram}`);
        return result;
    }
};
exports.CronController = CronController;
__decorate([
    (0, common_1.Post)(),
    (0, decorators_1.Public)(),
    (0, throttler_1.SkipThrottle)(),
    (0, common_1.HttpCode)(200),
    __param(0, (0, common_1.Headers)('x-cron-secret')),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object]),
    __metadata("design:returntype", Promise)
], CronController.prototype, "run", null);
exports.CronController = CronController = CronController_1 = __decorate([
    (0, common_1.Controller)('internal/cron'),
    __metadata("design:paramtypes", [outbox_dispatcher_1.OutboxDispatcher,
        telegram_runners_1.TelegramSender,
        telegram_runners_1.TelegramRunner,
        scheduler_service_1.SchedulerService])
], CronController);
let CronModule = class CronModule {
};
exports.CronModule = CronModule;
exports.CronModule = CronModule = __decorate([
    (0, common_1.Module)({
        imports: [outbox_module_1.OutboxModule, telegram_module_1.TelegramModule, automation_module_1.AutomationModule],
        controllers: [CronController],
    })
], CronModule);
//# sourceMappingURL=cron.controller.js.map
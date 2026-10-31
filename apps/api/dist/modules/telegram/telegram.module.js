"use strict";
var __decorate = (this && this.__decorate) || function (decorators, target, key, desc) {
    var c = arguments.length, r = c < 3 ? target : desc === null ? desc = Object.getOwnPropertyDescriptor(target, key) : desc, d;
    if (typeof Reflect === "object" && typeof Reflect.decorate === "function") r = Reflect.decorate(decorators, target, key, desc);
    else for (var i = decorators.length - 1; i >= 0; i--) if (d = decorators[i]) r = (c < 3 ? d(r) : c > 3 ? d(target, key, r) : d(target, key)) || r;
    return c > 3 && r && Object.defineProperty(target, key, r), r;
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.TelegramWorkerModule = exports.TelegramModule = void 0;
const common_1 = require("@nestjs/common");
const notifications_module_1 = require("../notifications/notifications.module");
const telegram_client_1 = require("./telegram.client");
const telegram_controller_1 = require("./telegram.controller");
const telegram_runners_1 = require("./telegram.runners");
const telegram_service_1 = require("./telegram.service");
let TelegramModule = class TelegramModule {
};
exports.TelegramModule = TelegramModule;
exports.TelegramModule = TelegramModule = __decorate([
    (0, common_1.Module)({
        imports: [notifications_module_1.NotificationsModule],
        controllers: [telegram_controller_1.TelegramController],
        providers: [telegram_client_1.TelegramClient, telegram_service_1.TelegramService, telegram_runners_1.TelegramSender, telegram_runners_1.TelegramRunner],
        exports: [telegram_client_1.TelegramClient, telegram_runners_1.TelegramSender, telegram_service_1.TelegramService, telegram_runners_1.TelegramRunner],
    })
], TelegramModule);
/** Для worker: отправка и polling без HTTP-контроллера. */
let TelegramWorkerModule = class TelegramWorkerModule {
};
exports.TelegramWorkerModule = TelegramWorkerModule;
exports.TelegramWorkerModule = TelegramWorkerModule = __decorate([
    (0, common_1.Module)({
        providers: [telegram_client_1.TelegramClient, telegram_service_1.TelegramService, telegram_runners_1.TelegramSender, telegram_runners_1.TelegramRunner],
        exports: [telegram_runners_1.TelegramSender],
    })
], TelegramWorkerModule);
//# sourceMappingURL=telegram.module.js.map
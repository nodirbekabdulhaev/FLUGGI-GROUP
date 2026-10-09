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
exports.TelegramController = void 0;
const node_crypto_1 = require("node:crypto");
const common_1 = require("@nestjs/common");
const env_1 = require("../../config/env");
const decorators_1 = require("../../core/auth/decorators");
const app_exception_1 = require("../../core/http/app.exception");
const notifications_service_1 = require("../notifications/notifications.service");
const telegram_service_1 = require("./telegram.service");
let TelegramController = class TelegramController {
    telegram;
    notifications;
    constructor(telegram, notifications) {
        this.telegram = telegram;
        this.notifications = notifications;
    }
    status(auth) {
        return this.telegram.status(auth);
    }
    link(auth) {
        return this.telegram.createLink(auth);
    }
    unlink(auth) {
        return this.telegram.unlink(auth);
    }
    /** Тестовое сообщение себе — проверить, что уведомления доходят. */
    async test(auth) {
        const s = await this.telegram.status(auth);
        if (!s.linked)
            throw (0, app_exception_1.businessRule)('Сначала подключите Telegram');
        await this.notifications.notify([auth.userId], {
            type: 'test',
            title: 'Проверка связи',
            body: 'Уведомления Fluggi CRM приходят в Telegram ✅',
            link: '/profile',
        }, null, { inApp: false });
        return { queued: true };
    }
    /** Webhook Telegram (режим webhook). Проверяется секретный заголовок. */
    async webhook(secret, update) {
        const expected = (0, env_1.loadEnv)().TELEGRAM_WEBHOOK_SECRET;
        const ok = Boolean(expected && secret) &&
            Buffer.from(secret).length === Buffer.from(expected).length &&
            (0, node_crypto_1.timingSafeEqual)(Buffer.from(secret), Buffer.from(expected));
        if (!ok)
            throw new app_exception_1.AppException('FORBIDDEN', 'Недопустимый запрос');
        await this.telegram.handleUpdate(update);
        return { ok: true };
    }
};
exports.TelegramController = TelegramController;
__decorate([
    (0, common_1.Get)('me/telegram'),
    (0, decorators_1.AuthenticatedOnly)(),
    __param(0, (0, decorators_1.CurrentUser)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object]),
    __metadata("design:returntype", Promise)
], TelegramController.prototype, "status", null);
__decorate([
    (0, common_1.Post)('me/telegram/link'),
    (0, common_1.HttpCode)(200),
    (0, decorators_1.AuthenticatedOnly)(),
    __param(0, (0, decorators_1.CurrentUser)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object]),
    __metadata("design:returntype", Promise)
], TelegramController.prototype, "link", null);
__decorate([
    (0, common_1.Delete)('me/telegram'),
    (0, common_1.HttpCode)(204),
    (0, decorators_1.AuthenticatedOnly)(),
    __param(0, (0, decorators_1.CurrentUser)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object]),
    __metadata("design:returntype", Promise)
], TelegramController.prototype, "unlink", null);
__decorate([
    (0, common_1.Post)('me/telegram/test'),
    (0, common_1.HttpCode)(200),
    (0, decorators_1.AuthenticatedOnly)(),
    __param(0, (0, decorators_1.CurrentUser)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object]),
    __metadata("design:returntype", Promise)
], TelegramController.prototype, "test", null);
__decorate([
    (0, common_1.Post)('telegram/webhook'),
    (0, common_1.HttpCode)(200),
    (0, decorators_1.Public)(),
    __param(0, (0, common_1.Headers)('x-telegram-bot-api-secret-token')),
    __param(1, (0, common_1.Body)()),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [Object, Object]),
    __metadata("design:returntype", Promise)
], TelegramController.prototype, "webhook", null);
exports.TelegramController = TelegramController = __decorate([
    (0, common_1.Controller)(),
    __metadata("design:paramtypes", [telegram_service_1.TelegramService,
        notifications_service_1.NotificationsService])
], TelegramController);
//# sourceMappingURL=telegram.controller.js.map
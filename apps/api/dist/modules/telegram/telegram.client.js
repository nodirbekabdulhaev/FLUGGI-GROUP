"use strict";
var __decorate = (this && this.__decorate) || function (decorators, target, key, desc) {
    var c = arguments.length, r = c < 3 ? target : desc === null ? desc = Object.getOwnPropertyDescriptor(target, key) : desc, d;
    if (typeof Reflect === "object" && typeof Reflect.decorate === "function") r = Reflect.decorate(decorators, target, key, desc);
    else for (var i = decorators.length - 1; i >= 0; i--) if (d = decorators[i]) r = (c < 3 ? d(r) : c > 3 ? d(target, key, r) : d(target, key)) || r;
    return c > 3 && r && Object.defineProperty(target, key, r), r;
};
var TelegramClient_1;
Object.defineProperty(exports, "__esModule", { value: true });
exports.TelegramClient = exports.TelegramApiError = exports.backgroundEnabled = void 0;
const common_1 = require("@nestjs/common");
const undici_1 = require("undici");
const env_1 = require("../../config/env");
/** Где работают фоновые циклы: в worker, а в разработке — и в API (как outbox). */
const backgroundEnabled = () => process.env.OUTBOX_IN_API !== 'false';
exports.backgroundEnabled = backgroundEnabled;
class TelegramApiError extends Error {
    status;
    description;
    retryAfter;
    constructor(status, description, retryAfter) {
        super(`Telegram ${status}: ${description}`);
        this.status = status;
        this.description = description;
        this.retryAfter = retryAfter;
    }
    /** Бот заблокирован пользователем или чат не найден — повторять бессмысленно. */
    get permanent() {
        return this.status === 403 || this.status === 400;
    }
}
exports.TelegramApiError = TelegramApiError;
/** Тонкий клиент Telegram Bot API (без сторонних библиотек). */
let TelegramClient = TelegramClient_1 = class TelegramClient {
    logger = new common_1.Logger(TelegramClient_1.name);
    get configured() {
        return Boolean((0, env_1.loadEnv)().TELEGRAM_BOT_TOKEN);
    }
    get username() {
        return (0, env_1.loadEnv)().TELEGRAM_BOT_USERNAME ?? null;
    }
    proxy = null;
    /** Прокси из TELEGRAM_PROXY_URL (создаётся один раз). */
    dispatcher() {
        const url = (0, env_1.loadEnv)().TELEGRAM_PROXY_URL;
        if (!url)
            return undefined;
        if (this.proxy?.url !== url)
            this.proxy = {
                url,
                dispatcher: url.startsWith('socks5://') ? new undici_1.Socks5ProxyAgent(url) : new undici_1.ProxyAgent(url),
            };
        return this.proxy.dispatcher;
    }
    async call(method, body, timeoutMs = 15_000) {
        const env = (0, env_1.loadEnv)();
        if (!env.TELEGRAM_BOT_TOKEN)
            throw new TelegramApiError(0, 'Бот не настроен');
        const res = await (0, undici_1.fetch)(`${env.TELEGRAM_API_BASE}/bot${env.TELEGRAM_BOT_TOKEN}/${method}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
            signal: AbortSignal.timeout(timeoutMs),
            dispatcher: this.dispatcher(),
        });
        const data = (await res.json().catch(() => ({})));
        if (!res.ok || !data.ok)
            throw new TelegramApiError(res.status, data.description ?? res.statusText, data.parameters?.retry_after);
        return data.result;
    }
    sendMessage(chatId, html) {
        return this.call('sendMessage', {
            chat_id: chatId,
            text: html,
            parse_mode: 'HTML',
            disable_web_page_preview: true,
        });
    }
    getUpdates(offset, timeoutSec) {
        return this.call('getUpdates', { offset, timeout: timeoutSec, allowed_updates: ['message'] }, (timeoutSec + 10) * 1000);
    }
    async getMe() {
        return this.call('getMe', {});
    }
    /** Экранирование для parse_mode=HTML. */
    static escape(text) {
        return text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }
};
exports.TelegramClient = TelegramClient;
exports.TelegramClient = TelegramClient = TelegramClient_1 = __decorate([
    (0, common_1.Injectable)()
], TelegramClient);
//# sourceMappingURL=telegram.client.js.map
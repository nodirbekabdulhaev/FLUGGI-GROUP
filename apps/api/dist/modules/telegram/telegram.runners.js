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
var TelegramSender_1, TelegramRunner_1;
Object.defineProperty(exports, "__esModule", { value: true });
exports.TelegramRunner = exports.TelegramSender = exports.backgroundEnabled = void 0;
const common_1 = require("@nestjs/common");
const env_1 = require("../../config/env");
const prisma_service_1 = require("../../core/prisma/prisma.service");
const telegram_client_1 = require("./telegram.client");
const telegram_service_1 = require("./telegram.service");
const MAX_ATTEMPTS = 5;
/** Пауза перед повтором: 30 с, 2 мин, 8 мин, 30 мин. */
const backoff = (attempt) => Math.min(30_000 * 4 ** (attempt - 1), 30 * 60_000);
var telegram_client_2 = require("./telegram.client");
Object.defineProperty(exports, "backgroundEnabled", { enumerable: true, get: function () { return telegram_client_2.backgroundEnabled; } });
/**
 * Доставка уведомлений в Telegram (ТЗ §53): очередь в БД, повторы с паузой,
 * журнал ошибок. Сбой Telegram не влияет на бизнес-операции.
 */
let TelegramSender = TelegramSender_1 = class TelegramSender {
    prisma;
    client;
    logger = new common_1.Logger(TelegramSender_1.name);
    constructor(prisma, client) {
        this.prisma = prisma;
        this.client = client;
    }
    async processBatch(limit = 20) {
        if (!this.client.configured)
            return 0;
        const appUrl = (0, env_1.loadEnv)().APP_URL;
        return this.prisma.$transaction(async (tx) => {
            const rows = await tx.$queryRaw `
          SELECT id FROM notification_deliveries
          WHERE status = 'PENDING' AND next_attempt_at <= UTC_TIMESTAMP(3)
          ORDER BY created_at LIMIT ${limit} ${this.prisma.lockRows}`;
            for (const { id } of rows) {
                const d = await tx.notificationDelivery.findUniqueOrThrow({
                    where: { id },
                    include: { user: true },
                });
                if (!d.user.telegramChatId) {
                    await tx.notificationDelivery.update({
                        where: { id },
                        data: { status: 'FAILED', lastError: 'Telegram не подключён' },
                    });
                    continue;
                }
                const text = [
                    `<b>${telegram_client_1.TelegramClient.escape(d.title)}</b>`,
                    d.body ? telegram_client_1.TelegramClient.escape(d.body) : null,
                    d.link ? `<a href="${appUrl}${d.link}">Открыть в CRM</a>` : null,
                ]
                    .filter(Boolean)
                    .join('\n');
                try {
                    await this.client.sendMessage(d.user.telegramChatId, text);
                    await tx.notificationDelivery.update({
                        where: { id },
                        data: { status: 'SENT', sentAt: new Date(), attempts: { increment: 1 } },
                    });
                }
                catch (err) {
                    const e = err;
                    const attempts = d.attempts + 1;
                    const final = (e instanceof telegram_client_1.TelegramApiError && e.permanent) || attempts >= MAX_ATTEMPTS;
                    await tx.notificationDelivery.update({
                        where: { id },
                        data: {
                            attempts,
                            lastError: String(e.message).slice(0, 500),
                            status: final ? 'FAILED' : 'PENDING',
                            nextAttemptAt: new Date(Date.now() + (e.retryAfter ? e.retryAfter * 1000 : backoff(attempts))),
                        },
                    });
                }
            }
            return rows.length;
        }, { timeout: 60_000 });
    }
};
exports.TelegramSender = TelegramSender;
exports.TelegramSender = TelegramSender = TelegramSender_1 = __decorate([
    (0, common_1.Injectable)(),
    __metadata("design:paramtypes", [prisma_service_1.PrismaService,
        telegram_client_1.TelegramClient])
], TelegramSender);
const HEARTBEAT_MS = 30_000;
let TelegramRunner = TelegramRunner_1 = class TelegramRunner {
    sender;
    client;
    telegram;
    logger = new common_1.Logger(TelegramRunner_1.name);
    stopped = false;
    offset = 0;
    bot = null;
    lastBeat = 0;
    constructor(sender, client, telegram) {
        this.sender = sender;
        this.client = client;
        this.telegram = telegram;
    }
    onApplicationBootstrap() {
        if (!(0, telegram_client_1.backgroundEnabled)() || !this.client.configured)
            return;
        const mode = (0, env_1.loadEnv)().TELEGRAM_MODE;
        void this.check().then((ok) => {
            if (ok)
                this.logger.log(`Telegram-бот @${this.bot} подключён, режим ${mode}`);
            void this.sendLoop();
            if (mode === 'polling')
                void this.pollLoop();
            else
                void this.webhookHeartbeat();
        });
    }
    onApplicationShutdown() {
        this.stopped = true;
    }
    /**
     * Проверка токена (getMe) и имени бота. Результат сохраняется в БД: профиль показывает
     * причину сбоя, а если проверок давно не было — что бот не запущен.
     */
    async check() {
        let problem = null;
        try {
            this.bot = (await this.client.getMe()).username;
            const configured = this.client.username;
            if (configured && configured.toLowerCase() !== this.bot.toLowerCase())
                problem = `TELEGRAM_BOT_USERNAME (@${configured}) не совпадает с ботом, которому принадлежит токен (@${this.bot}). Исправьте .env или удалите TELEGRAM_BOT_USERNAME и перезапустите API`;
        }
        catch (err) {
            problem = telegram_service_1.TelegramService.explain(err);
        }
        if (problem)
            this.logger.error(`Telegram: ${problem}`);
        await this.beat(problem, true);
        return !problem;
    }
    async beat(problem, force = false) {
        if (!force && !problem && Date.now() - this.lastBeat < HEARTBEAT_MS)
            return;
        this.lastBeat = Date.now();
        await this.telegram
            .reportHealth({ problem, bot: this.bot })
            .catch((err) => this.logger.warn(`Telegram health: ${err.message}`));
    }
    sleep(ms) {
        return new Promise((r) => setTimeout(r, ms).unref());
    }
    async sendLoop() {
        while (!this.stopped) {
            try {
                const n = await this.sender.processBatch();
                await this.sleep(n > 0 ? 200 : 3000);
            }
            catch (err) {
                this.logger.error(err, 'Telegram send batch failed');
                await this.sleep(15_000);
            }
        }
    }
    /** Режим webhook: сообщения принимает API, здесь — только периодическая проверка токена. */
    async webhookHeartbeat() {
        while (!this.stopped) {
            await this.sleep(60_000);
            await this.check();
        }
    }
    /** Режим polling: бот сам забирает сообщения — подходит для локального запуска без домена. */
    async pollLoop() {
        let failing = false;
        while (!this.stopped) {
            try {
                const updates = await this.client.getUpdates(this.offset, 25);
                if (failing)
                    this.logger.log('Telegram polling восстановлен');
                // После сбоя (или если бот не был доступен при запуске) — полная проверка: имя бота,
                // соответствие .env; иначе профиль ещё долго показывал бы старую ошибку
                if (failing || !this.bot)
                    await this.check();
                else
                    await this.beat(null);
                failing = false;
                for (const u of updates) {
                    this.offset = u.update_id + 1;
                    await this.telegram.handleUpdate(u);
                }
            }
            catch (err) {
                const e = err;
                failing = true;
                await this.beat(telegram_service_1.TelegramService.explain(err), true);
                this.logger.warn(`Telegram polling: ${e.message}`);
                // 409 — запущен webhook или второй poller; ждём дольше
                await this.sleep(e.status === 409 ? 60_000 : 10_000);
            }
        }
    }
};
exports.TelegramRunner = TelegramRunner;
exports.TelegramRunner = TelegramRunner = TelegramRunner_1 = __decorate([
    (0, common_1.Injectable)(),
    __metadata("design:paramtypes", [TelegramSender,
        telegram_client_1.TelegramClient,
        telegram_service_1.TelegramService])
], TelegramRunner);
//# sourceMappingURL=telegram.runners.js.map
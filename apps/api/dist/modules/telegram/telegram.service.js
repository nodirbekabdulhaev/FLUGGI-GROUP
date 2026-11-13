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
var TelegramService_1;
Object.defineProperty(exports, "__esModule", { value: true });
exports.TelegramService = void 0;
const node_crypto_1 = require("node:crypto");
const common_1 = require("@nestjs/common");
const app_exception_1 = require("../../core/http/app.exception");
const prisma_service_1 = require("../../core/prisma/prisma.service");
const telegram_client_1 = require("./telegram.client");
const LINK_TTL_MS = 15 * 60_000;
const HEALTH_KEY = 'telegram.health';
const hash = (t) => (0, node_crypto_1.createHash)('sha256').update(t).digest('hex');
/**
 * Привязка Telegram к аккаунту (ТЗ §53): CRM выдаёт одноразовый код,
 * сотрудник отправляет боту /start <код>, бот сохраняет telegram_chat_id.
 */
let TelegramService = class TelegramService {
    static { TelegramService_1 = this; }
    prisma;
    client;
    logger = new common_1.Logger(TelegramService_1.name);
    /** Бот «молчит» дольше этого — значит, сообщения никто не принимает. */
    static STALE_MS = 2 * 60_000;
    /** Понятная причина сбоя Telegram для CEO. */
    static explain(err) {
        if (err instanceof telegram_client_1.TelegramApiError) {
            if (err.status === 401 || err.status === 404)
                return 'Токен бота недействителен (возможно, его заменили в @BotFather). Укажите актуальный TELEGRAM_BOT_TOKEN в .env и перезапустите API';
            if (err.status === 409)
                return 'Бот получает сообщения в другом месте: у него включён webhook или с этим токеном запущен второй сервер';
            return `Telegram ответил ошибкой: ${err.description}`;
        }
        const e = err;
        const codes = [e.cause?.code, ...(e.cause?.errors ?? []).map((x) => x.code)].filter(Boolean);
        const reason = e.name === 'TimeoutError'
            ? 'превышено время ожидания'
            : codes.length
                ? [...new Set(codes)].join(', ')
                : (e.cause?.message ?? e.message);
        return `Сервер не может подключиться к Telegram (${reason}). Если Telegram у вас работает только через VPN или прокси — укажите прокси в TELEGRAM_PROXY_URL в .env (http://, https:// или socks5://) или включите VPN в режиме для всей системы, затем перезапустите API`;
    }
    /**
     * Состояние бота пишет фоновый процесс (API в разработке или worker) — в БД,
     * чтобы любой экземпляр API видел, принимает ли кто-то сообщения.
     */
    async reportHealth(h) {
        const value = { ...h, at: new Date().toISOString() };
        await this.prisma.setting.upsert({
            where: { key: HEALTH_KEY },
            update: { value },
            create: { key: HEALTH_KEY, value },
        });
    }
    async health() {
        const row = await this.prisma.setting.findUnique({ where: { key: HEALTH_KEY } });
        return row?.value ?? null;
    }
    /** Почему бот может не отвечать — показывается в профиле. */
    async currentProblem() {
        if (!this.client.configured)
            return null;
        const h = await this.health();
        if (!h || Date.now() - new Date(h.at).getTime() > TelegramService_1.STALE_MS)
            return 'Бот не запущен: сообщения боту сейчас никто не принимает. Перезапустите API (после изменения .env это обязательно); в production — запустите worker. В логе при запуске должно быть «Telegram-бот @… подключён»';
        return h.problem;
    }
    constructor(prisma, client) {
        this.prisma = prisma;
        this.client = client;
    }
    /** Имя бота: настоящее (из getMe, его сохраняет фоновый процесс), иначе из .env. */
    async username() {
        return (await this.health())?.bot ?? this.client.username;
    }
    async status(auth) {
        const user = await this.prisma.user.findUniqueOrThrow({ where: { id: auth.userId } });
        return {
            botConfigured: this.client.configured,
            botUsername: await this.username(),
            linked: Boolean(user.telegramChatId),
            username: user.telegramUsername,
            problem: await this.currentProblem(),
        };
    }
    async createLink(auth) {
        if (!this.client.configured)
            throw (0, app_exception_1.businessRule)('Telegram-бот не настроен: CEO должен указать TELEGRAM_BOT_TOKEN на сервере');
        const problem = await this.currentProblem();
        if (problem)
            throw (0, app_exception_1.businessRule)(problem);
        const bot = await this.username();
        if (!bot)
            throw (0, app_exception_1.businessRule)('Не удалось получить имя бота. Укажите TELEGRAM_BOT_USERNAME');
        const token = (0, node_crypto_1.randomBytes)(18).toString('base64url');
        const expiresAt = new Date(Date.now() + LINK_TTL_MS);
        await this.prisma.telegramLinkToken.create({
            data: { tokenHash: hash(token), userId: auth.userId, expiresAt },
        });
        return {
            deepLink: `https://t.me/${bot}?start=${token}`,
            command: `/start ${token}`,
            expiresAt: expiresAt.toISOString(),
        };
    }
    async unlink(auth) {
        await this.prisma.user.update({
            where: { id: auth.userId },
            data: { telegramChatId: null, telegramUsername: null },
        });
    }
    /** Обработка входящего сообщения боту (webhook или polling). */
    async handleUpdate(update) {
        const msg = update.message;
        if (!msg?.text || msg.chat.type !== 'private')
            return;
        const chatId = String(msg.chat.id);
        const [command, arg] = msg.text.trim().split(/\s+/, 2);
        const reply = (text) => this.client
            .sendMessage(chatId, text)
            .catch((err) => this.logger.warn(err.message));
        if (command === '/start' && arg) {
            const row = await this.prisma.telegramLinkToken.findUnique({
                where: { tokenHash: hash(arg) },
                include: { user: true },
            });
            if (!row || row.usedAt || row.expiresAt < new Date() || row.user.status !== 'ACTIVE') {
                await reply('Код недействителен или устарел. Получите новый в профиле CRM: «Подключить Telegram».');
                return;
            }
            await this.prisma.$transaction([
                // Один чат — один аккаунт
                this.prisma.user.updateMany({
                    where: { telegramChatId: chatId, id: { not: row.userId } },
                    data: { telegramChatId: null, telegramUsername: null },
                }),
                this.prisma.user.update({
                    where: { id: row.userId },
                    data: { telegramChatId: chatId, telegramUsername: msg.from?.username ?? null },
                }),
                this.prisma.telegramLinkToken.update({
                    where: { id: row.id },
                    data: { usedAt: new Date() },
                }),
            ]);
            await reply(`✅ Telegram подключён к Fluggi CRM.\n<b>${telegram_client_1.TelegramClient.escape(row.user.fullName)}</b>, сюда будут приходить уведомления. Настроить их можно в профиле CRM.\n\nОтключить: /stop`);
            return;
        }
        if (command === '/stop') {
            const n = await this.prisma.user.updateMany({
                where: { telegramChatId: chatId },
                data: { telegramChatId: null, telegramUsername: null },
            });
            await reply(n.count
                ? 'Уведомления отключены. Подключить снова можно в профиле CRM.'
                : 'Этот чат не подключён к CRM.');
            return;
        }
        const linked = await this.prisma.user.findFirst({ where: { telegramChatId: chatId } });
        await reply(linked
            ? `Вы подключены как <b>${telegram_client_1.TelegramClient.escape(linked.fullName)}</b>. Уведомления приходят автоматически.\nОтключить: /stop`
            : 'Это бот Fluggi CRM. Чтобы подключиться, откройте в CRM «Профиль → Подключить Telegram».');
    }
};
exports.TelegramService = TelegramService;
exports.TelegramService = TelegramService = TelegramService_1 = __decorate([
    (0, common_1.Injectable)(),
    __metadata("design:paramtypes", [prisma_service_1.PrismaService,
        telegram_client_1.TelegramClient])
], TelegramService);
//# sourceMappingURL=telegram.service.js.map
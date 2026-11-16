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
Object.defineProperty(exports, "__esModule", { value: true });
exports.NotificationsService = void 0;
const common_1 = require("@nestjs/common");
const contracts_1 = require("@fluggi/contracts");
const env_1 = require("../../config/env");
const prisma_service_1 = require("../../core/prisma/prisma.service");
let NotificationsService = class NotificationsService {
    prisma;
    constructor(prisma) {
        this.prisma = prisma;
    }
    /**
     * Уведомление пользователям (ТЗ §14, §53): in-app и Telegram с учётом личных настроек.
     * Дубли получателей и «сам себе» отсекаются. В Telegram — только если бот настроен
     * и сотрудник подключил чат; отправку делает фоновая очередь с повторами.
     */
    async notify(userIds, n, exceptUserId, opts = {}) {
        const ids = [
            ...new Set(userIds.filter((id) => Boolean(id) && id !== exceptUserId)),
        ];
        if (ids.length === 0)
            return;
        const group = contracts_1.NOTIFICATION_TYPE_GROUP[n.type] ?? n.type;
        const [off, users] = await Promise.all([
            this.prisma.notificationSetting.findMany({
                where: { userId: { in: ids }, eventType: group, enabled: false },
            }),
            this.prisma.user.findMany({
                where: { id: { in: ids }, status: 'ACTIVE', deletedAt: null },
                select: { id: true, telegramChatId: true },
            }),
        ]);
        const disabled = (userId, channel) => off.some((o) => o.userId === userId && o.channel === channel);
        const active = users.map((u) => u.id);
        const inApp = opts.inApp === false ? [] : active.filter((id) => !disabled(id, 'IN_APP'));
        const telegram = (0, env_1.loadEnv)().TELEGRAM_BOT_TOKEN
            ? users.filter((u) => u.telegramChatId && !disabled(u.id, 'TELEGRAM')).map((u) => u.id)
            : [];
        if (inApp.length)
            await this.prisma.notification.createMany({
                data: inApp.map((userId) => ({
                    userId,
                    type: n.type,
                    title: n.title,
                    body: n.body,
                    link: n.link,
                })),
            });
        if (telegram.length)
            await this.prisma.notificationDelivery.createMany({
                data: telegram.map((userId) => ({
                    userId,
                    channel: 'TELEGRAM',
                    type: n.type,
                    title: n.title,
                    body: n.body,
                    link: n.link,
                })),
            });
    }
    /** Личные настройки уведомлений: типы для роли пользователя и состояние каналов. */
    async settings(userId, role) {
        const rows = await this.prisma.notificationSetting.findMany({ where: { userId } });
        const isOn = (type, channel) => !rows.some((r) => r.eventType === type && r.channel === channel && !r.enabled);
        return contracts_1.NOTIFICATION_EVENTS.filter((e) => role === 'CEO' || e.roles.includes(role)).map((e) => ({
            type: e.type,
            label: e.label,
            inApp: isOn(e.type, 'IN_APP'),
            telegram: isOn(e.type, 'TELEGRAM'),
        }));
    }
    async saveSettings(userId, role, items) {
        const known = new Set(contracts_1.NOTIFICATION_EVENTS.map((e) => e.type));
        await this.prisma.$transaction(items
            .filter((i) => known.has(i.eventType))
            .map((i) => this.prisma.notificationSetting.upsert({
            where: {
                userId_eventType_channel: { userId, eventType: i.eventType, channel: i.channel },
            },
            update: { enabled: i.enabled },
            create: { userId, eventType: i.eventType, channel: i.channel, enabled: i.enabled },
        })));
        return this.settings(userId, role);
    }
    async list(userId, page, pageSize) {
        const where = { userId };
        const [items, total] = await Promise.all([
            this.prisma.notification.findMany({
                where,
                orderBy: { createdAt: 'desc' },
                skip: (page - 1) * pageSize,
                take: pageSize,
            }),
            this.prisma.notification.count({ where }),
        ]);
        return {
            items: items.map((n) => ({
                id: n.id,
                type: n.type,
                title: n.title,
                body: n.body,
                link: n.link,
                readAt: n.readAt?.toISOString() ?? null,
                createdAt: n.createdAt.toISOString(),
            })),
            total,
            page,
            pageSize,
        };
    }
    unread(userId) {
        return this.prisma.notification.count({ where: { userId, readAt: null } });
    }
    async markRead(userId, id) {
        await this.prisma.notification.updateMany({
            where: { userId, readAt: null, ...(id ? { id } : {}) },
            data: { readAt: new Date() },
        });
    }
};
exports.NotificationsService = NotificationsService;
exports.NotificationsService = NotificationsService = __decorate([
    (0, common_1.Injectable)(),
    __metadata("design:paramtypes", [prisma_service_1.PrismaService])
], NotificationsService);
//# sourceMappingURL=notifications.service.js.map
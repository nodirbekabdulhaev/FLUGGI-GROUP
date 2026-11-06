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
exports.ChatService = void 0;
const common_1 = require("@nestjs/common");
const app_exception_1 = require("../../core/http/app.exception");
const prisma_service_1 = require("../../core/prisma/prisma.service");
const notifications_service_1 = require("../notifications/notifications.service");
const userSelect = { id: true, fullName: true, role: { select: { name: true } } };
/**
 * Чат сотрудников: личная переписка. Новое сообщение — уведомление в Telegram,
 * но не чаще одного, пока получатель не прочитал переписку.
 */
let ChatService = class ChatService {
    prisma;
    notifications;
    constructor(prisma, notifications) {
        this.prisma = prisma;
        this.notifications = notifications;
    }
    /** С кем можно переписываться: все активные сотрудники. */
    async contacts(auth) {
        const users = await this.prisma.user.findMany({
            where: { status: 'ACTIVE', deletedAt: null, id: { not: auth.userId } },
            select: { ...userSelect, team: { select: { name: true } } },
            orderBy: { fullName: 'asc' },
        });
        return users.map((u) => ({
            id: u.id,
            name: u.fullName,
            role: u.role.name,
            team: u.team?.name ?? null,
        }));
    }
    async member(auth, conversationId) {
        const m = await this.prisma.conversationMember.findUnique({
            where: { conversationId_userId: { conversationId, userId: auth.userId } },
        });
        if (!m)
            throw (0, app_exception_1.notFound)('Чат');
        return m;
    }
    async list(auth) {
        const rows = await this.prisma.conversation.findMany({
            where: { members: { some: { userId: auth.userId } } },
            include: {
                members: { include: { user: { select: userSelect } } },
                messages: { orderBy: { createdAt: 'desc' }, take: 1 },
            },
            orderBy: { lastMessageAt: 'desc' },
            take: 100,
        });
        const unread = await this.unreadByConversation(auth);
        return rows
            .map((c) => {
            const peer = c.members.find((m) => m.userId !== auth.userId)?.user;
            const last = c.messages[0];
            return {
                id: c.id,
                peer: peer
                    ? { id: peer.id, name: peer.fullName, role: peer.role.name }
                    : { id: auth.userId, name: auth.fullName, role: auth.roleName },
                lastMessage: last
                    ? {
                        body: last.body,
                        createdAt: last.createdAt.toISOString(),
                        mine: last.authorId === auth.userId,
                    }
                    : null,
                unread: unread.get(c.id) ?? 0,
            };
        })
            .filter((c) => c.lastMessage || c.unread);
    }
    async unreadByConversation(auth) {
        const rows = await this.prisma.$queryRaw `
      SELECT m.conversation_id, count(*) AS n
      FROM chat_messages m
      JOIN conversation_members cm ON cm.conversation_id = m.conversation_id AND cm.user_id = ${auth.userId}
      WHERE m.created_at > cm.last_read_at AND m.author_id <> ${auth.userId}
      GROUP BY m.conversation_id`;
        return new Map(rows.map((r) => [r.conversation_id, Number(r.n)]));
    }
    async unreadTotal(auth) {
        let count = 0;
        for (const n of (await this.unreadByConversation(auth)).values())
            count += n;
        return { count };
    }
    /** Открыть (или создать) личную переписку с сотрудником. */
    async direct(auth, userId) {
        if (userId === auth.userId)
            throw (0, app_exception_1.businessRule)('Нельзя написать самому себе');
        const peer = await this.prisma.user.findFirst({
            where: { id: userId, status: 'ACTIVE', deletedAt: null },
        });
        if (!peer)
            throw (0, app_exception_1.notFound)('Сотрудник');
        const directKey = [auth.userId, userId].sort().join(':');
        const existing = await this.prisma.conversation.findUnique({ where: { directKey } });
        if (existing)
            return { id: existing.id };
        try {
            const c = await this.prisma.conversation.create({
                data: { directKey, members: { create: [{ userId: auth.userId }, { userId }] } },
            });
            return { id: c.id };
        }
        catch {
            // Параллельный запрос уже создал переписку
            return {
                id: (await this.prisma.conversation.findUniqueOrThrow({ where: { directKey } })).id,
            };
        }
    }
    async messages(auth, conversationId, q) {
        await this.member(auth, conversationId);
        const rows = await this.prisma.chatMessage.findMany({
            where: { conversationId, ...(q.before ? { createdAt: { lt: new Date(q.before) } } : {}) },
            include: { author: { select: { id: true, fullName: true } } },
            orderBy: { createdAt: 'desc' },
            take: q.limit,
        });
        return rows.reverse().map((m) => ({
            id: m.id,
            conversationId: m.conversationId,
            author: { id: m.author.id, name: m.author.fullName },
            body: m.body,
            createdAt: m.createdAt.toISOString(),
            mine: m.authorId === auth.userId,
        }));
    }
    async send(auth, conversationId, body) {
        await this.member(auth, conversationId);
        const now = new Date();
        const m = await this.prisma.$transaction(async (tx) => {
            const msg = await tx.chatMessage.create({
                data: { conversationId, authorId: auth.userId, body },
            });
            await tx.conversation.update({ where: { id: conversationId }, data: { lastMessageAt: now } });
            await tx.conversationMember.update({
                where: { conversationId_userId: { conversationId, userId: auth.userId } },
                data: { lastReadAt: now },
            });
            return msg;
        });
        // Telegram: один раз на «пачку» непрочитанных
        const others = await this.prisma.conversationMember.findMany({
            where: { conversationId, userId: { not: auth.userId } },
        });
        for (const o of others) {
            if (o.notifiedAt && o.notifiedAt > o.lastReadAt)
                continue;
            await this.prisma.conversationMember.update({
                where: { conversationId_userId: { conversationId, userId: o.userId } },
                data: { notifiedAt: now },
            });
            await this.notifications.notify([o.userId], {
                type: 'chat.message',
                title: `💬 ${auth.fullName}`,
                body: body.length > 300 ? `${body.slice(0, 300)}…` : body,
                link: `/dashboard?chat=${conversationId}`,
            }, null, { inApp: false });
        }
        return {
            id: m.id,
            conversationId,
            author: { id: auth.userId, name: auth.fullName },
            body: m.body,
            createdAt: m.createdAt.toISOString(),
            mine: true,
        };
    }
    async read(auth, conversationId) {
        await this.member(auth, conversationId);
        await this.prisma.conversationMember.update({
            where: { conversationId_userId: { conversationId, userId: auth.userId } },
            data: { lastReadAt: new Date() },
        });
    }
};
exports.ChatService = ChatService;
exports.ChatService = ChatService = __decorate([
    (0, common_1.Injectable)(),
    __metadata("design:paramtypes", [prisma_service_1.PrismaService,
        notifications_service_1.NotificationsService])
], ChatService);
//# sourceMappingURL=chat.service.js.map
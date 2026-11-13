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
exports.InboxService = void 0;
const common_1 = require("@nestjs/common");
const contracts_1 = require("@fluggi/contracts");
const app_exception_1 = require("../../core/http/app.exception");
const prisma_service_1 = require("../../core/prisma/prisma.service");
const intake_service_1 = require("./intake.service");
const meta_service_1 = require("./meta.service");
const include = {
    lead: { select: { id: true, number: true, title: true } },
    owner: { select: { id: true, fullName: true } },
    messages: { orderBy: { createdAt: 'desc' }, take: 1 },
};
const toDto = (t) => {
    const last = t.messages[0];
    return {
        id: t.id,
        channel: t.channel,
        peerId: t.peerId,
        peerName: t.peerName,
        peerUsername: t.peerUsername,
        lead: t.lead
            ? { id: t.lead.id, number: (0, contracts_1.formatNumber)('L', t.lead.number), name: t.lead.title }
            : null,
        owner: t.owner ? { id: t.owner.id, name: t.owner.fullName } : null,
        unread: t.unread,
        lastMessage: last
            ? {
                text: last.text,
                direction: last.direction,
                createdAt: last.createdAt.toISOString(),
            }
            : null,
        lastMessageAt: t.lastMessageAt.toISOString(),
    };
};
/**
 * «Входящие»: Директ и комментарии Instagram/Facebook. Видимость — как у лидов:
 * CEO — все, РОП — отдела, менеджер — свои переписки.
 */
let InboxService = class InboxService {
    prisma;
    meta;
    intake;
    constructor(prisma, meta, intake) {
        this.prisma = prisma;
        this.meta = meta;
        this.intake = intake;
    }
    where(auth) {
        const scope = auth.permissions['lead.read'];
        if (scope === 'ALL')
            return {};
        if (scope === 'TEAM') {
            const teams = [...new Set([...auth.headedTeamIds, ...(auth.teamId ? [auth.teamId] : [])])];
            return { OR: [{ ownerId: auth.userId }, { teamId: { in: teams } }] };
        }
        return { ownerId: auth.userId };
    }
    async thread(auth, id) {
        const t = await this.prisma.socialThread.findFirst({
            where: { AND: [this.where(auth), { id }] },
            include,
        });
        if (!t)
            throw (0, app_exception_1.notFound)('Переписка');
        return t;
    }
    async list(auth, q) {
        const and = [this.where(auth)];
        if (q.channel)
            and.push({ channel: q.channel });
        if (q.unread === 'true')
            and.push({ unread: { gt: 0 } });
        const where = { AND: and };
        const [rows, total, unread] = await Promise.all([
            this.prisma.socialThread.findMany({
                where,
                include,
                orderBy: { lastMessageAt: 'desc' },
                skip: (q.page - 1) * q.pageSize,
                take: q.pageSize,
            }),
            this.prisma.socialThread.count({ where }),
            this.prisma.socialThread.count({ where: { AND: [this.where(auth), { unread: { gt: 0 } }] } }),
        ]);
        return { items: rows.map(toDto), total, page: q.page, pageSize: q.pageSize, unread };
    }
    async messages(auth, id) {
        await this.thread(auth, id);
        const rows = await this.prisma.socialMessage.findMany({
            where: { threadId: id },
            include: { author: { select: { id: true, fullName: true } } },
            orderBy: { createdAt: 'asc' },
            take: 500,
        });
        await this.prisma.socialThread.update({ where: { id }, data: { unread: 0 } });
        return rows.map((m) => ({
            id: m.id,
            direction: m.direction,
            text: m.text,
            externalId: m.externalId,
            mediaId: m.mediaId,
            author: m.author ? { id: m.author.id, name: m.author.fullName } : null,
            createdAt: m.createdAt.toISOString(),
        }));
    }
    /** Ответ из CRM: в Директ, публично под комментарием или в Директ на комментарий. */
    async reply(auth, id, input) {
        const t = await this.thread(auth, id);
        let externalId = null;
        if (t.channel === 'INSTAGRAM_DM') {
            const r = await this.meta.sendDirect(t.peerId, input.text);
            externalId = r.message_id ?? null;
        }
        else {
            if (!input.commentId)
                throw (0, app_exception_1.businessRule)('Выберите комментарий, на который отвечаете');
            const own = await this.prisma.socialMessage.findFirst({
                where: { threadId: id, externalId: input.commentId },
            });
            if (!own)
                throw (0, app_exception_1.businessRule)('Комментарий не из этой переписки');
            if (input.mode === 'private') {
                const r = await this.meta.privateReply(input.commentId, input.text);
                externalId = r.message_id ?? null;
            }
            else {
                const r = await this.meta.replyComment(input.commentId, input.text, t.channel === 'FACEBOOK_COMMENT');
                externalId = r.id ?? null;
            }
        }
        const now = new Date();
        await this.prisma.socialMessage.create({
            data: {
                threadId: id,
                direction: 'OUT',
                text: input.mode === 'private' && t.channel !== 'INSTAGRAM_DM'
                    ? `[в Директ] ${input.text}`
                    : input.text,
                externalId,
                authorId: auth.userId,
                createdAt: now,
            },
        });
        await this.prisma.socialThread.update({
            where: { id },
            data: { lastMessageAt: now, unread: 0 },
        });
        return this.messages(auth, id);
    }
    /** «Создать лид» из переписки (или привязать к найденному по Instagram). */
    async createLead(auth, id) {
        const t = await this.thread(auth, id);
        if (!t.leadId) {
            const first = await this.prisma.socialMessage.findFirst({
                where: { threadId: id, direction: 'IN' },
                orderBy: { createdAt: 'asc' },
            });
            const settings = await this.intake.settings();
            const who = t.peerUsername ? `@${t.peerUsername}` : (t.peerName ?? 'Instagram');
            const channel = t.channel === 'INSTAGRAM_DM' ? 'Директ Instagram' : 'Комментарий';
            const res = await this.intake.intake({
                title: `${who} — ${channel}`,
                contactName: t.peerName ?? t.peerUsername,
                instagram: t.peerUsername,
                comment: first ? `${channel}: ${first.text}` : null,
                sourceCode: t.channel === 'FACEBOOK_COMMENT' ? 'OTHER' : 'INSTAGRAM',
                serviceId: settings.serviceId,
                ownerId: t.ownerId ?? auth.userId,
                channel,
            });
            await this.prisma.socialThread.update({ where: { id }, data: { leadId: res.leadId } });
        }
        return toDto(await this.thread(auth, id));
    }
};
exports.InboxService = InboxService;
exports.InboxService = InboxService = __decorate([
    (0, common_1.Injectable)(),
    __metadata("design:paramtypes", [prisma_service_1.PrismaService,
        meta_service_1.MetaService,
        intake_service_1.IntakeService])
], InboxService);
//# sourceMappingURL=inbox.service.js.map
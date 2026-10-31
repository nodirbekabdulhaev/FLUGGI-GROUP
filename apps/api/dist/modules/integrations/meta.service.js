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
var MetaService_1;
Object.defineProperty(exports, "__esModule", { value: true });
exports.MetaService = void 0;
const node_crypto_1 = require("node:crypto");
const common_1 = require("@nestjs/common");
const env_1 = require("../../config/env");
const app_exception_1 = require("../../core/http/app.exception");
const prisma_service_1 = require("../../core/prisma/prisma.service");
const notifications_service_1 = require("../notifications/notifications.service");
const intake_service_1 = require("./intake.service");
const HEALTH_KEY = 'meta.health';
/**
 * Instagram / Facebook через Meta Graph API:
 *  • Директ → переписка во «Входящих», лид (настройка «из Директа»);
 *  • комментарии → переписка, лид по ключевым словам («цена», «сколько»…);
 *  • лид-формы таргета (leadgen) → лид с источником «Таргет», кампанией и объявлением.
 * Подпись каждого webhook проверяется секретом приложения (X-Hub-Signature-256).
 */
let MetaService = MetaService_1 = class MetaService {
    prisma;
    intake;
    notifications;
    logger = new common_1.Logger(MetaService_1.name);
    constructor(prisma, intake, notifications) {
        this.prisma = prisma;
        this.intake = intake;
        this.notifications = notifications;
    }
    /** Ключи Meta читаются при каждом обращении (не кэшируются) — меняются вместе с .env. */
    env() {
        const base = (0, env_1.loadEnv)();
        const v = (k) => process.env[k]?.trim() || undefined;
        return {
            APP_URL: base.APP_URL,
            META_APP_SECRET: v('META_APP_SECRET'),
            META_VERIFY_TOKEN: v('META_VERIFY_TOKEN'),
            META_PAGE_ACCESS_TOKEN: v('META_PAGE_ACCESS_TOKEN'),
            META_GRAPH_BASE: v('META_GRAPH_BASE') ?? base.META_GRAPH_BASE,
        };
    }
    // ─────────────── Graph API ───────────────
    async graph(method, path, params = {}, body) {
        const token = this.env().META_PAGE_ACCESS_TOKEN;
        if (!token)
            throw (0, app_exception_1.businessRule)('Instagram не подключён: нет META_PAGE_ACCESS_TOKEN в .env');
        const url = new URL(`${this.env().META_GRAPH_BASE.replace(/\/$/, '')}/${path}`);
        for (const [k, v] of Object.entries({ ...params, access_token: token }))
            url.searchParams.set(k, v);
        let res;
        try {
            res = await fetch(url, {
                method,
                headers: body ? { 'Content-Type': 'application/json' } : undefined,
                body: body ? JSON.stringify(body) : undefined,
                signal: AbortSignal.timeout(15_000),
            });
        }
        catch (err) {
            throw (0, app_exception_1.businessRule)(`Нет связи с Meta: ${err.message}`);
        }
        const json = (await res.json().catch(() => ({})));
        if (!res.ok) {
            const message = json.error?.message ?? `HTTP ${res.status}`;
            await this.health(message);
            throw (0, app_exception_1.businessRule)(`Meta: ${message}`);
        }
        return json;
    }
    async health(error) {
        const value = { at: new Date().toISOString(), error };
        await this.prisma.setting.upsert({
            where: { key: HEALTH_KEY },
            update: { value },
            create: { key: HEALTH_KEY, value },
        });
    }
    // ─────────────── Webhook ───────────────
    /** GET-проверка при подключении webhook в кабинете Meta. */
    verify(mode, token, challenge) {
        const expected = this.env().META_VERIFY_TOKEN;
        if (mode === 'subscribe' && expected && token === expected && challenge)
            return challenge;
        throw new app_exception_1.AppException('FORBIDDEN', 'Неверный токен проверки webhook');
    }
    /** Подпись X-Hub-Signature-256 = sha256 HMAC тела запроса секретом приложения. */
    checkSignature(raw, header) {
        const secret = this.env().META_APP_SECRET;
        if (!secret)
            throw new app_exception_1.AppException('FORBIDDEN', 'Instagram не подключён: нет META_APP_SECRET');
        if (!raw || !header?.startsWith('sha256='))
            throw new app_exception_1.AppException('FORBIDDEN', 'Нет подписи webhook');
        const expected = `sha256=${(0, node_crypto_1.createHmac)('sha256', secret).update(raw).digest('hex')}`;
        const a = Buffer.from(expected);
        const b = Buffer.from(header);
        if (a.length !== b.length || !(0, node_crypto_1.timingSafeEqual)(a, b))
            throw new app_exception_1.AppException('FORBIDDEN', 'Неверная подпись webhook');
    }
    async handle(body) {
        for (const entry of body.entry ?? []) {
            const ownId = entry.id ?? null;
            for (const m of entry.messaging ?? []) {
                await this.safe(() => this.onMessage(m, ownId, body.object));
            }
            for (const c of entry.changes ?? []) {
                if (c.field === 'comments' || c.field === 'feed')
                    await this.safe(() => this.onComment(c.value ?? {}, body.object));
                if (c.field === 'leadgen')
                    await this.safe(() => this.onLeadgen(c.value ?? {}));
            }
        }
        await this.health(null);
    }
    /** Ошибка одного события не должна терять остальные; Meta повторит весь запрос при 5xx. */
    async safe(fn) {
        try {
            await fn();
        }
        catch (err) {
            this.logger.warn(`Meta event failed: ${err.message}`);
            await this.health(err.message);
        }
    }
    async thread(channel, peerId, peer) {
        const settings = await this.intake.settings();
        const existing = await this.prisma.socialThread.findUnique({
            where: { channel_peerId: { channel, peerId } },
        });
        if (existing) {
            if ((peer.username && !existing.peerUsername) || (peer.name && !existing.peerName))
                return this.prisma.socialThread.update({
                    where: { id: existing.id },
                    data: {
                        peerUsername: existing.peerUsername ?? peer.username ?? null,
                        peerName: existing.peerName ?? peer.name ?? null,
                    },
                });
            return existing;
        }
        const ownerId = await this.intake.pickOwner(settings.ownerId, settings.teamId);
        const owner = await this.prisma.user.findUniqueOrThrow({ where: { id: ownerId } });
        return this.prisma.socialThread.create({
            data: {
                channel,
                peerId,
                peerName: peer.name ?? null,
                peerUsername: peer.username ?? null,
                ownerId,
                teamId: owner.teamId,
            },
        });
    }
    async addMessage(threadId, m, at) {
        if (m.externalId) {
            const dup = await this.prisma.socialMessage.findUnique({
                where: { externalId: m.externalId },
            });
            if (dup)
                return null;
        }
        const msg = await this.prisma.socialMessage.create({
            data: {
                threadId,
                direction: m.direction,
                text: m.text.slice(0, 4000),
                externalId: m.externalId ?? null,
                mediaId: m.mediaId ?? null,
                createdAt: at,
            },
        });
        await this.prisma.socialThread.update({
            where: { id: threadId },
            data: {
                lastMessageAt: at,
                ...(m.direction === 'IN' ? { unread: { increment: 1 } } : {}),
            },
        });
        return msg;
    }
    /** Лид из переписки: дубль ищется по Instagram; ссылка лида сохраняется в переписке. */
    async leadFor(t, text, channel) {
        if (t.leadId)
            return;
        const settings = await this.intake.settings();
        const who = t.peerUsername ? `@${t.peerUsername}` : (t.peerName ?? 'Instagram');
        const res = await this.intake.intake({
            title: `${who} — ${channel}`,
            contactName: t.peerName ?? t.peerUsername,
            instagram: t.peerUsername,
            comment: `${channel}: ${text}`,
            sourceCode: 'INSTAGRAM',
            serviceId: settings.serviceId,
            ownerId: t.ownerId,
            channel,
        });
        await this.prisma.socialThread.update({ where: { id: t.id }, data: { leadId: res.leadId } });
    }
    async onMessage(m, ownId, object) {
        const text = m.message?.text ?? (m.message?.attachments?.length ? '[вложение]' : null);
        if (!m.message || !text || !m.sender?.id)
            return;
        const echo = Boolean(m.message.is_echo) || (ownId !== null && m.sender.id === ownId);
        const peerId = echo ? m.recipient?.id : m.sender.id;
        if (!peerId)
            return;
        const channel = 'INSTAGRAM_DM';
        const profile = echo ? {} : await this.profile(peerId, object);
        const t = await this.thread(channel, peerId, profile);
        const at = m.timestamp ? new Date(m.timestamp) : new Date();
        const msg = await this.addMessage(t.id, { direction: echo ? 'OUT' : 'IN', text, externalId: m.message.mid ?? null }, at);
        if (!msg || echo)
            return;
        const settings = await this.intake.settings();
        if (settings.autoLeadFromDirect)
            await this.leadFor(t, text, 'Директ Instagram');
        await this.notifications.notify([t.ownerId], {
            type: 'social.message',
            title: `💬 Instagram: ${t.peerUsername ? `@${t.peerUsername}` : (t.peerName ?? 'сообщение')}`,
            body: text.slice(0, 300),
            link: `/inbox?thread=${t.id}`,
        }, null);
    }
    /** Имя и username собеседника (Instagram User Profile API); без токена — пусто. */
    async profile(id, object) {
        if (object !== 'instagram' || !this.env().META_PAGE_ACCESS_TOKEN)
            return {};
        try {
            const p = await this.graph('GET', id, {
                fields: 'name,username',
            });
            return { name: p.name ?? null, username: p.username ?? null };
        }
        catch {
            return {};
        }
    }
    async onComment(v, object) {
        const from = (v.from ?? {});
        const commentId = (v.id ?? v.comment_id);
        const text = (v.text ?? v.message);
        if (!from.id || !commentId || !text)
            return;
        if (object === 'page' && v.item !== 'comment')
            return;
        if (v.verb && v.verb !== 'add')
            return;
        const media = (v.media ?? {});
        const channel = object === 'page' ? 'FACEBOOK_COMMENT' : 'INSTAGRAM_COMMENT';
        const t = await this.thread(channel, from.id, { name: from.name, username: from.username });
        const msg = await this.addMessage(t.id, {
            direction: 'IN',
            text,
            externalId: commentId,
            mediaId: media.id ?? v.post_id ?? null,
        }, new Date());
        if (!msg)
            return;
        const settings = await this.intake.settings();
        const lower = text.toLowerCase();
        const hot = settings.autoLeadFromComments === 'all' ||
            (settings.autoLeadFromComments === 'keywords' &&
                settings.commentKeywords.some((k) => lower.includes(k.toLowerCase())));
        if (hot)
            await this.leadFor(t, text, 'Комментарий');
        await this.notifications.notify([t.ownerId], {
            type: 'social.message',
            title: `💬 Комментарий: ${from.username ? `@${from.username}` : (from.name ?? '')}`,
            body: text.slice(0, 300),
            link: `/inbox?thread=${t.id}`,
        }, null);
    }
    /** Лид-форма таргета: данные лида запрашиваются у Meta по leadgen_id. */
    async onLeadgen(v) {
        const id = v.leadgen_id;
        if (!id)
            return;
        const lead = await this.graph('GET', id, { fields: 'field_data,campaign_name,ad_name,form_id,platform,created_time' });
        const f = new Map((lead.field_data ?? []).map((x) => [x.name, x.values?.join(', ') ?? '']));
        const pick = (...keys) => keys.map((k) => f.get(k)).find(Boolean) ?? null;
        const name = pick('full_name') ??
            ([pick('first_name'), pick('last_name')].filter(Boolean).join(' ') || null);
        const known = new Set([
            'full_name',
            'first_name',
            'last_name',
            'phone_number',
            'email',
            'company_name',
        ]);
        const extra = [...f.entries()].filter(([k]) => !known.has(k)).map(([k, val]) => `${k}: ${val}`);
        const settings = await this.intake.settings();
        const comment = [
            `Таргет ${lead.platform === 'ig' ? 'Instagram' : 'Facebook'}`,
            lead.campaign_name ? `Кампания: ${lead.campaign_name}` : null,
            lead.ad_name ? `Объявление: ${lead.ad_name}` : null,
            ...extra,
        ]
            .filter(Boolean)
            .join('\n');
        await this.intake.intake({
            title: `${pick('company_name') ?? name ?? 'Лид таргета'} — ${lead.campaign_name ?? 'таргет'}`,
            contactName: name,
            companyName: pick('company_name'),
            phone: pick('phone_number'),
            email: pick('email'),
            comment,
            sourceCode: 'TARGET',
            serviceId: settings.serviceId,
            ownerId: settings.ownerId,
            teamId: settings.teamId,
            channel: 'Таргет (лид-форма)',
        });
    }
    // ─────────────── Ответы из CRM ───────────────
    async sendDirect(peerId, text) {
        return this.graph('POST', 'me/messages', {}, {
            recipient: { id: peerId },
            message: { text },
            messaging_type: 'RESPONSE',
        });
    }
    /** Публичный ответ на комментарий (Instagram: /replies; Facebook: /comments). */
    async replyComment(commentId, text, facebook) {
        return this.graph('POST', `${commentId}/${facebook ? 'comments' : 'replies'}`, { message: text });
    }
    /** Ответ на комментарий в Директ (private reply). */
    async privateReply(commentId, text) {
        return this.graph('POST', 'me/messages', {}, {
            recipient: { comment_id: commentId },
            message: { text },
        });
    }
    async status() {
        const env = this.env();
        const missing = [
            ['META_APP_SECRET', env.META_APP_SECRET],
            ['META_VERIFY_TOKEN', env.META_VERIFY_TOKEN],
            ['META_PAGE_ACCESS_TOKEN', env.META_PAGE_ACCESS_TOKEN],
        ]
            .filter(([, v]) => !v)
            .map(([k]) => k);
        const [h, threads, adsLeads] = await Promise.all([
            this.prisma.setting.findUnique({ where: { key: HEALTH_KEY } }),
            this.prisma.socialThread.count(),
            this.prisma.lead.count({ where: { source: { code: 'TARGET' } } }),
        ]);
        const health = (h?.value ?? null);
        return {
            configured: missing.length === 0,
            missing,
            webhookUrl: `${new URL(env.APP_URL).origin}/api/v1/public/meta/webhook`,
            lastEventAt: health?.at ?? null,
            lastError: health?.error ?? null,
            threads,
            adsLeads,
        };
    }
};
exports.MetaService = MetaService;
exports.MetaService = MetaService = MetaService_1 = __decorate([
    (0, common_1.Injectable)(),
    __metadata("design:paramtypes", [prisma_service_1.PrismaService,
        intake_service_1.IntakeService,
        notifications_service_1.NotificationsService])
], MetaService);
//# sourceMappingURL=meta.service.js.map
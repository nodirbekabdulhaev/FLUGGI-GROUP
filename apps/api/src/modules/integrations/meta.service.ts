import { createHmac, timingSafeEqual } from 'node:crypto';
import { Injectable, Logger } from '@nestjs/common';
import type { MetaStatusDto, SocialChannel } from '@fluggi/contracts';
import type { Prisma } from '@fluggi/db';
import { loadEnv } from '../../config/env';
import { AppException, businessRule } from '../../core/http/app.exception';
import { PrismaService } from '../../core/prisma/prisma.service';
import { NotificationsService } from '../notifications/notifications.service';
import { IntakeService } from './intake.service';

const HEALTH_KEY = 'meta.health';

interface MessagingEvent {
  sender?: { id?: string };
  recipient?: { id?: string };
  timestamp?: number;
  message?: { mid?: string; text?: string; is_echo?: boolean; attachments?: unknown[] };
}

interface ChangeEvent {
  field?: string;
  value?: Record<string, unknown>;
}

interface WebhookBody {
  object?: string;
  entry?: { id?: string; time?: number; messaging?: MessagingEvent[]; changes?: ChangeEvent[] }[];
}

/**
 * Instagram / Facebook через Meta Graph API:
 *  • Директ → переписка во «Входящих», лид (настройка «из Директа»);
 *  • комментарии → переписка, лид по ключевым словам («цена», «сколько»…);
 *  • лид-формы таргета (leadgen) → лид с источником «Таргет», кампанией и объявлением.
 * Подпись каждого webhook проверяется секретом приложения (X-Hub-Signature-256).
 */
@Injectable()
export class MetaService {
  private readonly logger = new Logger(MetaService.name);

  constructor(
    private readonly prisma: PrismaService,
    private readonly intake: IntakeService,
    private readonly notifications: NotificationsService,
  ) {}

  /** Ключи Meta читаются при каждом обращении (не кэшируются) — меняются вместе с .env. */
  private env() {
    const base = loadEnv();
    const v = (k: string) => process.env[k]?.trim() || undefined;
    return {
      APP_URL: base.APP_URL,
      META_APP_SECRET: v('META_APP_SECRET'),
      META_VERIFY_TOKEN: v('META_VERIFY_TOKEN'),
      META_PAGE_ACCESS_TOKEN: v('META_PAGE_ACCESS_TOKEN'),
      META_GRAPH_BASE: v('META_GRAPH_BASE') ?? base.META_GRAPH_BASE,
    };
  }

  // ─────────────── Graph API ───────────────

  private async graph<T>(
    method: 'GET' | 'POST',
    path: string,
    params: Record<string, string> = {},
    body?: unknown,
  ): Promise<T> {
    const token = this.env().META_PAGE_ACCESS_TOKEN;
    if (!token) throw businessRule('Instagram не подключён: нет META_PAGE_ACCESS_TOKEN в .env');
    const url = new URL(`${this.env().META_GRAPH_BASE.replace(/\/$/, '')}/${path}`);
    for (const [k, v] of Object.entries({ ...params, access_token: token }))
      url.searchParams.set(k, v);
    let res: Response;
    try {
      res = await fetch(url, {
        method,
        headers: body ? { 'Content-Type': 'application/json' } : undefined,
        body: body ? JSON.stringify(body) : undefined,
        signal: AbortSignal.timeout(15_000),
      });
    } catch (err) {
      throw businessRule(`Нет связи с Meta: ${(err as Error).message}`);
    }
    const json = (await res.json().catch(() => ({}))) as { error?: { message?: string } } & T;
    if (!res.ok) {
      const message = json.error?.message ?? `HTTP ${res.status}`;
      await this.health(message);
      throw businessRule(`Meta: ${message}`);
    }
    return json;
  }

  private async health(error: string | null) {
    const value = { at: new Date().toISOString(), error } as Prisma.InputJsonValue;
    await this.prisma.setting.upsert({
      where: { key: HEALTH_KEY },
      update: { value },
      create: { key: HEALTH_KEY, value },
    });
  }

  // ─────────────── Webhook ───────────────

  /** GET-проверка при подключении webhook в кабинете Meta. */
  verify(mode?: string, token?: string, challenge?: string): string {
    const expected = this.env().META_VERIFY_TOKEN;
    if (mode === 'subscribe' && expected && token === expected && challenge) return challenge;
    throw new AppException('FORBIDDEN', 'Неверный токен проверки webhook');
  }

  /** Подпись X-Hub-Signature-256 = sha256 HMAC тела запроса секретом приложения. */
  checkSignature(raw: Buffer | undefined, header: string | undefined) {
    const secret = this.env().META_APP_SECRET;
    if (!secret) throw new AppException('FORBIDDEN', 'Instagram не подключён: нет META_APP_SECRET');
    if (!raw || !header?.startsWith('sha256='))
      throw new AppException('FORBIDDEN', 'Нет подписи webhook');
    const expected = `sha256=${createHmac('sha256', secret).update(raw).digest('hex')}`;
    const a = Buffer.from(expected);
    const b = Buffer.from(header);
    if (a.length !== b.length || !timingSafeEqual(a, b))
      throw new AppException('FORBIDDEN', 'Неверная подпись webhook');
  }

  async handle(body: WebhookBody) {
    for (const entry of body.entry ?? []) {
      const ownId = entry.id ?? null;
      for (const m of entry.messaging ?? []) {
        await this.safe(() => this.onMessage(m, ownId, body.object));
      }
      for (const c of entry.changes ?? []) {
        if (c.field === 'comments' || c.field === 'feed')
          await this.safe(() => this.onComment(c.value ?? {}, body.object));
        if (c.field === 'leadgen') await this.safe(() => this.onLeadgen(c.value ?? {}));
      }
    }
    await this.health(null);
  }

  /** Ошибка одного события не должна терять остальные; Meta повторит весь запрос при 5xx. */
  private async safe(fn: () => Promise<void>) {
    try {
      await fn();
    } catch (err) {
      this.logger.warn(`Meta event failed: ${(err as Error).message}`);
      await this.health((err as Error).message);
    }
  }

  private async thread(
    channel: SocialChannel,
    peerId: string,
    peer: { name?: string | null; username?: string | null },
  ) {
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

  private async addMessage(
    threadId: string,
    m: {
      direction: 'IN' | 'OUT';
      text: string;
      externalId?: string | null;
      mediaId?: string | null;
    },
    at: Date,
  ) {
    if (m.externalId) {
      const dup = await this.prisma.socialMessage.findUnique({
        where: { externalId: m.externalId },
      });
      if (dup) return null;
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
  private async leadFor(
    t: {
      id: string;
      leadId: string | null;
      peerUsername: string | null;
      peerName: string | null;
      ownerId: string | null;
    },
    text: string,
    channel: string,
  ) {
    if (t.leadId) return;
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

  private async onMessage(m: MessagingEvent, ownId: string | null, object?: string) {
    const text = m.message?.text ?? (m.message?.attachments?.length ? '[вложение]' : null);
    if (!m.message || !text || !m.sender?.id) return;
    const echo = Boolean(m.message.is_echo) || (ownId !== null && m.sender.id === ownId);
    const peerId = echo ? m.recipient?.id : m.sender.id;
    if (!peerId) return;
    const channel: SocialChannel = 'INSTAGRAM_DM';
    const profile = echo ? {} : await this.profile(peerId, object);
    const t = await this.thread(channel, peerId, profile);
    const at = m.timestamp ? new Date(m.timestamp) : new Date();
    const msg = await this.addMessage(
      t.id,
      { direction: echo ? 'OUT' : 'IN', text, externalId: m.message.mid ?? null },
      at,
    );
    if (!msg || echo) return;
    const settings = await this.intake.settings();
    if (settings.autoLeadFromDirect) await this.leadFor(t, text, 'Директ Instagram');
    await this.notifications.notify(
      [t.ownerId],
      {
        type: 'social.message',
        title: `💬 Instagram: ${t.peerUsername ? `@${t.peerUsername}` : (t.peerName ?? 'сообщение')}`,
        body: text.slice(0, 300),
        link: `/inbox?thread=${t.id}`,
      },
      null,
    );
  }

  /** Имя и username собеседника (Instagram User Profile API); без токена — пусто. */
  private async profile(id: string, object?: string) {
    if (object !== 'instagram' || !this.env().META_PAGE_ACCESS_TOKEN) return {};
    try {
      const p = await this.graph<{ name?: string; username?: string }>('GET', id, {
        fields: 'name,username',
      });
      return { name: p.name ?? null, username: p.username ?? null };
    } catch {
      return {};
    }
  }

  private async onComment(v: Record<string, unknown>, object?: string) {
    const from = (v.from ?? {}) as { id?: string; username?: string; name?: string };
    const commentId = (v.id ?? v.comment_id) as string | undefined;
    const text = (v.text ?? v.message) as string | undefined;
    if (!from.id || !commentId || !text) return;
    if (object === 'page' && v.item !== 'comment') return;
    if (v.verb && v.verb !== 'add') return;
    const media = (v.media ?? {}) as { id?: string };
    const channel: SocialChannel = object === 'page' ? 'FACEBOOK_COMMENT' : 'INSTAGRAM_COMMENT';
    const t = await this.thread(channel, from.id, { name: from.name, username: from.username });
    const msg = await this.addMessage(
      t.id,
      {
        direction: 'IN',
        text,
        externalId: commentId,
        mediaId: media.id ?? (v.post_id as string | undefined) ?? null,
      },
      new Date(),
    );
    if (!msg) return;
    const settings = await this.intake.settings();
    const lower = text.toLowerCase();
    const hot =
      settings.autoLeadFromComments === 'all' ||
      (settings.autoLeadFromComments === 'keywords' &&
        settings.commentKeywords.some((k) => lower.includes(k.toLowerCase())));
    if (hot) await this.leadFor(t, text, 'Комментарий');
    await this.notifications.notify(
      [t.ownerId],
      {
        type: 'social.message',
        title: `💬 Комментарий: ${from.username ? `@${from.username}` : (from.name ?? '')}`,
        body: text.slice(0, 300),
        link: `/inbox?thread=${t.id}`,
      },
      null,
    );
  }

  /** Лид-форма таргета: данные лида запрашиваются у Meta по leadgen_id. */
  private async onLeadgen(v: Record<string, unknown>) {
    const id = v.leadgen_id as string | undefined;
    if (!id) return;
    const lead = await this.graph<{
      field_data?: { name: string; values: string[] }[];
      campaign_name?: string;
      ad_name?: string;
      form_id?: string;
      platform?: string;
    }>('GET', id, { fields: 'field_data,campaign_name,ad_name,form_id,platform,created_time' });
    const f = new Map((lead.field_data ?? []).map((x) => [x.name, x.values?.join(', ') ?? '']));
    const pick = (...keys: string[]) => keys.map((k) => f.get(k)).find(Boolean) ?? null;
    const name =
      pick('full_name') ??
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

  async sendDirect(peerId: string, text: string) {
    return this.graph<{ message_id?: string }>(
      'POST',
      'me/messages',
      {},
      {
        recipient: { id: peerId },
        message: { text },
        messaging_type: 'RESPONSE',
      },
    );
  }

  /** Публичный ответ на комментарий (Instagram: /replies; Facebook: /comments). */
  async replyComment(commentId: string, text: string, facebook: boolean) {
    return this.graph<{ id?: string }>(
      'POST',
      `${commentId}/${facebook ? 'comments' : 'replies'}`,
      { message: text },
    );
  }

  /** Ответ на комментарий в Директ (private reply). */
  async privateReply(commentId: string, text: string) {
    return this.graph<{ message_id?: string }>(
      'POST',
      'me/messages',
      {},
      {
        recipient: { comment_id: commentId },
        message: { text },
      },
    );
  }

  async status(): Promise<MetaStatusDto> {
    const env = this.env();
    const missing = (
      [
        ['META_APP_SECRET', env.META_APP_SECRET],
        ['META_VERIFY_TOKEN', env.META_VERIFY_TOKEN],
        ['META_PAGE_ACCESS_TOKEN', env.META_PAGE_ACCESS_TOKEN],
      ] as const
    )
      .filter(([, v]) => !v)
      .map(([k]) => k);
    const [h, threads, adsLeads] = await Promise.all([
      this.prisma.setting.findUnique({ where: { key: HEALTH_KEY } }),
      this.prisma.socialThread.count(),
      this.prisma.lead.count({ where: { source: { code: 'TARGET' } } }),
    ]);
    const health = (h?.value ?? null) as { at?: string; error?: string | null } | null;
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
}

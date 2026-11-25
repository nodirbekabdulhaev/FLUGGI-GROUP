import { Injectable } from '@nestjs/common';
import {
  formatNumber,
  type inboxListQuerySchema,
  type Paginated,
  type SocialMessageDto,
  type SocialThreadDto,
  type socialReplySchema,
} from '@fluggi/contracts';
import type { Prisma } from '@fluggi/db';
import type { z } from 'zod';
import type { AuthContext } from '../../core/auth/auth-context';
import { businessRule, notFound } from '../../core/http/app.exception';
import { PrismaService } from '../../core/prisma/prisma.service';
import { IntakeService } from './intake.service';
import { MetaService } from './meta.service';

const include = {
  lead: { select: { id: true, number: true, title: true } },
  owner: { select: { id: true, fullName: true } },
  messages: { orderBy: { createdAt: 'desc' }, take: 1 },
} satisfies Prisma.SocialThreadInclude;

type Row = Prisma.SocialThreadGetPayload<{ include: typeof include }>;

const toDto = (t: Row): SocialThreadDto => {
  const last = t.messages[0];
  return {
    id: t.id,
    channel: t.channel,
    peerId: t.peerId,
    peerName: t.peerName,
    peerUsername: t.peerUsername,
    lead: t.lead
      ? { id: t.lead.id, number: formatNumber('L', t.lead.number), name: t.lead.title }
      : null,
    owner: t.owner ? { id: t.owner.id, name: t.owner.fullName } : null,
    unread: t.unread,
    lastMessage: last
      ? {
          text: last.text,
          direction: last.direction as 'IN' | 'OUT',
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
@Injectable()
export class InboxService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly meta: MetaService,
    private readonly intake: IntakeService,
  ) {}

  private where(auth: AuthContext): Prisma.SocialThreadWhereInput {
    const scope = auth.permissions['lead.read'];
    if (scope === 'ALL') return {};
    if (scope === 'TEAM') {
      const teams = [...new Set([...auth.headedTeamIds, ...(auth.teamId ? [auth.teamId] : [])])];
      return { OR: [{ ownerId: auth.userId }, { teamId: { in: teams } }] };
    }
    return { ownerId: auth.userId };
  }

  private async thread(auth: AuthContext, id: string) {
    const t = await this.prisma.socialThread.findFirst({
      where: { AND: [this.where(auth), { id }] },
      include,
    });
    if (!t) throw notFound('Переписка');
    return t;
  }

  async list(
    auth: AuthContext,
    q: z.output<typeof inboxListQuerySchema>,
  ): Promise<Paginated<SocialThreadDto> & { unread: number }> {
    const and: Prisma.SocialThreadWhereInput[] = [this.where(auth)];
    if (q.channel) and.push({ channel: q.channel });
    if (q.unread === 'true') and.push({ unread: { gt: 0 } });
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

  async messages(auth: AuthContext, id: string): Promise<SocialMessageDto[]> {
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
      direction: m.direction as 'IN' | 'OUT',
      text: m.text,
      externalId: m.externalId,
      mediaId: m.mediaId,
      author: m.author ? { id: m.author.id, name: m.author.fullName } : null,
      createdAt: m.createdAt.toISOString(),
    }));
  }

  /** Ответ из CRM: в Директ, публично под комментарием или в Директ на комментарий. */
  async reply(auth: AuthContext, id: string, input: z.output<typeof socialReplySchema>) {
    const t = await this.thread(auth, id);
    let externalId: string | null = null;
    if (t.channel === 'INSTAGRAM_DM') {
      const r = await this.meta.sendDirect(t.peerId, input.text);
      externalId = r.message_id ?? null;
    } else {
      if (!input.commentId) throw businessRule('Выберите комментарий, на который отвечаете');
      const own = await this.prisma.socialMessage.findFirst({
        where: { threadId: id, externalId: input.commentId },
      });
      if (!own) throw businessRule('Комментарий не из этой переписки');
      if (input.mode === 'private') {
        const r = await this.meta.privateReply(input.commentId, input.text);
        externalId = r.message_id ?? null;
      } else {
        const r = await this.meta.replyComment(
          input.commentId,
          input.text,
          t.channel === 'FACEBOOK_COMMENT',
        );
        externalId = r.id ?? null;
      }
    }
    const now = new Date();
    await this.prisma.socialMessage.create({
      data: {
        threadId: id,
        direction: 'OUT',
        text:
          input.mode === 'private' && t.channel !== 'INSTAGRAM_DM'
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
  async createLead(auth: AuthContext, id: string): Promise<SocialThreadDto> {
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
}

import { Injectable } from '@nestjs/common';
import {
  NOTIFICATION_EVENTS,
  NOTIFICATION_TYPE_GROUP,
  type NotificationDto,
  type NotificationSettingDto,
  type Paginated,
  type RoleCode,
} from '@fluggi/contracts';
import { loadEnv } from '../../config/env';
import { PrismaService } from '../../core/prisma/prisma.service';

export interface NotificationInput {
  type: string;
  title: string;
  body?: string;
  link?: string;
}

@Injectable()
export class NotificationsService {
  constructor(private readonly prisma: PrismaService) {}

  /**
   * Уведомление пользователям (ТЗ §14, §53): in-app и Telegram с учётом личных настроек.
   * Дубли получателей и «сам себе» отсекаются. В Telegram — только если бот настроен
   * и сотрудник подключил чат; отправку делает фоновая очередь с повторами.
   */
  async notify(
    userIds: (string | null | undefined)[],
    n: NotificationInput,
    exceptUserId?: string | null,
    opts: { inApp?: boolean } = {},
  ) {
    const ids = [
      ...new Set(userIds.filter((id): id is string => Boolean(id) && id !== exceptUserId)),
    ];
    if (ids.length === 0) return;
    const group = NOTIFICATION_TYPE_GROUP[n.type] ?? n.type;
    const [off, users] = await Promise.all([
      this.prisma.notificationSetting.findMany({
        where: { userId: { in: ids }, eventType: group, enabled: false },
      }),
      this.prisma.user.findMany({
        where: { id: { in: ids }, status: 'ACTIVE', deletedAt: null },
        select: { id: true, telegramChatId: true },
      }),
    ]);
    const disabled = (userId: string, channel: 'IN_APP' | 'TELEGRAM') =>
      off.some((o) => o.userId === userId && o.channel === channel);
    const active = users.map((u) => u.id);
    const inApp = opts.inApp === false ? [] : active.filter((id) => !disabled(id, 'IN_APP'));
    const telegram = loadEnv().TELEGRAM_BOT_TOKEN
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
          channel: 'TELEGRAM' as const,
          type: n.type,
          title: n.title,
          body: n.body,
          link: n.link,
        })),
      });
  }

  /** Личные настройки уведомлений: типы для роли пользователя и состояние каналов. */
  async settings(userId: string, role: RoleCode): Promise<NotificationSettingDto[]> {
    const rows = await this.prisma.notificationSetting.findMany({ where: { userId } });
    const isOn = (type: string, channel: 'IN_APP' | 'TELEGRAM') =>
      !rows.some((r) => r.eventType === type && r.channel === channel && !r.enabled);
    return NOTIFICATION_EVENTS.filter((e) => role === 'CEO' || e.roles.includes(role)).map((e) => ({
      type: e.type,
      label: e.label,
      inApp: isOn(e.type, 'IN_APP'),
      telegram: isOn(e.type, 'TELEGRAM'),
    }));
  }

  async saveSettings(
    userId: string,
    role: RoleCode,
    items: { eventType: string; channel: 'IN_APP' | 'TELEGRAM'; enabled: boolean }[],
  ): Promise<NotificationSettingDto[]> {
    const known = new Set(NOTIFICATION_EVENTS.map((e) => e.type));
    await this.prisma.$transaction(
      items
        .filter((i) => known.has(i.eventType))
        .map((i) =>
          this.prisma.notificationSetting.upsert({
            where: {
              userId_eventType_channel: { userId, eventType: i.eventType, channel: i.channel },
            },
            update: { enabled: i.enabled },
            create: { userId, eventType: i.eventType, channel: i.channel, enabled: i.enabled },
          }),
        ),
    );
    return this.settings(userId, role);
  }

  async list(userId: string, page: number, pageSize: number): Promise<Paginated<NotificationDto>> {
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

  unread(userId: string) {
    return this.prisma.notification.count({ where: { userId, readAt: null } });
  }

  async markRead(userId: string, id?: string) {
    await this.prisma.notification.updateMany({
      where: { userId, readAt: null, ...(id ? { id } : {}) },
      data: { readAt: new Date() },
    });
  }
}

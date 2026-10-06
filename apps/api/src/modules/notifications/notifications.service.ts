import { Injectable } from '@nestjs/common';
import type { NotificationDto, Paginated } from '@fluggi/contracts';
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

  /** Создаёт уведомления; дубли получателей и «сам себе» отсекаются. */
  async notify(
    userIds: (string | null | undefined)[],
    n: NotificationInput,
    exceptUserId?: string | null,
  ) {
    const ids = [
      ...new Set(userIds.filter((id): id is string => Boolean(id) && id !== exceptUserId)),
    ];
    if (ids.length === 0) return;
    await this.prisma.notification.createMany({
      data: ids.map((userId) => ({
        userId,
        type: n.type,
        title: n.title,
        body: n.body,
        link: n.link,
      })),
    });
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

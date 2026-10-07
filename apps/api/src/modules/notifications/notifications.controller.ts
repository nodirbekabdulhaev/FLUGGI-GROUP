import { Body, Controller, Get, HttpCode, Param, Post, Put, Query } from '@nestjs/common';
import {
  notificationSettingsSchema,
  paginationQuerySchema,
  type NotificationDto,
  type NotificationSettingDto,
  type Paginated,
} from '@fluggi/contracts';
import type { z } from 'zod';
import type { AuthContext } from '../../core/auth/auth-context';
import { AuthenticatedOnly, CurrentUser } from '../../core/auth/decorators';
import { UuidPipe } from '../../core/http/uuid.pipe';
import { zod } from '../../core/http/zod.pipe';
import { NotificationsService } from './notifications.service';

/** Уведомления — личные: каждый видит только свои. */
@Controller('notifications')
export class NotificationsController {
  constructor(private readonly notifications: NotificationsService) {}

  @Get()
  @AuthenticatedOnly()
  list(
    @CurrentUser() auth: AuthContext,
    @Query(zod(paginationQuerySchema)) q: z.output<typeof paginationQuerySchema>,
  ): Promise<Paginated<NotificationDto>> {
    return this.notifications.list(auth.userId, q.page, q.pageSize);
  }

  /** Личные настройки уведомлений по каналам (ТЗ §14: «индивидуальные»). */
  @Get('settings')
  @AuthenticatedOnly()
  settings(@CurrentUser() auth: AuthContext): Promise<NotificationSettingDto[]> {
    return this.notifications.settings(auth.userId, auth.roleCode);
  }

  @Put('settings')
  @AuthenticatedOnly()
  saveSettings(
    @CurrentUser() auth: AuthContext,
    @Body(zod(notificationSettingsSchema)) body: z.output<typeof notificationSettingsSchema>,
  ): Promise<NotificationSettingDto[]> {
    return this.notifications.saveSettings(auth.userId, auth.roleCode, body.settings);
  }

  @Get('unread-count')
  @AuthenticatedOnly()
  async unread(@CurrentUser() auth: AuthContext): Promise<{ count: number }> {
    return { count: await this.notifications.unread(auth.userId) };
  }

  @Post('read-all')
  @HttpCode(204)
  @AuthenticatedOnly()
  readAll(@CurrentUser() auth: AuthContext): Promise<void> {
    return this.notifications.markRead(auth.userId);
  }

  @Post(':id/read')
  @HttpCode(204)
  @AuthenticatedOnly()
  read(@CurrentUser() auth: AuthContext, @Param('id', UuidPipe) id: string): Promise<void> {
    return this.notifications.markRead(auth.userId, id);
  }
}

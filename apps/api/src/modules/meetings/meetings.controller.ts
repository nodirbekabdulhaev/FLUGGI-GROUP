import { Body, Controller, Get, HttpCode, Param, Patch, Post, Query } from '@nestjs/common';
import {
  completeMeetingSchema,
  createMeetingSchema,
  meetingListQuerySchema,
  updateMeetingSchema,
  type MeetingDto,
  type Paginated,
} from '@fluggi/contracts';
import type { z } from 'zod';
import type { AuthContext, RequestMeta } from '../../core/auth/auth-context';
import { CurrentUser, ReqMeta, RequirePermission } from '../../core/auth/decorators';
import { UuidPipe } from '../../core/http/uuid.pipe';
import { zod } from '../../core/http/zod.pipe';
import { MeetingsService } from './meetings.service';

@Controller('meetings')
export class MeetingsController {
  constructor(private readonly meetings: MeetingsService) {}

  @Get()
  @RequirePermission('meeting.read')
  list(
    @CurrentUser() auth: AuthContext,
    @Query(zod(meetingListQuerySchema)) q: z.output<typeof meetingListQuerySchema>,
  ): Promise<Paginated<MeetingDto>> {
    return this.meetings.list(auth, q);
  }

  @Post()
  @RequirePermission('meeting.create')
  create(
    @CurrentUser() auth: AuthContext,
    @Body(zod(createMeetingSchema)) body: z.output<typeof createMeetingSchema>,
    @ReqMeta() meta: RequestMeta,
  ): Promise<MeetingDto> {
    return this.meetings.create(auth, body, meta);
  }

  @Patch(':id')
  @RequirePermission('meeting.update')
  update(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @Body(zod(updateMeetingSchema)) body: z.output<typeof updateMeetingSchema>,
    @ReqMeta() meta: RequestMeta,
  ): Promise<MeetingDto> {
    return this.meetings.update(auth, id, body, meta);
  }

  @Post(':id/complete')
  @HttpCode(200)
  @RequirePermission('meeting.update')
  complete(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @Body(zod(completeMeetingSchema)) body: z.output<typeof completeMeetingSchema>,
    @ReqMeta() meta: RequestMeta,
  ): Promise<MeetingDto> {
    return this.meetings.complete(auth, id, body, meta);
  }
}

import { Body, Controller, Get, HttpCode, Param, Post, Query } from '@nestjs/common';
import {
  chatMessageSchema,
  chatMessagesQuerySchema,
  directChatSchema,
  type ChatContactDto,
  type ChatMessageDto,
  type ConversationDto,
} from '@fluggi/contracts';
import type { z } from 'zod';
import type { AuthContext } from '../../core/auth/auth-context';
import { AuthenticatedOnly, CurrentUser } from '../../core/auth/decorators';
import { UuidPipe } from '../../core/http/uuid.pipe';
import { zod } from '../../core/http/zod.pipe';
import { ChatService } from './chat.service';

/** Чат сотрудников (личная переписка). */
@Controller('chats')
export class ChatController {
  constructor(private readonly chat: ChatService) {}

  @Get()
  @AuthenticatedOnly()
  list(@CurrentUser() auth: AuthContext): Promise<ConversationDto[]> {
    return this.chat.list(auth);
  }

  @Get('unread')
  @AuthenticatedOnly()
  unread(@CurrentUser() auth: AuthContext): Promise<{ count: number }> {
    return this.chat.unreadTotal(auth);
  }

  @Get('contacts')
  @AuthenticatedOnly()
  contacts(@CurrentUser() auth: AuthContext): Promise<ChatContactDto[]> {
    return this.chat.contacts(auth);
  }

  @Post('direct')
  @HttpCode(200)
  @AuthenticatedOnly()
  direct(
    @CurrentUser() auth: AuthContext,
    @Body(zod(directChatSchema)) body: z.output<typeof directChatSchema>,
  ): Promise<{ id: string }> {
    return this.chat.direct(auth, body.userId);
  }

  @Get(':id/messages')
  @AuthenticatedOnly()
  messages(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @Query(zod(chatMessagesQuerySchema)) q: z.output<typeof chatMessagesQuerySchema>,
  ): Promise<ChatMessageDto[]> {
    return this.chat.messages(auth, id, q);
  }

  @Post(':id/messages')
  @AuthenticatedOnly()
  send(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @Body(zod(chatMessageSchema)) body: z.output<typeof chatMessageSchema>,
  ): Promise<ChatMessageDto> {
    return this.chat.send(auth, id, body.body);
  }

  @Post(':id/read')
  @HttpCode(204)
  @AuthenticatedOnly()
  read(@CurrentUser() auth: AuthContext, @Param('id', UuidPipe) id: string): Promise<void> {
    return this.chat.read(auth, id);
  }
}

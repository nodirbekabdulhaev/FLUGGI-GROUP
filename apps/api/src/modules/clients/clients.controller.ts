import {
  Body,
  Controller,
  Delete,
  Get,
  HttpCode,
  Param,
  Patch,
  Post,
  Put,
  Query,
} from '@nestjs/common';
import {
  clientListQuerySchema,
  contactSchema,
  createClientSchema,
  updateClientSchema,
  type ClientDetailDto,
  type ClientDto,
  type ContactDto,
  type Paginated,
} from '@fluggi/contracts';
import type { z } from 'zod';
import type { AuthContext, RequestMeta } from '../../core/auth/auth-context';
import { CurrentUser, ReqMeta, RequirePermission } from '../../core/auth/decorators';
import { UuidPipe } from '../../core/http/uuid.pipe';
import { zod } from '../../core/http/zod.pipe';
import { ClientsService } from './clients.service';

@Controller()
export class ClientsController {
  constructor(private readonly clients: ClientsService) {}

  @Get('clients')
  @RequirePermission('client.read')
  list(
    @CurrentUser() auth: AuthContext,
    @Query(zod(clientListQuerySchema)) q: z.output<typeof clientListQuerySchema>,
  ): Promise<Paginated<ClientDto>> {
    return this.clients.list(auth, q);
  }

  @Get('clients/:id')
  @RequirePermission('client.read')
  get(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
  ): Promise<ClientDetailDto> {
    return this.clients.get(auth, id);
  }

  @Post('clients')
  @RequirePermission('client.create')
  create(
    @CurrentUser() auth: AuthContext,
    @Body(zod(createClientSchema)) body: z.output<typeof createClientSchema>,
    @ReqMeta() meta: RequestMeta,
  ): Promise<ClientDetailDto> {
    return this.clients.create(auth, body, meta);
  }

  @Patch('clients/:id')
  @RequirePermission('client.update')
  update(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @Body(zod(updateClientSchema)) body: z.output<typeof updateClientSchema>,
    @ReqMeta() meta: RequestMeta,
  ): Promise<ClientDetailDto> {
    return this.clients.update(auth, id, body, meta);
  }

  @Post('clients/:id/contacts')
  @RequirePermission('client.update')
  addContact(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @Body(zod(contactSchema)) body: z.output<typeof contactSchema>,
    @ReqMeta() meta: RequestMeta,
  ): Promise<ContactDto> {
    return this.clients.addContact(auth, id, body, meta);
  }

  @Put('contacts/:id')
  @RequirePermission('client.update')
  updateContact(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @Body(zod(contactSchema)) body: z.output<typeof contactSchema>,
    @ReqMeta() meta: RequestMeta,
  ): Promise<ContactDto> {
    return this.clients.updateContact(auth, id, body, meta);
  }

  @Delete('contacts/:id')
  @HttpCode(204)
  @RequirePermission('client.update')
  removeContact(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @ReqMeta() meta: RequestMeta,
  ): Promise<void> {
    return this.clients.removeContact(auth, id, meta);
  }
}

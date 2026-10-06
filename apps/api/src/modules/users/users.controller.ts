import { Body, Controller, Get, HttpCode, Param, Patch, Post, Query } from '@nestjs/common';
import {
  createUserSchema,
  updateUserSchema,
  userListQuerySchema,
  type CreateUserResponse,
  type Paginated,
  type UserDto,
} from '@fluggi/contracts';
import type { z } from 'zod';
import type { AuthContext, RequestMeta } from '../../core/auth/auth-context';
import { CurrentUser, ReqMeta, RequirePermission } from '../../core/auth/decorators';
import { UuidPipe } from '../../core/http/uuid.pipe';
import { zod } from '../../core/http/zod.pipe';
import { UsersService } from './users.service';

@Controller('users')
export class UsersController {
  constructor(private readonly users: UsersService) {}

  @Get()
  @RequirePermission('employee.read')
  list(
    @CurrentUser() auth: AuthContext,
    @Query(zod(userListQuerySchema)) query: z.output<typeof userListQuerySchema>,
  ): Promise<Paginated<UserDto>> {
    return this.users.list(auth, query);
  }

  @Get(':id')
  @RequirePermission('employee.read')
  get(@CurrentUser() auth: AuthContext, @Param('id', UuidPipe) id: string): Promise<UserDto> {
    return this.users.get(auth, id);
  }

  @Post()
  @RequirePermission('employee.manage', 'ALL')
  create(
    @CurrentUser() auth: AuthContext,
    @Body(zod(createUserSchema)) body: z.output<typeof createUserSchema>,
    @ReqMeta() meta: RequestMeta,
  ): Promise<CreateUserResponse> {
    return this.users.create(auth, body, meta);
  }

  @Patch(':id')
  @RequirePermission('employee.manage', 'ALL')
  update(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @Body(zod(updateUserSchema)) body: z.output<typeof updateUserSchema>,
    @ReqMeta() meta: RequestMeta,
  ): Promise<UserDto> {
    return this.users.update(auth, id, body, meta);
  }

  @Post(':id/block')
  @HttpCode(200)
  @RequirePermission('employee.manage', 'ALL')
  block(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @ReqMeta() meta: RequestMeta,
  ): Promise<UserDto> {
    return this.users.setBlocked(auth, id, true, meta);
  }

  @Post(':id/unblock')
  @HttpCode(200)
  @RequirePermission('employee.manage', 'ALL')
  unblock(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @ReqMeta() meta: RequestMeta,
  ): Promise<UserDto> {
    return this.users.setBlocked(auth, id, false, meta);
  }

  @Post(':id/reset-password')
  @HttpCode(200)
  @RequirePermission('employee.manage', 'ALL')
  resetPassword(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @ReqMeta() meta: RequestMeta,
  ): Promise<{ temporaryPassword: string }> {
    return this.users.resetPassword(auth, id, meta);
  }
}

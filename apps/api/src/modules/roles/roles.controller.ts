import { Body, Controller, Get, Param, Put } from '@nestjs/common';
import {
  PERMISSIONS,
  updateRolePermissionsSchema,
  type RoleDto,
  type UpdateRolePermissionsInput,
} from '@fluggi/contracts';
import type { AuthContext, RequestMeta } from '../../core/auth/auth-context';
import { CurrentUser, ReqMeta, RequirePermission } from '../../core/auth/decorators';
import { UuidPipe } from '../../core/http/uuid.pipe';
import { zod } from '../../core/http/zod.pipe';
import { RolesService } from './roles.service';

@Controller('roles')
export class RolesController {
  constructor(private readonly roles: RolesService) {}

  @Get()
  @RequirePermission('role.manage', 'ALL')
  list(): Promise<RoleDto[]> {
    return this.roles.list();
  }

  @Get('permissions')
  @RequirePermission('role.manage', 'ALL')
  catalog(): Record<string, string> {
    return PERMISSIONS;
  }

  @Put(':id/permissions')
  @RequirePermission('role.manage', 'ALL')
  update(
    @CurrentUser() auth: AuthContext,
    @Param('id', UuidPipe) id: string,
    @Body(zod(updateRolePermissionsSchema)) body: UpdateRolePermissionsInput,
    @ReqMeta() meta: RequestMeta,
  ): Promise<RoleDto> {
    return this.roles.updatePermissions(auth, id, body, meta);
  }
}

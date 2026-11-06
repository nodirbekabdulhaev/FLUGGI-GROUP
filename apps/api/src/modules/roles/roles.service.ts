import { Injectable } from '@nestjs/common';
import {
  CEO_LOCKED_PERMISSIONS,
  type PermissionMap,
  type RoleDto,
  type UpdateRolePermissionsInput,
} from '@fluggi/contracts';
import type { AuthContext, RequestMeta } from '../../core/auth/auth-context';
import { AuditService } from '../../core/audit/audit.service';
import { businessRule, notFound } from '../../core/http/app.exception';
import { PrismaService } from '../../core/prisma/prisma.service';

@Injectable()
export class RolesService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly audit: AuditService,
  ) {}

  async list(): Promise<RoleDto[]> {
    const roles = await this.prisma.role.findMany({
      include: {
        permissions: { include: { permission: true } },
        _count: { select: { users: { where: { deletedAt: null } } } },
      },
      orderBy: { createdAt: 'asc' },
    });
    return roles.map((r) => ({
      id: r.id,
      code: r.code,
      name: r.name,
      isSystem: r.isSystem,
      usersCount: r._count.users,
      permissions: Object.fromEntries(
        r.permissions.map((p) => [p.permission.code, p.scope]),
      ) as PermissionMap,
    }));
  }

  async updatePermissions(
    auth: AuthContext,
    roleId: string,
    input: UpdateRolePermissionsInput,
    meta: RequestMeta,
  ): Promise<RoleDto> {
    const role = await this.prisma.role.findUnique({
      where: { id: roleId },
      include: { permissions: { include: { permission: true } } },
    });
    if (!role) throw notFound('Роль');

    if (role.code === 'CEO') {
      const missing = CEO_LOCKED_PERMISSIONS.filter((c) => input.permissions[c] !== 'ALL');
      if (missing.length > 0) {
        throw businessRule(`У роли CEO нельзя ограничить права: ${missing.join(', ')}`);
      }
    }

    const all = await this.prisma.permission.findMany();
    const idByCode = new Map(all.map((p) => [p.code, p.id]));
    const before = Object.fromEntries(role.permissions.map((p) => [p.permission.code, p.scope]));

    const changes: Record<string, { old: unknown; new: unknown }> = {};
    for (const code of new Set([...Object.keys(before), ...Object.keys(input.permissions)])) {
      const oldScope = before[code] ?? null;
      const newScope = input.permissions[code as keyof typeof input.permissions] ?? null;
      if (oldScope !== newScope) changes[code] = { old: oldScope, new: newScope };
    }

    await this.prisma.$transaction(async (tx) => {
      await tx.rolePermission.deleteMany({ where: { roleId } });
      await tx.rolePermission.createMany({
        data: Object.entries(input.permissions).map(([code, scope]) => ({
          roleId,
          permissionId: idByCode.get(code)!,
          scope,
        })),
      });
      if (Object.keys(changes).length > 0) {
        await this.audit.log(tx, {
          actorId: auth.userId,
          action: 'role.permissions_update',
          entityType: 'role',
          entityId: roleId,
          changes,
          meta,
        });
      }
    });

    return (await this.list()).find((r) => r.id === roleId)!;
  }
}

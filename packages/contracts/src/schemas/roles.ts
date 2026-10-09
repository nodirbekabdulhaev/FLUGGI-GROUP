import { z } from 'zod';
import { SCOPES } from '../enums';
import type { RoleCode } from '../enums';
import { PERMISSION_CODES } from '../permissions';
import type { PermissionMap } from '../permissions';

export const updateRolePermissionsSchema = z.object({
  // partialRecord: в Zod 4 z.record с enum-ключом требует все ключи.
  permissions: z.partialRecord(z.enum(PERMISSION_CODES), z.enum(SCOPES)),
});
export type UpdateRolePermissionsInput = z.infer<typeof updateRolePermissionsSchema>;

export interface RoleDto {
  id: string;
  code: RoleCode;
  name: string;
  isSystem: boolean;
  usersCount: number;
  permissions: PermissionMap;
}

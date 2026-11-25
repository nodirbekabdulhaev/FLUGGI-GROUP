import type { PermissionCode, Scope } from '@fluggi/contracts';
import { forbidden } from '../http/app.exception';
import type { AuthContext } from '../auth/auth-context';

/** Область видимости пользователя для права; без права — 403. */
export function scopeOf(auth: AuthContext, code: PermissionCode): Scope {
  const scope = auth.permissions[code];
  if (!scope) throw forbidden();
  return scope;
}

/**
 * Описание того, как запись «принадлежит» пользователю/отделу.
 * Каждый модуль передаёт свои поля — фильтр строится единообразно.
 */
export interface OwnershipFields<W> {
  own: (userId: string) => W;
  team: (teamIds: string[], userId: string) => W;
}

/**
 * Prisma-where для области видимости. Используется в каждом репозитории,
 * поэтому ограничение данных не зависит от фронтенда.
 *  ALL  → без ограничений
 *  TEAM → отделы, которыми руководит пользователь (+ его собственный отдел)
 *  OWN  → только свои записи
 */
export function scopeWhere<W extends object>(
  auth: AuthContext,
  code: PermissionCode,
  fields: OwnershipFields<W>,
): W | Record<string, never> {
  const scope = scopeOf(auth, code);
  if (scope === 'ALL') return {};
  if (scope === 'TEAM') {
    const teamIds = [...new Set([...auth.headedTeamIds, ...(auth.teamId ? [auth.teamId] : [])])];
    return fields.team(teamIds, auth.userId);
  }
  return fields.own(auth.userId);
}

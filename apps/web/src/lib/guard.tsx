import { hasPermission, type PermissionCode, type Scope } from '@fluggi/contracts';
import { Forbidden } from '@/components/shared/states';
import { getMe } from './server-api';

/** Серверная проверка для страницы: без права показываем «Нет доступа». API всё равно проверит сам. */
export async function RequirePermission({
  code,
  minScope,
  children,
}: {
  code: PermissionCode;
  minScope?: Scope;
  children: React.ReactNode;
}) {
  const me = await getMe();
  if (!me || !hasPermission(me.permissions, code, minScope)) return <Forbidden />;
  return <>{children}</>;
}

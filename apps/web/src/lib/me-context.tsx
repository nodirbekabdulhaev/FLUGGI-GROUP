'use client';

import { hasPermission, type MeResponse, type PermissionCode, type Scope } from '@fluggi/contracts';
import { createContext, useCallback, useContext } from 'react';

const MeContext = createContext<MeResponse | null>(null);

export function MeProvider({ me, children }: { me: MeResponse; children: React.ReactNode }) {
  return <MeContext.Provider value={me}>{children}</MeContext.Provider>;
}

export function useMe(): MeResponse {
  const me = useContext(MeContext);
  if (!me) throw new Error('useMe() вне MeProvider');
  return me;
}

/**
 * Проверка права для интерфейса (скрыть кнопку/раздел).
 * Это только UX: реальная проверка всегда выполняется на backend.
 */
export function useCan() {
  const me = useMe();
  return useCallback(
    (code: PermissionCode, minScope: Scope = 'OWN') =>
      hasPermission(me.permissions, code, minScope),
    [me.permissions],
  );
}

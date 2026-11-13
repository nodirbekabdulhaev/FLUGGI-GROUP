'use client';

import type { Paginated, UserDto } from '@fluggi/contracts';
import { useQuery } from '@tanstack/react-query';
import { forwardRef } from 'react';
import { NativeSelect } from '@/components/ui/input';
import { api } from '@/lib/api-client';
import { useCan } from '@/lib/me-context';

/**
 * Сотрудники для списков «Ответственный»: менеджеры и РОП в зоне видимости пользователя
 * (CEO ответственным не бывает — получает уведомления). В фильтрах — и CEO (старые записи).
 */
export function useAssignableUsers() {
  const can = useCan();
  return useQuery({
    queryKey: ['users', 'assignable'],
    queryFn: async () => {
      const res = await api<Paginated<UserDto>>('/users', {
        query: { status: 'ACTIVE', pageSize: 100 },
      });
      return res.items.filter((u) => ['MANAGER', 'ROP', 'CEO'].includes(u.role.code));
    },
    enabled: can('employee.read'),
    staleTime: 60_000,
  });
}

export const OwnerSelect = forwardRef<
  HTMLSelectElement,
  React.SelectHTMLAttributes<HTMLSelectElement> & { emptyLabel?: string; filter?: boolean }
>(({ emptyLabel, filter, ...props }, ref) => {
  const all = useAssignableUsers();
  const users = {
    data: all.data?.filter((u) => filter || u.role.code !== 'CEO'),
  };
  return (
    <NativeSelect ref={ref} {...props}>
      {emptyLabel !== undefined ? <option value="">{emptyLabel}</option> : null}
      {users.data?.map((u) => (
        <option key={u.id} value={u.id}>
          {u.fullName}
          {u.team ? ` · ${u.team.name}` : ''}
        </option>
      ))}
    </NativeSelect>
  );
});
OwnerSelect.displayName = 'OwnerSelect';

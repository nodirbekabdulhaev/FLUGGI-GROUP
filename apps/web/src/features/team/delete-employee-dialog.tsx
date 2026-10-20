'use client';

import type { UserDto, UserWorkloadDto } from '@fluggi/contracts';
import { useQuery } from '@tanstack/react-query';
import { useTranslations } from 'next-intl';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter } from '@/components/ui/dialog';
import { NativeSelect } from '@/components/ui/input';
import { Field } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { api, errorMessage } from '@/lib/api-client';
import { useDeleteUser, useUsers } from './api';

const KEYS = ['leads', 'deals', 'clients', 'projects', 'tasks', 'todos', 'threads'] as const;

/**
 * Удаление сотрудника: показываем его открытую работу и спрашиваем, кому её передать.
 * История (оплаты, комиссии, зарплата, журнал) остаётся с его именем.
 */
export function DeleteEmployeeDialog({
  user,
  onClose,
}: {
  user: UserDto | null;
  onClose: () => void;
}) {
  const t = useTranslations('employees');
  const work = useQuery({
    queryKey: ['users', 'workload', user?.id],
    queryFn: () => api<UserWorkloadDto>(`/users/${user!.id}/workload`),
    enabled: Boolean(user),
    staleTime: 0,
  });
  const people = useUsers({ pageSize: 100, status: 'ACTIVE' }, Boolean(user));
  const remove = useDeleteUser();
  const [to, setTo] = useState('');
  useEffect(() => setTo(''), [user]);
  const needsTarget = (work.data?.total ?? 0) > 0;

  return (
    <Dialog open={Boolean(user)} onOpenChange={(o) => !o && onClose()}>
      <DialogContent title={t('deleteTitle', { name: user?.fullName ?? '' })}>
        <div className="grid gap-4 text-sm">
          <p className="text-muted-foreground">{t('deleteText')}</p>
          {work.isPending ? (
            <Skeleton className="h-16" />
          ) : work.data ? (
            needsTarget ? (
              <div className="grid gap-3">
                <ul className="grid grid-cols-2 gap-x-4 gap-y-1 rounded-md bg-muted/50 p-3">
                  {KEYS.filter((k) => work.data[k] > 0).map((k) => (
                    <li key={k} className="flex justify-between gap-2">
                      <span className="text-muted-foreground">{t(`work.${k}`)}</span>
                      <span className="font-medium tabular-nums">{work.data[k]}</span>
                    </li>
                  ))}
                </ul>
                <Field label={t('transferTo')} htmlFor="del-to" hint={t('transferHint')}>
                  <NativeSelect id="del-to" value={to} onChange={(e) => setTo(e.target.value)}>
                    <option value="">—</option>
                    {people.data?.items
                      .filter((u) => u.id !== user?.id)
                      .map((u) => (
                        <option key={u.id} value={u.id}>
                          {u.fullName} · {u.role.name}
                        </option>
                      ))}
                  </NativeSelect>
                </Field>
              </div>
            ) : (
              <p>{t('noOpenWork')}</p>
            )
          ) : null}
        </div>
        <DialogFooter>
          <Button type="button" variant="outline" onClick={onClose}>
            {t('cancel')}
          </Button>
          <Button
            variant="destructive"
            loading={remove.isPending}
            disabled={work.isPending || (needsTarget && !to)}
            onClick={async () => {
              try {
                await remove.mutateAsync({ id: user!.id, transferToId: to || null });
                toast.success(t('deleted'));
                onClose();
              } catch (err) {
                toast.error(errorMessage(err));
              }
            }}
          >
            {t('deleteConfirmButton')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

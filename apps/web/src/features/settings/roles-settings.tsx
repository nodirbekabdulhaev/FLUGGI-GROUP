'use client';

import {
  PERMISSIONS,
  PERMISSION_CODES,
  SCOPES,
  type PermissionMap,
  type RoleDto,
  type Scope,
} from '@fluggi/contracts';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useTranslations } from 'next-intl';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import { ErrorState, TableSkeleton } from '@/components/shared/states';
import { Button } from '@/components/ui/button';
import { Card, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { NativeSelect } from '@/components/ui/input';
import { api, errorMessage } from '@/lib/api-client';
import { cn } from '@/lib/utils';

const MODULES = [...new Set(PERMISSION_CODES.map((c) => c.split('.')[0]!))];

export function RolesSettings() {
  const t = useTranslations();
  const qc = useQueryClient();
  const roles = useQuery({ queryKey: ['roles'], queryFn: () => api<RoleDto[]>('/roles') });
  const [roleId, setRoleId] = useState<string>('');
  const [draft, setDraft] = useState<PermissionMap>({});

  const role = useMemo(
    () => roles.data?.find((r) => r.id === roleId) ?? roles.data?.[0],
    [roles.data, roleId],
  );
  useEffect(() => {
    if (role) setDraft(role.permissions);
  }, [role]);

  const dirty = role
    ? JSON.stringify(sortKeys(draft)) !== JSON.stringify(sortKeys(role.permissions))
    : false;

  const save = useMutation({
    mutationFn: () =>
      api<RoleDto>(`/roles/${role!.id}/permissions`, {
        method: 'PUT',
        body: { permissions: draft },
      }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['roles'] }),
  });

  if (roles.isPending)
    return (
      <Card>
        <TableSkeleton rows={8} cols={2} />
      </Card>
    );
  if (roles.isError)
    return (
      <Card>
        <ErrorState error={roles.error} onRetry={() => roles.refetch()} />
      </Card>
    );

  return (
    <Card>
      <CardHeader className="border-b pb-5">
        <CardTitle>{t('settings.rolesTitle')}</CardTitle>
        <CardDescription>{t('settings.rolesText')}</CardDescription>
        <div className="mt-3 flex gap-1 overflow-x-auto">
          {roles.data.map((r) => (
            <button
              key={r.id}
              type="button"
              onClick={() => setRoleId(r.id)}
              className={cn(
                'whitespace-nowrap rounded-md px-3 py-1.5 text-sm',
                r.id === role?.id
                  ? 'bg-primary text-primary-foreground'
                  : 'bg-muted text-muted-foreground hover:text-foreground',
              )}
            >
              {t(`roles.${r.code}`)} <span className="opacity-60">· {r.usersCount}</span>
            </button>
          ))}
        </div>
      </CardHeader>

      <div className="divide-y">
        {MODULES.map((module) => (
          <div key={module} className="px-5 py-3">
            <p className="mb-2 text-xs font-medium uppercase tracking-wide text-muted-foreground">
              {t.has(`permissionModules.${module}`) ? t(`permissionModules.${module}`) : module}
            </p>
            <div className="grid gap-2 sm:grid-cols-2">
              {PERMISSION_CODES.filter((c) => c.startsWith(`${module}.`)).map((code) => (
                <label key={code} className="flex items-center justify-between gap-3 text-sm">
                  <span className="min-w-0 truncate" title={code}>
                    {PERMISSIONS[code]}
                  </span>
                  <NativeSelect
                    className="h-8 w-28 shrink-0 text-xs"
                    value={draft[code] ?? ''}
                    disabled={role?.code === 'CEO'}
                    onChange={(e) =>
                      setDraft((d) => {
                        const next = { ...d };
                        if (e.target.value) next[code] = e.target.value as Scope;
                        else delete next[code];
                        return next;
                      })
                    }
                  >
                    <option value="">{t('scopes.none')}</option>
                    {SCOPES.map((s) => (
                      <option key={s} value={s}>
                        {t(`scopes.${s}`)}
                      </option>
                    ))}
                  </NativeSelect>
                </label>
              ))}
            </div>
          </div>
        ))}
      </div>

      {role?.code !== 'CEO' ? (
        <div className="sticky bottom-0 flex items-center justify-end gap-3 border-t bg-surface px-5 py-3">
          {dirty ? (
            <span className="text-sm text-warning">{t('settings.rolesUnsaved')}</span>
          ) : null}
          <Button
            variant="outline"
            disabled={!dirty}
            onClick={() => role && setDraft(role.permissions)}
          >
            {t('common.cancel')}
          </Button>
          <Button
            disabled={!dirty}
            loading={save.isPending}
            loadingText={t('common.saving')}
            onClick={async () => {
              try {
                await save.mutateAsync();
                toast.success(t('settings.rolesSaved'));
              } catch (err) {
                toast.error(errorMessage(err));
              }
            }}
          >
            {t('common.save')}
          </Button>
        </div>
      ) : null}
    </Card>
  );
}

function sortKeys(map: PermissionMap) {
  return Object.fromEntries(Object.entries(map).sort(([a], [b]) => a.localeCompare(b)));
}

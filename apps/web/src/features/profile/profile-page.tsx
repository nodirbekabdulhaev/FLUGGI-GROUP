'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import {
  changePasswordSchema,
  type ChangePasswordInput,
  type SessionInfo,
} from '@fluggi/contracts';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Monitor } from 'lucide-react';
import { useFormatter, useNow, useTranslations } from 'next-intl';
import { useForm } from 'react-hook-form';
import { toast } from 'sonner';
import { PageHeader } from '@/components/shared/page-header';
import { ErrorState, TableSkeleton } from '@/components/shared/states';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Field } from '@/components/ui/label';
import { ApiError, api, errorMessage } from '@/lib/api-client';
import { useMe } from '@/lib/me-context';
import { NotificationSettingsCard, TelegramCard } from './telegram-card';

function ChangePassword() {
  const t = useTranslations();
  const qc = useQueryClient();
  const form = useForm<ChangePasswordInput>({
    resolver: zodResolver(changePasswordSchema),
    defaultValues: { currentPassword: '', newPassword: '' },
  });
  const onSubmit = form.handleSubmit(async (values) => {
    try {
      await api('/auth/change-password', { method: 'POST', body: values });
      toast.success(t('profile.passwordChanged'));
      form.reset();
      await qc.invalidateQueries({ queryKey: ['sessions'] });
    } catch (err) {
      if (err instanceof ApiError) {
        for (const [path, message] of Object.entries(err.fieldErrors())) {
          form.setError(path as keyof ChangePasswordInput, { message });
        }
      }
      toast.error(errorMessage(err));
    }
  });
  const { errors, isSubmitting } = form.formState;
  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('profile.changePassword')}</CardTitle>
      </CardHeader>
      <CardContent>
        <form onSubmit={onSubmit} className="grid gap-4" noValidate>
          <Field
            label={t('profile.currentPassword')}
            htmlFor="currentPassword"
            error={errors.currentPassword?.message}
          >
            <Input
              id="currentPassword"
              type="password"
              autoComplete="current-password"
              {...form.register('currentPassword')}
            />
          </Field>
          <Field
            label={t('profile.newPassword')}
            htmlFor="newPassword"
            error={errors.newPassword?.message}
            hint={t('profile.passwordHint')}
          >
            <Input
              id="newPassword"
              type="password"
              autoComplete="new-password"
              {...form.register('newPassword')}
            />
          </Field>
          <div>
            <Button type="submit" loading={isSubmitting} loadingText={t('common.saving')}>
              {t('common.save')}
            </Button>
          </div>
        </form>
      </CardContent>
    </Card>
  );
}

function Sessions() {
  const t = useTranslations('profile');
  const format = useFormatter();
  // Явное «сейчас» с обновлением раз в минуту — «5 минут назад» не устаревает.
  const now = useNow({ updateInterval: 60_000 });
  const qc = useQueryClient();
  const sessions = useQuery({
    queryKey: ['sessions'],
    queryFn: () => api<SessionInfo[]>('/auth/sessions'),
  });
  const revoke = useMutation({
    mutationFn: (id: string) => api(`/auth/sessions/${id}`, { method: 'DELETE' }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['sessions'] }),
  });
  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('sessions')}</CardTitle>
        <CardDescription>{t('sessionsText')}</CardDescription>
      </CardHeader>
      {sessions.isPending ? (
        <TableSkeleton rows={2} cols={1} />
      ) : sessions.isError ? (
        <ErrorState error={sessions.error} onRetry={() => sessions.refetch()} />
      ) : (
        <ul className="max-h-96 divide-y overflow-y-auto border-t">
          {sessions.data.map((s) => (
            <li key={s.id} className="flex items-center gap-3 px-5 py-3">
              <Monitor className="size-4 shrink-0 text-muted-foreground" />
              <div className="min-w-0 flex-1 text-sm">
                <p className="truncate">{s.userAgent ?? '—'}</p>
                <p className="text-xs text-muted-foreground">
                  {s.ip ?? ''} ·{' '}
                  {t('lastSeen', { date: format.relativeTime(new Date(s.lastSeenAt), now) })}
                </p>
              </div>
              {s.current ? (
                <Badge tone="success">{t('thisDevice')}</Badge>
              ) : (
                <Button
                  variant="outline"
                  size="sm"
                  disabled={revoke.isPending}
                  onClick={async () => {
                    try {
                      await revoke.mutateAsync(s.id);
                      toast.success(t('revoked'));
                    } catch (err) {
                      toast.error(errorMessage(err));
                    }
                  }}
                >
                  {t('revoke')}
                </Button>
              )}
            </li>
          ))}
        </ul>
      )}
    </Card>
  );
}

export function ProfilePage() {
  const t = useTranslations();
  const me = useMe();
  const rows: [string, string][] = [
    [t('employees.fullName'), me.fullName],
    [t('employees.email'), me.email],
    [t('employees.role'), t(`roles.${me.role.code}`)],
    [t('employees.team'), me.team?.name ?? t('common.notSet')],
  ];
  return (
    <>
      <PageHeader title={t('profile.title')} description={t('profile.subtitle')} />
      <div className="grid gap-6 lg:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle>{t('profile.info')}</CardTitle>
          </CardHeader>
          <CardContent>
            <dl className="grid gap-3 text-sm">
              {rows.map(([label, value]) => (
                <div key={label} className="grid grid-cols-[8rem_1fr] gap-3">
                  <dt className="text-muted-foreground">{label}</dt>
                  <dd className="min-w-0 break-words">{value}</dd>
                </div>
              ))}
            </dl>
          </CardContent>
        </Card>
        <ChangePassword />
        <TelegramCard />
        <Sessions />
        <div className="lg:col-span-2">
          <NotificationSettingsCard />
        </div>
      </div>
    </>
  );
}

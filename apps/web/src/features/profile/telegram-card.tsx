'use client';

import type { NotificationSettingDto, TelegramLinkDto } from '@fluggi/contracts';
import { Copy, ExternalLink, Send } from 'lucide-react';
import { useTranslations } from 'next-intl';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { ErrorState, TableSkeleton } from '@/components/shared/states';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import {
  useInvalidating,
  useNotificationSettings,
  useTelegramStatus,
} from '@/features/automation/api';
import { api, errorMessage } from '@/lib/api-client';

/** Подключение Telegram-бота (ТЗ §53): одноразовый код → /start в боте. */
export function TelegramCard() {
  const t = useTranslations('telegram');
  const status = useTelegramStatus();
  const [link, setLink] = useState<TelegramLinkDto | null>(null);
  const create = useInvalidating([['telegram']], () =>
    api<TelegramLinkDto>('/me/telegram/link', { method: 'POST' }),
  );
  const unlink = useInvalidating([['telegram']], () => api('/me/telegram', { method: 'DELETE' }));
  const test = useInvalidating([], () => api('/me/telegram/test', { method: 'POST' }));

  // Пока код показан — проверяем, не подключился ли пользователь в боте
  const { refetch } = status;
  const linked = status.data?.linked;
  useEffect(() => {
    if (!link || linked) return;
    const id = setInterval(() => void refetch(), 3000);
    return () => clearInterval(id);
  }, [link, linked, refetch]);
  useEffect(() => {
    if (linked && link) {
      setLink(null);
      toast.success(t('linkedToast'));
    }
  }, [linked, link, t]);

  const run = async (fn: () => Promise<unknown>, ok?: string) => {
    try {
      await fn();
      if (ok) toast.success(ok);
    } catch (err) {
      toast.error(errorMessage(err));
    }
  };

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2">
          <Send className="size-4" /> {t('title')}
        </CardTitle>
        <CardDescription>{t('text')}</CardDescription>
      </CardHeader>
      <CardContent>
        {status.isPending ? (
          <TableSkeleton rows={1} cols={1} />
        ) : status.isError ? (
          <ErrorState error={status.error} onRetry={() => status.refetch()} />
        ) : !status.data.botConfigured ? (
          <p className="text-sm text-muted-foreground" data-testid="telegram-not-configured">
            {t('notConfigured')}
          </p>
        ) : status.data.problem ? (
          <div
            role="alert"
            data-testid="telegram-problem"
            className="rounded-md bg-danger-soft px-3 py-2 text-sm text-danger"
          >
            <p className="font-medium">{t('problem')}</p>
            <p className="mt-1">{status.data.problem}</p>
          </div>
        ) : status.data.linked ? (
          <div className="grid gap-4">
            <p className="flex flex-wrap items-center gap-2 text-sm">
              <Badge tone="success">{t('linked')}</Badge>
              {status.data.username ? `@${status.data.username}` : null}
              {status.data.botUsername ? (
                <span className="text-muted-foreground">
                  · {t('bot', { name: status.data.botUsername })}
                </span>
              ) : null}
            </p>
            <div className="flex flex-wrap gap-2">
              <Button
                variant="outline"
                loading={test.isPending}
                onClick={() => run(() => test.mutateAsync(undefined), t('testSent'))}
              >
                {t('test')}
              </Button>
              <Button
                variant="outline"
                loading={unlink.isPending}
                onClick={() => run(() => unlink.mutateAsync(undefined), t('unlinked'))}
              >
                {t('unlink')}
              </Button>
            </div>
          </div>
        ) : link ? (
          <div className="grid gap-3 text-sm">
            <p>{t('step1')}</p>
            <div>
              <Button asChild>
                <a href={link.deepLink} target="_blank" rel="noreferrer">
                  <ExternalLink className="size-4" /> {t('open')}
                </a>
              </Button>
            </div>
            <p className="text-muted-foreground">{t('step2')}</p>
            <div className="flex items-center gap-2">
              <code
                className="min-w-0 flex-1 truncate rounded-md bg-muted px-3 py-2 text-xs"
                data-testid="telegram-command"
              >
                {link.command}
              </code>
              <Button
                variant="outline"
                size="sm"
                aria-label={t('copy')}
                onClick={() => run(() => navigator.clipboard.writeText(link.command), t('copied'))}
              >
                <Copy className="size-4" />
              </Button>
            </div>
            <p className="text-xs text-muted-foreground">{t('expires')}</p>
          </div>
        ) : (
          <Button
            loading={create.isPending}
            onClick={() => run(async () => setLink(await create.mutateAsync(undefined)))}
          >
            {t('connect')}
          </Button>
        )}
      </CardContent>
    </Card>
  );
}

/** Индивидуальные настройки уведомлений (ТЗ §14): по типам и каналам. */
export function NotificationSettingsCard() {
  const t = useTranslations('telegram');
  const settings = useNotificationSettings();
  const qc = useQueryClient();
  const key = ['notifications', 'settings'];
  const save = useMutation({
    mutationFn: (s: { eventType: string; channel: 'IN_APP' | 'TELEGRAM'; enabled: boolean }) =>
      api<NotificationSettingDto[]>('/notifications/settings', {
        method: 'PUT',
        body: { settings: [s] },
      }),
    onSuccess: (data) => qc.setQueryData(key, data),
  });
  // Локальное состояние меняется синхронно с кликом; сервер подтверждает асинхронно
  const [local, setLocal] = useState<Record<string, boolean>>({});
  const toggle = async (eventType: string, channel: 'IN_APP' | 'TELEGRAM', enabled: boolean) => {
    const k = `${eventType}:${channel}`;
    setLocal((m) => ({ ...m, [k]: enabled }));
    try {
      await save.mutateAsync({ eventType, channel, enabled });
    } catch (err) {
      toast.error(errorMessage(err));
    } finally {
      setLocal(({ [k]: _, ...rest }) => rest);
    }
  };
  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('settingsTitle')}</CardTitle>
        <CardDescription>{t('settingsText')}</CardDescription>
      </CardHeader>
      {settings.isPending ? (
        <TableSkeleton rows={6} cols={3} />
      ) : settings.isError ? (
        <ErrorState error={settings.error} onRetry={() => settings.refetch()} />
      ) : (
        <Table>
          <THead>
            <TR>
              <TH>{t('event')}</TH>
              <TH className="w-28 text-center">{t('inApp')}</TH>
              <TH className="w-28 text-center">Telegram</TH>
            </TR>
          </THead>
          <TBody>
            {settings.data.map((s) => (
              <TR key={s.type}>
                <TD>{s.label}</TD>
                {(['IN_APP', 'TELEGRAM'] as const).map((ch) => {
                  const on = local[`${s.type}:${ch}`] ?? (ch === 'IN_APP' ? s.inApp : s.telegram);
                  return (
                    <TD key={ch} className="text-center">
                      <input
                        type="checkbox"
                        className="size-4 accent-[var(--color-accent)]"
                        aria-label={`${s.label}: ${ch === 'IN_APP' ? t('inApp') : 'Telegram'}`}
                        checked={on}
                        onChange={(e) => toggle(s.type, ch, e.target.checked)}
                      />
                    </TD>
                  );
                })}
              </TR>
            ))}
          </TBody>
        </Table>
      )}
    </Card>
  );
}

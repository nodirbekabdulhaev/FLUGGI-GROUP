'use client';

import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Bell, CheckCheck } from 'lucide-react';
import { useTranslations } from 'next-intl';
import { useTelegramStatus } from '@/features/automation/api';
import Link from 'next/link';
import { useState } from 'react';
import { PageHeader } from '@/components/shared/page-header';
import { Pagination } from '@/components/shared/pagination';
import { EmptyState, ErrorState, TableSkeleton } from '@/components/shared/states';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useNotifications, useUnreadCount as useUnread } from '@/features/crm/api';
import { api } from '@/lib/api-client';
import { dateTime } from '@/lib/format';
import { cn } from '@/lib/utils';

export function NotificationsPage() {
  const t = useTranslations('notifications');
  const qc = useQueryClient();
  const [page, setPage] = useState(1);
  const list = useNotifications(page);
  const refresh = () => qc.invalidateQueries({ queryKey: ['notifications'] });
  const readAll = useMutation({
    mutationFn: () => api('/notifications/read-all', { method: 'POST' }),
    onSuccess: refresh,
  });
  const read = useMutation({
    mutationFn: (id: string) => api(`/notifications/${id}/read`, { method: 'POST' }),
    onSuccess: refresh,
  });

  return (
    <>
      <PageHeader
        title={t('title')}
        description={t('subtitle')}
        actions={
          <Button variant="outline" onClick={() => readAll.mutate()} loading={readAll.isPending}>
            <CheckCheck /> {t('readAll')}
          </Button>
        }
      />
      <Card>
        {list.isPending ? (
          <TableSkeleton />
        ) : list.isError ? (
          <ErrorState error={list.error} onRetry={() => list.refetch()} />
        ) : list.data.items.length === 0 ? (
          <EmptyState icon={Bell} title={t('empty')} text={t('emptyText')} />
        ) : (
          <>
            <ul className="divide-y">
              {list.data.items.map((n) => (
                <li
                  key={n.id}
                  className={cn(
                    'flex items-start gap-3 px-5 py-3.5',
                    !n.readAt && 'bg-accent-soft/50',
                  )}
                >
                  <span
                    className={cn(
                      'mt-1.5 size-2 shrink-0 rounded-full',
                      n.readAt ? 'bg-transparent' : 'bg-accent',
                    )}
                    aria-hidden
                  />
                  <div className="min-w-0 flex-1">
                    <p className="text-sm font-medium">{n.title}</p>
                    {n.body ? <p className="text-sm text-muted-foreground">{n.body}</p> : null}
                    <p className="mt-0.5 text-xs text-muted-foreground">{dateTime(n.createdAt)}</p>
                  </div>
                  {n.link ? (
                    <Button asChild size="sm" variant="ghost">
                      <Link href={n.link} onClick={() => !n.readAt && read.mutate(n.id)}>
                        {t('open')}
                      </Link>
                    </Button>
                  ) : null}
                </li>
              ))}
            </ul>
            <Pagination
              page={list.data.page}
              pageSize={list.data.pageSize}
              total={list.data.total}
              onPage={setPage}
            />
          </>
        )}
      </Card>
      <TelegramLine />
    </>
  );
}

export function NotificationBell() {
  const t = useTranslations('notifications');
  const { data } = useUnread();
  const count = data?.count ?? 0;
  return (
    <Button
      asChild
      variant="ghost"
      size="icon"
      aria-label={`${t('title')}${count ? ` (${count})` : ''}`}
    >
      <Link href="/notifications" className="relative">
        <Bell className="size-5" />
        {count > 0 ? (
          <span className="absolute right-1.5 top-1.5 flex min-w-4 items-center justify-center rounded-full bg-danger px-1 text-[10px] font-semibold leading-4 text-white">
            {count > 99 ? '99+' : count}
          </span>
        ) : null}
      </Link>
    </Button>
  );
}

/** Статус дублирования в Telegram: подключён / не подключён / бот не работает. */
function TelegramLine() {
  const t = useTranslations('notifications');
  const tg = useTelegramStatus();
  if (!tg.data) return null;
  const d = tg.data;
  const text = !d.botConfigured
    ? t('tgNotConfigured')
    : d.problem
      ? t('tgProblem', { problem: d.problem })
      : d.linked
        ? t('tgOn', { username: d.username ? `@${d.username}` : '' })
        : t('tgOff');
  const ok = d.botConfigured && !d.problem && d.linked;
  return (
    <p className={`mt-3 text-xs ${ok ? 'text-success' : 'text-muted-foreground'}`}>
      {text}{' '}
      {!ok ? (
        <Link href="/profile" className="font-medium text-accent hover:underline">
          {t('tgSetup')}
        </Link>
      ) : null}
    </p>
  );
}

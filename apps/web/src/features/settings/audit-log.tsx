'use client';

import type { AuditLogDto, Paginated } from '@fluggi/contracts';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { useFormatter, useTranslations } from 'next-intl';
import { useState } from 'react';
import { Pagination } from '@/components/shared/pagination';
import { EmptyState, ErrorState, TableSkeleton } from '@/components/shared/states';
import { Card, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { api } from '@/lib/api-client';

function show(v: unknown) {
  if (v === null || v === undefined || v === '') return '—';
  return typeof v === 'object' ? JSON.stringify(v) : String(v);
}

export function AuditLog() {
  const t = useTranslations();
  const format = useFormatter();
  const [page, setPage] = useState(1);
  const logs = useQuery({
    queryKey: ['audit', page],
    queryFn: () => api<Paginated<AuditLogDto>>('/audit-logs', { query: { page, pageSize: 30 } }),
    placeholderData: keepPreviousData,
  });

  return (
    <Card>
      <CardHeader className="border-b pb-5">
        <CardTitle>{t('settings.auditTitle')}</CardTitle>
        <CardDescription>{t('settings.auditText')}</CardDescription>
      </CardHeader>
      {logs.isPending ? (
        <TableSkeleton />
      ) : logs.isError ? (
        <ErrorState error={logs.error} onRetry={() => logs.refetch()} />
      ) : logs.data.items.length === 0 ? (
        <EmptyState title={t('settings.auditEmpty')} />
      ) : (
        <>
          <ul className="divide-y">
            {logs.data.items.map((log) => (
              <li key={log.id} className="grid gap-1 px-5 py-3 text-sm sm:grid-cols-[10rem_1fr]">
                <span className="text-muted-foreground">
                  {format.dateTime(new Date(log.createdAt), {
                    dateStyle: 'short',
                    timeStyle: 'short',
                  })}
                </span>
                <div className="min-w-0">
                  <p>
                    <span className="font-medium">
                      {log.actor?.fullName ?? t('settings.auditSystem')}
                    </span>{' '}
                    <span className="text-muted-foreground">
                      — {t.has(`audit.${log.action}`) ? t(`audit.${log.action}`) : log.action}
                    </span>
                  </p>
                  {log.changes ? (
                    <ul className="mt-1 grid gap-0.5 text-xs text-muted-foreground">
                      {Object.entries(log.changes).map(([field, change]) => (
                        <li key={field} className="break-all">
                          <span className="font-mono">{field}</span>: {show(change.old)} →{' '}
                          {show(change.new)}
                        </li>
                      ))}
                    </ul>
                  ) : null}
                  {log.ip ? <p className="text-xs text-muted-foreground/70">IP {log.ip}</p> : null}
                </div>
              </li>
            ))}
          </ul>
          <Pagination
            page={logs.data.page}
            pageSize={logs.data.pageSize}
            total={logs.data.total}
            onPage={setPage}
          />
        </>
      )}
    </Card>
  );
}

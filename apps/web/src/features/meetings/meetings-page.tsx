'use client';

import { useTranslations } from 'next-intl';
import { useEffect, useState } from 'react';
import { PageHeader } from '@/components/shared/page-header';
import { Pagination } from '@/components/shared/pagination';
import { EmptyState, ErrorState, TableSkeleton } from '@/components/shared/states';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useMeetings } from '@/features/crm/api';
import { CompleteMeetingDialog } from '@/features/crm/meeting-dialogs';
import { MeetingRow } from '@/features/crm/panels';
import { useCan } from '@/lib/me-context';

type View = 'upcoming' | 'done' | 'all';

export function MeetingsPage() {
  const t = useTranslations('meetings');
  const can = useCan();
  const [view, setView] = useState<View>('upcoming');
  const [page, setPage] = useState(1);
  const [completing, setCompleting] = useState<string | null>(null);
  useEffect(() => setPage(1), [view]);
  const today = new Date(Date.now() + 5 * 3600_000).toISOString().slice(0, 10);
  const meetings = useMeetings({
    status: view === 'done' ? 'DONE' : undefined,
    dateFrom: view === 'upcoming' ? today : undefined,
    page,
    pageSize: 25,
  });
  const items = meetings.data?.items.filter(
    (m) => view !== 'upcoming' || !['DONE', 'CANCELLED'].includes(m.status),
  );

  return (
    <>
      <PageHeader title={t('title')} description={t('subtitle')} />
      <div className="mb-4 flex gap-2">
        {(['upcoming', 'done', 'all'] as const).map((v) => (
          <Button
            key={v}
            size="sm"
            variant={view === v ? 'default' : 'outline'}
            onClick={() => setView(v)}
          >
            {t(v)}
          </Button>
        ))}
      </div>
      <Card>
        {meetings.isPending ? (
          <TableSkeleton />
        ) : meetings.isError ? (
          <ErrorState error={meetings.error} onRetry={() => meetings.refetch()} />
        ) : !items || items.length === 0 ? (
          <EmptyState title={t('emptyTitle')} text={t('emptyText')} />
        ) : (
          <>
            <ul className="divide-y">
              {items.map((m) => (
                <MeetingRow
                  key={m.id}
                  m={m}
                  onComplete={can('meeting.update') ? setCompleting : undefined}
                />
              ))}
            </ul>
            <Pagination
              page={meetings.data.page}
              pageSize={meetings.data.pageSize}
              total={meetings.data.total}
              onPage={setPage}
            />
          </>
        )}
      </Card>
      <CompleteMeetingDialog
        meetingId={completing}
        onOpenChange={(o) => !o && setCompleting(null)}
      />
    </>
  );
}

'use client';

import type { FollowUpDto } from '@fluggi/contracts';
import { Repeat } from 'lucide-react';
import Link from 'next/link';
import { useFormatter, useTranslations } from 'next-intl';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { PageHeader } from '@/components/shared/page-header';
import { Pagination } from '@/components/shared/pagination';
import { EmptyState, ErrorState, TableSkeleton } from '@/components/shared/states';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Dialog, DialogContent, DialogFooter } from '@/components/ui/dialog';
import { Field } from '@/components/ui/label';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import { api, errorMessage } from '@/lib/api-client';
import { useCan } from '@/lib/me-context';
import { useFollowUps, useInvalidating } from './api';

type View = 'due' | 'pending' | 'closed';

function CompleteDialog({ item, onClose }: { item: FollowUpDto | null; onClose: () => void }) {
  const t = useTranslations('followUps');
  const can = useCan();
  const [result, setResult] = useState('');
  const [createDeal, setCreateDeal] = useState(false);
  useEffect(() => {
    setResult('');
    setCreateDeal(item?.kind === 'REPEAT_SALE');
  }, [item]);
  const complete = useInvalidating([['follow-ups'], ['deals']], (status: 'DONE' | 'SKIPPED') =>
    api<FollowUpDto>(`/follow-ups/${item!.id}/complete`, {
      method: 'POST',
      body: { status, result: result || undefined, createDeal: status === 'DONE' && createDeal },
    }),
  );
  const submit = async (status: 'DONE' | 'SKIPPED') => {
    try {
      const r = await complete.mutateAsync(status);
      toast.success(r.resultDeal ? t('dealCreated', { number: r.resultDeal.number }) : t('closed'));
      onClose();
    } catch (err) {
      toast.error(errorMessage(err));
    }
  };
  return (
    <Dialog open={Boolean(item)} onOpenChange={(o) => !o && onClose()}>
      <DialogContent
        title={t('completeTitle')}
        description={item ? `${item.client.name}` : undefined}
      >
        <div className="grid gap-4">
          <Field label={t('result')} htmlFor="fu-result">
            <Textarea
              id="fu-result"
              rows={3}
              placeholder={t('resultPlaceholder')}
              value={result}
              onChange={(e) => setResult(e.target.value)}
            />
          </Field>
          {can('deal.create') ? (
            <label className="flex items-center gap-2 text-sm">
              <input
                type="checkbox"
                checked={createDeal}
                onChange={(e) => setCreateDeal(e.target.checked)}
              />
              {t('createDeal')}
            </label>
          ) : null}
        </div>
        <DialogFooter>
          <Button variant="outline" disabled={complete.isPending} onClick={() => submit('SKIPPED')}>
            {t('skip')}
          </Button>
          <Button loading={complete.isPending} onClick={() => submit('DONE')}>
            {t('done')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

/** Повторные продажи (ТЗ §38): follow-up через 30/60/90 дней после завершения проекта. */
export function FollowUpsPage() {
  const t = useTranslations('followUps');
  const format = useFormatter();
  const [view, setView] = useState<View>('due');
  const [page, setPage] = useState(1);
  const [completing, setCompleting] = useState<FollowUpDto | null>(null);
  useEffect(() => setPage(1), [view]);
  const list = useFollowUps({
    due: view === 'due' ? 'true' : undefined,
    status: view === 'pending' ? 'PENDING' : undefined,
    page,
    pageSize: 25,
  });
  const items = list.data?.items.filter((f) => view !== 'closed' || f.status !== 'PENDING');

  return (
    <>
      <PageHeader title={t('title')} description={t('subtitle')} />
      <div className="mb-4 flex gap-2">
        {(['due', 'pending', 'closed'] as const).map((v) => (
          <Button
            key={v}
            size="sm"
            variant={view === v ? 'default' : 'outline'}
            onClick={() => setView(v)}
          >
            {t(`views.${v}`)}
          </Button>
        ))}
      </div>
      <Card>
        {list.isPending ? (
          <TableSkeleton />
        ) : list.isError ? (
          <ErrorState error={list.error} onRetry={() => list.refetch()} />
        ) : !items || items.length === 0 ? (
          <EmptyState icon={Repeat} title={t('emptyTitle')} text={t('emptyText')} />
        ) : (
          <>
            <Table>
              <THead>
                <TR>
                  <TH>{t('client')}</TH>
                  <TH>{t('kind')}</TH>
                  <TH>{t('project')}</TH>
                  <TH>{t('due')}</TH>
                  <TH>{t('owner')}</TH>
                  <TH>{t('status')}</TH>
                  <TH className="w-32" />
                </TR>
              </THead>
              <TBody>
                {items.map((f) => (
                  <TR key={f.id}>
                    <TD className="font-medium">
                      <Link href={`/clients/${f.client.id}`} className="hover:underline">
                        {f.client.name}
                      </Link>
                    </TD>
                    <TD>{t(`kinds.${f.kind}`)}</TD>
                    <TD>
                      {f.project ? (
                        <Link
                          href={`/projects/${f.project.id}`}
                          className="text-muted-foreground hover:underline"
                        >
                          {f.project.number} {f.project.name}
                        </Link>
                      ) : (
                        '—'
                      )}
                    </TD>
                    <TD className="whitespace-nowrap">
                      {format.dateTime(new Date(f.dueDate), {
                        dateStyle: 'medium',
                        timeZone: 'UTC',
                      })}
                      {f.overdueDays > 0 ? (
                        <Badge tone="danger" className="ml-2">
                          {t('overdue', { days: f.overdueDays })}
                        </Badge>
                      ) : null}
                    </TD>
                    <TD>{f.owner.name}</TD>
                    <TD>
                      {f.status === 'PENDING' ? (
                        <Badge tone="warning">{t('statuses.PENDING')}</Badge>
                      ) : (
                        <span className="grid gap-0.5">
                          <Badge tone={f.status === 'DONE' ? 'success' : 'neutral'}>
                            {t(`statuses.${f.status}`)}
                          </Badge>
                          {f.resultDeal ? (
                            <Link
                              href={`/sales/deals/${f.resultDeal.id}`}
                              className="text-xs hover:underline"
                            >
                              {f.resultDeal.number}
                            </Link>
                          ) : null}
                        </span>
                      )}
                    </TD>
                    <TD className="text-right">
                      {f.status === 'PENDING' ? (
                        <Button size="sm" variant="outline" onClick={() => setCompleting(f)}>
                          {t('complete')}
                        </Button>
                      ) : (
                        <span className="line-clamp-2 text-xs text-muted-foreground">
                          {f.result}
                        </span>
                      )}
                    </TD>
                  </TR>
                ))}
              </TBody>
            </Table>
            <Pagination
              page={list.data.page}
              pageSize={list.data.pageSize}
              total={list.data.total}
              onPage={setPage}
            />
          </>
        )}
      </Card>
      <CompleteDialog item={completing} onClose={() => setCompleting(null)} />
    </>
  );
}

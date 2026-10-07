'use client';

import { ExportMenu } from '@/features/analytics/export-menu';
import { DEAL_STATUSES, type DealStatus } from '@fluggi/contracts';
import { Plus, Search } from 'lucide-react';
import { useTranslations } from 'next-intl';
import Link from 'next/link';
import { useEffect, useState } from 'react';
import { StageBadge, StatusBadge } from '@/components/crm/badges';
import { PageHeader } from '@/components/shared/page-header';
import { Pagination } from '@/components/shared/pagination';
import { EmptyState, ErrorState, TableSkeleton } from '@/components/shared/states';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input, NativeSelect } from '@/components/ui/input';
import { TBody, TD, TH, THead, TR, Table } from '@/components/ui/table';
import { useDeals, useReferences } from '@/features/crm/api';
import { OwnerSelect } from '@/features/crm/owner-select';
import { date, money } from '@/lib/format';
import { useCan } from '@/lib/me-context';
import { DealFormDialog } from './deal-form-dialog';

export function DealsPage() {
  const t = useTranslations();
  const can = useCan();
  const refs = useReferences();
  const [q, setQ] = useState('');
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState<DealStatus | ''>('OPEN');
  const [stageCode, setStageCode] = useState('');
  const [serviceId, setServiceId] = useState('');
  const [ownerId, setOwnerId] = useState('');
  const [page, setPage] = useState(1);
  const [createOpen, setCreateOpen] = useState(false);
  useEffect(() => {
    const id = setTimeout(() => setSearch(q), 300);
    return () => clearTimeout(id);
  }, [q]);
  useEffect(() => {
    setPage(1);
  }, [search, status, stageCode, serviceId, ownerId]);

  const deals = useDeals({
    q: search || undefined,
    status: status || undefined,
    stageCode: (stageCode || undefined) as never,
    serviceId: serviceId || undefined,
    ownerId: ownerId || undefined,
    page,
    pageSize: 25,
  });
  const filtered = Boolean(search || status !== 'OPEN' || stageCode || serviceId || ownerId);
  const add =
    can('deal.create') && can('client.read') ? (
      <Button onClick={() => setCreateOpen(true)}>
        <Plus /> {t('deals.add')}
      </Button>
    ) : null;

  return (
    <>
      <PageHeader
        title={t('deals.title')}
        description={t('deals.subtitle')}
        actions={
          <div className="flex flex-wrap gap-2">
            <ExportMenu entity="deals" />
            {add}
          </div>
        }
      />
      <Card>
        <div className="grid gap-3 border-b p-4 md:grid-cols-[1fr_auto]">
          <div className="relative">
            <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
            <Input
              value={q}
              onChange={(e) => setQ(e.target.value)}
              placeholder={t('deals.search')}
              aria-label={t('common.search')}
              className="pl-9"
            />
          </div>
          <div className="grid grid-cols-2 gap-3 md:flex">
            <NativeSelect
              aria-label={t('crm.fields.status')}
              value={status}
              onChange={(e) => setStatus(e.target.value as DealStatus | '')}
              className="md:w-40"
            >
              <option value="">{t('leads.filters.allStatuses')}</option>
              {DEAL_STATUSES.map((s) => (
                <option key={s} value={s}>
                  {t(`crm.status.deal.${s}`)}
                </option>
              ))}
            </NativeSelect>
            <NativeSelect
              aria-label={t('crm.fields.stage')}
              value={stageCode}
              onChange={(e) => setStageCode(e.target.value)}
              className="md:w-48"
            >
              <option value="">
                {t('crm.fields.stage')}: {t('crm.common.all')}
              </option>
              {refs.data?.stages
                .filter((s) => s.entity === 'DEAL')
                .map((s) => (
                  <option key={s.id} value={s.code}>
                    {s.name}
                  </option>
                ))}
            </NativeSelect>
            <NativeSelect
              aria-label={t('crm.fields.service')}
              value={serviceId}
              onChange={(e) => setServiceId(e.target.value)}
              className="md:w-44"
            >
              <option value="">
                {t('crm.fields.service')}: {t('crm.common.all')}
              </option>
              {refs.data?.services.map((s) => (
                <option key={s.id} value={s.id}>
                  {s.name}
                </option>
              ))}
            </NativeSelect>
            {can('deal.read', 'TEAM') && can('employee.read') ? (
              <OwnerSelect
                aria-label={t('crm.fields.owner')}
                value={ownerId}
                onChange={(e) => setOwnerId(e.target.value)}
                emptyLabel={`${t('crm.fields.owner')}: ${t('crm.common.all')}`}
                className="md:w-48"
              />
            ) : null}
          </div>
        </div>
        {deals.isPending ? (
          <TableSkeleton />
        ) : deals.isError ? (
          <ErrorState error={deals.error} onRetry={() => deals.refetch()} />
        ) : deals.data.items.length === 0 ? (
          filtered ? (
            <EmptyState title={t('deals.emptyFiltered')} />
          ) : (
            <EmptyState title={t('deals.emptyTitle')} text={t('deals.emptyText')} action={add} />
          )
        ) : (
          <>
            <div className="hidden md:block">
              <Table>
                <THead>
                  <tr>
                    <TH>{t('crm.fields.title')}</TH>
                    <TH>{t('crm.fields.client')}</TH>
                    <TH>{t('crm.fields.stage')}</TH>
                    <TH className="text-right">{t('crm.fields.amount')}</TH>
                    <TH>{t('crm.fields.probability')}</TH>
                    <TH>{t('crm.fields.owner')}</TH>
                    <TH>{t('crm.fields.expectedCloseDate')}</TH>
                  </tr>
                </THead>
                <TBody>
                  {deals.data.items.map((d) => (
                    <TR key={d.id}>
                      <TD>
                        <Link href={`/sales/deals/${d.id}`} className="block">
                          <span className="font-medium hover:underline">{d.title}</span>
                          <span className="block text-xs text-muted-foreground">
                            {d.number}
                            {d.isRepeat ? ` · ${t('deals.repeat')}` : ''}
                          </span>
                        </Link>
                      </TD>
                      <TD>
                        <Link href={`/clients/${d.client.id}`} className="hover:underline">
                          {d.client.name}
                        </Link>
                      </TD>
                      <TD>
                        {d.status === 'OPEN' ? (
                          <StageBadge stage={d.stage} />
                        ) : (
                          <StatusBadge status={d.status} kind="deal" />
                        )}
                      </TD>
                      <TD className="whitespace-nowrap text-right font-medium">
                        {money(d.amount, d.currency)}
                      </TD>
                      <TD className="text-muted-foreground">{d.probability}%</TD>
                      <TD className="text-muted-foreground">{d.owner.name}</TD>
                      <TD className="whitespace-nowrap text-muted-foreground">
                        {date(d.expectedCloseDate)}
                      </TD>
                    </TR>
                  ))}
                </TBody>
              </Table>
            </div>
            <ul className="divide-y md:hidden">
              {deals.data.items.map((d) => (
                <li key={d.id}>
                  <Link
                    href={`/sales/deals/${d.id}`}
                    className="grid gap-1.5 p-4 active:bg-muted/50"
                  >
                    <p className="font-medium">{d.title}</p>
                    <p className="text-sm text-muted-foreground">
                      {d.client.name} · {d.owner.name}
                    </p>
                    <div className="flex flex-wrap items-center gap-2 text-sm">
                      {d.status === 'OPEN' ? (
                        <StageBadge stage={d.stage} />
                      ) : (
                        <StatusBadge status={d.status} kind="deal" />
                      )}
                      <span className="font-medium">{money(d.amount, d.currency)}</span>
                    </div>
                  </Link>
                </li>
              ))}
            </ul>
            <Pagination
              page={deals.data.page}
              pageSize={deals.data.pageSize}
              total={deals.data.total}
              onPage={setPage}
            />
          </>
        )}
      </Card>
      <DealFormDialog open={createOpen} onOpenChange={setCreateOpen} />
    </>
  );
}

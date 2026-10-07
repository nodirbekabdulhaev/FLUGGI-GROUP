'use client';

import { ExportMenu } from '@/features/analytics/export-menu';
import {
  LEAD_STATUSES,
  SCORE_LEVELS,
  type LeadStatus,
  type ScoreLevelCode,
} from '@fluggi/contracts';
import { Plus, Search } from 'lucide-react';
import { useTranslations } from 'next-intl';
import Link from 'next/link';
import { useEffect, useState } from 'react';
import { ScoreBadge, StageBadge, StatusBadge } from '@/components/crm/badges';
import { PageHeader } from '@/components/shared/page-header';
import { Pagination } from '@/components/shared/pagination';
import { EmptyState, ErrorState, TableSkeleton } from '@/components/shared/states';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input, NativeSelect } from '@/components/ui/input';
import { TBody, TD, TH, THead, TR, Table } from '@/components/ui/table';
import { useLeads, useReferences } from '@/features/crm/api';
import { OwnerSelect } from '@/features/crm/owner-select';
import { dateTime, money } from '@/lib/format';
import { useCan } from '@/lib/me-context';
import { LeadFormDialog } from './lead-form-dialog';

function useDebounced<T>(value: T, ms = 300) {
  const [v, setV] = useState(value);
  useEffect(() => {
    const id = setTimeout(() => setV(value), ms);
    return () => clearTimeout(id);
  }, [value, ms]);
  return v;
}

export function LeadsPage() {
  const t = useTranslations();
  const can = useCan();
  const refs = useReferences();
  const [q, setQ] = useState('');
  const [status, setStatus] = useState<LeadStatus | ''>('OPEN');
  const [stageCode, setStageCode] = useState('');
  const [sourceId, setSourceId] = useState('');
  const [serviceId, setServiceId] = useState('');
  const [ownerId, setOwnerId] = useState('');
  const [scoreLevel, setScoreLevel] = useState<ScoreLevelCode | ''>('');
  const [page, setPage] = useState(1);
  const [createOpen, setCreateOpen] = useState(false);
  const search = useDebounced(q);
  useEffect(
    () => setPage(1),
    [search, status, stageCode, sourceId, serviceId, ownerId, scoreLevel],
  );

  const leads = useLeads({
    q: search || undefined,
    status: status || undefined,
    stageCode: (stageCode || undefined) as never,
    sourceId: sourceId || undefined,
    serviceId: serviceId || undefined,
    ownerId: ownerId || undefined,
    scoreLevel: scoreLevel || undefined,
    page,
    pageSize: 25,
  });
  const filtered = Boolean(
    search || status !== 'OPEN' || stageCode || sourceId || serviceId || ownerId || scoreLevel,
  );
  const add = can('lead.create') ? (
    <Button onClick={() => setCreateOpen(true)}>
      <Plus /> {t('leads.add')}
    </Button>
  ) : null;

  return (
    <>
      <PageHeader
        title={t('leads.title')}
        description={t('leads.subtitle')}
        actions={
          <div className="flex flex-wrap gap-2">
            <ExportMenu entity="leads" />
            {add}
          </div>
        }
      />
      <Card>
        <div className="grid gap-3 border-b p-4">
          <div className="relative">
            <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
            <Input
              value={q}
              onChange={(e) => setQ(e.target.value)}
              placeholder={t('leads.search')}
              aria-label={t('common.search')}
              className="pl-9"
            />
          </div>
          <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
            <NativeSelect
              aria-label={t('crm.fields.status')}
              value={status}
              onChange={(e) => setStatus(e.target.value as LeadStatus | '')}
            >
              <option value="">{t('leads.filters.allStatuses')}</option>
              {LEAD_STATUSES.map((s) => (
                <option key={s} value={s}>
                  {t(`crm.status.lead.${s}`)}
                </option>
              ))}
            </NativeSelect>
            <NativeSelect
              aria-label={t('crm.fields.stage')}
              value={stageCode}
              onChange={(e) => setStageCode(e.target.value)}
            >
              <option value="">
                {t('crm.fields.stage')}: {t('crm.common.all')}
              </option>
              {refs.data?.stages
                .filter((s) => s.entity === 'LEAD')
                .map((s) => (
                  <option key={s.id} value={s.code}>
                    {s.name}
                  </option>
                ))}
            </NativeSelect>
            <NativeSelect
              aria-label={t('crm.fields.source')}
              value={sourceId}
              onChange={(e) => setSourceId(e.target.value)}
            >
              <option value="">
                {t('crm.fields.source')}: {t('crm.common.all')}
              </option>
              {refs.data?.sources.map((s) => (
                <option key={s.id} value={s.id}>
                  {s.name}
                </option>
              ))}
            </NativeSelect>
            <NativeSelect
              aria-label={t('crm.fields.service')}
              value={serviceId}
              onChange={(e) => setServiceId(e.target.value)}
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
            <NativeSelect
              aria-label={t('crm.fields.score')}
              value={scoreLevel}
              onChange={(e) => setScoreLevel(e.target.value as ScoreLevelCode | '')}
            >
              <option value="">
                {t('crm.fields.score')}: {t('crm.common.all')}
              </option>
              {SCORE_LEVELS.map((s) => (
                <option key={s} value={s}>
                  {t(`crm.score.${s}`)}
                </option>
              ))}
            </NativeSelect>
            {can('lead.read', 'TEAM') && can('employee.read') ? (
              <OwnerSelect
                filter
                aria-label={t('crm.fields.owner')}
                value={ownerId}
                onChange={(e) => setOwnerId(e.target.value)}
                emptyLabel={`${t('crm.fields.owner')}: ${t('crm.common.all')}`}
              />
            ) : null}
          </div>
        </div>

        {leads.isPending ? (
          <TableSkeleton />
        ) : leads.isError ? (
          <ErrorState error={leads.error} onRetry={() => leads.refetch()} />
        ) : leads.data.items.length === 0 ? (
          filtered ? (
            <EmptyState title={t('leads.emptyFiltered')} />
          ) : (
            <EmptyState title={t('leads.emptyTitle')} text={t('leads.emptyText')} action={add} />
          )
        ) : (
          <>
            <div className="hidden md:block">
              <Table>
                <THead>
                  <tr>
                    <TH>{t('crm.fields.title')}</TH>
                    <TH>{t('crm.fields.stage')}</TH>
                    <TH>{t('crm.fields.score')}</TH>
                    <TH>{t('crm.fields.budget')}</TH>
                    <TH>{t('crm.fields.source')}</TH>
                    <TH>{t('crm.fields.owner')}</TH>
                    <TH>{t('crm.fields.createdAt')}</TH>
                  </tr>
                </THead>
                <TBody>
                  {leads.data.items.map((l) => (
                    <TR key={l.id} className="cursor-pointer">
                      <TD>
                        <Link href={`/sales/leads/${l.id}`} className="block">
                          <span className="font-medium hover:underline">{l.title}</span>
                          <span className="block text-xs text-muted-foreground">
                            {l.number} · {l.contactName ?? ''} {l.phone ?? l.telegram ?? ''}
                          </span>
                        </Link>
                      </TD>
                      <TD>
                        {l.status === 'OPEN' ? (
                          <StageBadge stage={l.stage} />
                        ) : (
                          <StatusBadge status={l.status} kind="lead" />
                        )}
                      </TD>
                      <TD>
                        <ScoreBadge level={l.scoreLevel} score={l.score} />
                      </TD>
                      <TD className="whitespace-nowrap">{money(l.budget, l.currency)}</TD>
                      <TD className="text-muted-foreground">{l.source.name}</TD>
                      <TD className="text-muted-foreground">{l.owner.name}</TD>
                      <TD className="whitespace-nowrap text-muted-foreground">
                        {dateTime(l.createdAt)}
                      </TD>
                    </TR>
                  ))}
                </TBody>
              </Table>
            </div>
            <ul className="divide-y md:hidden">
              {leads.data.items.map((l) => (
                <li key={l.id}>
                  <Link
                    href={`/sales/leads/${l.id}`}
                    className="grid gap-1.5 p-4 active:bg-muted/50"
                  >
                    <div className="flex items-start justify-between gap-2">
                      <p className="font-medium">{l.title}</p>
                      <ScoreBadge level={l.scoreLevel} />
                    </div>
                    <p className="text-sm text-muted-foreground">
                      {l.contactName} · {l.phone ?? l.telegram}
                    </p>
                    <div className="flex flex-wrap items-center gap-2 text-sm">
                      {l.status === 'OPEN' ? (
                        <StageBadge stage={l.stage} />
                      ) : (
                        <StatusBadge status={l.status} kind="lead" />
                      )}
                      <span>{money(l.budget, l.currency)}</span>
                    </div>
                  </Link>
                </li>
              ))}
            </ul>
            <Pagination
              page={leads.data.page}
              pageSize={leads.data.pageSize}
              total={leads.data.total}
              onPage={setPage}
            />
          </>
        )}
      </Card>
      <LeadFormDialog open={createOpen} onOpenChange={setCreateOpen} />
    </>
  );
}

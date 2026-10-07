'use client';

import { ExportMenu } from '@/features/analytics/export-menu';
import { PROJECT_STATUSES, type ProjectStatus, type ProjectView } from '@fluggi/contracts';
import { FolderKanban } from 'lucide-react';
import { useTranslations } from 'next-intl';
import Link from 'next/link';
import { useState } from 'react';
import { PriorityText } from '@/components/crm/badges';
import { PageHeader } from '@/components/shared/page-header';
import { Pagination } from '@/components/shared/pagination';
import { EmptyState, ErrorState, TableSkeleton } from '@/components/shared/states';
import { Card } from '@/components/ui/card';
import { Input, NativeSelect } from '@/components/ui/input';
import { TBody, TD, TH, THead, TR, Table } from '@/components/ui/table';
import { date, money } from '@/lib/format';
import { useProjects } from './api';
import { OverdueBadge, ProjectStatusBadge, TaskProgress } from './status';

/** Списки проектов: все / в работе / просроченные / завершённые (ТЗ §4). */
export function ProjectsPage({ view }: { view: ProjectView }) {
  const t = useTranslations('projects');
  const ts = useTranslations('sales.projectStatus');
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState<ProjectStatus | ''>('');
  const [page, setPage] = useState(1);
  const list = useProjects({
    view,
    q: search.trim() || undefined,
    status: status || undefined,
    page,
    pageSize: 25,
  });
  const showMoney = list.data?.items.some((p) => p.price !== null) ?? false;

  return (
    <>
      <PageHeader
        title={t(`views.${view}`)}
        description={t(`viewsHint.${view}`)}
        actions={<ExportMenu entity="projects" />}
      />
      <Card>
        <div className="flex flex-col gap-3 border-b p-4 sm:flex-row">
          <Input
            type="search"
            placeholder={t('search')}
            aria-label={t('search')}
            className="sm:max-w-xs"
            value={search}
            onChange={(e) => (setSearch(e.target.value), setPage(1))}
          />
          {view === 'all' ? (
            <NativeSelect
              aria-label={t('col.status')}
              className="sm:w-56"
              value={status}
              onChange={(e) => (setStatus(e.target.value as ProjectStatus | ''), setPage(1))}
            >
              <option value="">Все статусы</option>
              {PROJECT_STATUSES.map((s) => (
                <option key={s} value={s}>
                  {ts(s)}
                </option>
              ))}
            </NativeSelect>
          ) : null}
        </div>
        {list.isPending ? (
          <TableSkeleton />
        ) : list.isError ? (
          <ErrorState error={list.error} onRetry={() => list.refetch()} />
        ) : list.data.items.length === 0 ? (
          <EmptyState icon={FolderKanban} title={t('empty')} text={t('emptyText')} />
        ) : (
          <>
            <div className="hidden md:block">
              <Table>
                <THead>
                  <tr>
                    <TH>{t('col.project')}</TH>
                    <TH>{t('col.client')}</TH>
                    <TH>{t('col.status')}</TH>
                    <TH>{t('col.deadline')}</TH>
                    <TH>{t('col.tasks')}</TH>
                    <TH>{t('col.rop')}</TH>
                    {showMoney ? <TH className="text-right">{t('col.price')}</TH> : null}
                  </tr>
                </THead>
                <TBody>
                  {list.data.items.map((p) => (
                    <TR key={p.id}>
                      <TD>
                        <Link href={`/projects/${p.id}`} className="font-medium hover:underline">
                          {p.number} · {p.name}
                        </Link>
                        <span className="block">
                          <PriorityText priority={p.priority} />
                        </span>
                      </TD>
                      <TD>{p.client.name}</TD>
                      <TD>
                        <ProjectStatusBadge status={p.status} />
                      </TD>
                      <TD className="whitespace-nowrap">
                        {date(p.deadline)}
                        <OverdueBadge days={p.overdueDays} className="ml-2" />
                      </TD>
                      <TD>
                        <TaskProgress
                          done={p.tasks.done}
                          total={p.tasks.total}
                          overdue={p.tasks.overdue}
                        />
                      </TD>
                      <TD className="text-muted-foreground">{p.rop.name}</TD>
                      {showMoney ? (
                        <TD className="whitespace-nowrap text-right font-medium">
                          {money(p.price, p.currency)}
                        </TD>
                      ) : null}
                    </TR>
                  ))}
                </TBody>
              </Table>
            </div>
            <ul className="divide-y md:hidden">
              {list.data.items.map((p) => (
                <li key={p.id}>
                  <Link href={`/projects/${p.id}`} className="grid gap-2 p-4 active:bg-muted/50">
                    <div className="flex items-start justify-between gap-3">
                      <div className="min-w-0">
                        <p className="font-medium">
                          {p.number} · {p.name}
                        </p>
                        <p className="text-sm text-muted-foreground">
                          {p.client.name} · {date(p.deadline)}
                        </p>
                      </div>
                      <ProjectStatusBadge status={p.status} />
                    </div>
                    <OverdueBadge days={p.overdueDays} className="w-fit" />
                    <TaskProgress
                      done={p.tasks.done}
                      total={p.tasks.total}
                      overdue={p.tasks.overdue}
                    />
                  </Link>
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
    </>
  );
}

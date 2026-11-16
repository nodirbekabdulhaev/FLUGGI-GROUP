'use client';

import { TrendingUp } from 'lucide-react';
import { useTranslations } from 'next-intl';
import Link from 'next/link';
import { useState } from 'react';
import { PageHeader } from '@/components/shared/page-header';
import { Pagination } from '@/components/shared/pagination';
import { EmptyState, ErrorState, TableSkeleton } from '@/components/shared/states';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { TBody, TD, TH, THead, TR, Table } from '@/components/ui/table';
import { ProjectStatusBadge } from '@/features/projects/status';
import { money } from '@/lib/format';
import { cn } from '@/lib/utils';
import { useProjectsProfit } from './api';

/** Прибыль по проектам (ТЗ §25): выручка − расходы, маржа. */
export function ProfitPage() {
  const t = useTranslations('finance');
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const list = useProjectsProfit({ q: search.trim() || undefined, page, pageSize: 25 });
  return (
    <>
      <PageHeader title={t('profit.title')} description={t('profit.subtitle')} />
      <Card>
        <div className="border-b p-4">
          <Input
            type="search"
            placeholder="Поиск по проекту или клиенту"
            aria-label="Поиск по проекту или клиенту"
            className="sm:max-w-xs"
            value={search}
            onChange={(e) => (setSearch(e.target.value), setPage(1))}
          />
        </div>
        {list.isPending ? (
          <TableSkeleton />
        ) : list.isError ? (
          <ErrorState error={list.error} onRetry={() => list.refetch()} />
        ) : list.data.items.length === 0 ? (
          <EmptyState icon={TrendingUp} title={t('profit.empty')} />
        ) : (
          <>
            <div className="overflow-x-auto">
              <Table>
                <THead>
                  <tr>
                    <TH>Проект</TH>
                    <TH>Статус</TH>
                    <TH className="text-right">{t('project.revenue')}</TH>
                    <TH className="text-right">{t('project.collected')}</TH>
                    <TH className="text-right">{t('project.expenses')}</TH>
                    <TH className="text-right">{t('project.grossProfit')}</TH>
                    <TH className="text-right">{t('project.margin')}</TH>
                  </tr>
                </THead>
                <TBody>
                  {list.data.items.map((r) => (
                    <TR key={r.project.id}>
                      <TD>
                        <Link
                          href={`/projects/${r.project.id}?tab=finance`}
                          className="font-medium hover:underline"
                        >
                          {r.project.number} · {r.project.name}
                        </Link>
                        <span className="block text-xs text-muted-foreground">{r.client.name}</span>
                      </TD>
                      <TD>
                        <ProjectStatusBadge status={r.status} />
                      </TD>
                      <TD className="whitespace-nowrap text-right tabular-nums">
                        {money(r.revenueUzs)}
                      </TD>
                      <TD className="whitespace-nowrap text-right tabular-nums">
                        {money(r.collectedUzs)}
                      </TD>
                      <TD className="whitespace-nowrap text-right tabular-nums">
                        {money(r.expensesUzs)}
                      </TD>
                      <TD
                        className={cn(
                          'whitespace-nowrap text-right font-semibold tabular-nums',
                          Number(r.grossProfitUzs) < 0 && 'text-danger',
                        )}
                      >
                        {money(r.grossProfitUzs)}
                      </TD>
                      <TD className="text-right tabular-nums">
                        {r.marginPct === null ? '—' : `${r.marginPct}%`}
                      </TD>
                    </TR>
                  ))}
                </TBody>
              </Table>
            </div>
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

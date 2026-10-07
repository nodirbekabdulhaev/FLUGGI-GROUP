'use client';

import { TASK_VIEWS, type TaskView } from '@fluggi/contracts';
import { CheckSquare } from 'lucide-react';
import { useTranslations } from 'next-intl';
import Link from 'next/link';
import { useState } from 'react';
import { PriorityText } from '@/components/crm/badges';
import { PageHeader } from '@/components/shared/page-header';
import { Pagination } from '@/components/shared/pagination';
import { EmptyState, ErrorState, TableSkeleton } from '@/components/shared/states';
import { Tabs } from '@/components/shared/tabs';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { TBody, TD, TH, THead, TR, Table } from '@/components/ui/table';
import { dateTime } from '@/lib/format';
import { useCan } from '@/lib/me-context';
import { useTasks } from './api';
import { OverdueBadge, TaskStatusBadge } from './status';

/** Задачи (ТЗ §22, §60): мои задачи, сегодня, просрочено, в работе, завершено. */
export function TasksPage() {
  const t = useTranslations('tasks');
  const can = useCan();
  // Руководитель видит задачи команды; исполнитель — только свои (это ограничивает и backend).
  const lead = can('task.create', 'TEAM');
  const [view, setView] = useState<TaskView>('all');
  const [mine, setMine] = useState(!lead);
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const list = useTasks({
    view,
    mine: mine ? 'true' : undefined,
    q: search.trim() || undefined,
    page,
    pageSize: 25,
  });
  const href = (task: { id: string; project: { id: string } }) =>
    `/projects/${task.project.id}?task=${task.id}`;

  return (
    <>
      <PageHeader title={t('title')} description={t('subtitle')} />
      <Tabs
        items={TASK_VIEWS.map((v) => ({ key: v, label: t(`views.${v}`) }))}
        value={view}
        onChange={(v) => (setView(v as TaskView), setPage(1))}
      />
      <Card>
        <div className="flex flex-col gap-3 border-b p-4 sm:flex-row sm:items-center">
          <Input
            type="search"
            placeholder="Поиск по названию"
            aria-label="Поиск по названию"
            className="sm:max-w-xs"
            value={search}
            onChange={(e) => (setSearch(e.target.value), setPage(1))}
          />
          {lead ? (
            <label className="flex items-center gap-2 text-sm">
              <input
                type="checkbox"
                checked={mine}
                onChange={(e) => (setMine(e.target.checked), setPage(1))}
              />
              {t('mine')}
            </label>
          ) : null}
        </div>
        {list.isPending ? (
          <TableSkeleton />
        ) : list.isError ? (
          <ErrorState error={list.error} onRetry={() => list.refetch()} />
        ) : list.data.items.length === 0 ? (
          <EmptyState icon={CheckSquare} title={t('empty')} text={t('emptyText')} />
        ) : (
          <>
            <div className="hidden md:block">
              <Table>
                <THead>
                  <tr>
                    <TH>{t('fields.title')}</TH>
                    <TH>{t('fields.project')}</TH>
                    <TH>{t('fields.status')}</TH>
                    <TH>{t('fields.deadline')}</TH>
                    <TH>{t('fields.assignee')}</TH>
                    <TH>{t('fields.priority')}</TH>
                  </tr>
                </THead>
                <TBody>
                  {list.data.items.map((task) => (
                    <TR key={task.id}>
                      <TD>
                        <Link href={href(task)} className="font-medium hover:underline">
                          {task.title}
                        </Link>
                        <span className="block text-xs text-muted-foreground">{task.number}</span>
                      </TD>
                      <TD>
                        <Link
                          href={`/projects/${task.project.id}`}
                          className="text-muted-foreground hover:underline"
                        >
                          {task.project.number} · {task.project.name}
                        </Link>
                      </TD>
                      <TD>
                        <TaskStatusBadge status={task.status} />
                      </TD>
                      <TD className="whitespace-nowrap">
                        {dateTime(task.deadline)}
                        <OverdueBadge days={task.overdueDays} className="ml-2" />
                      </TD>
                      <TD className="text-muted-foreground">{task.assignee.name}</TD>
                      <TD>
                        <PriorityText priority={task.priority} />
                      </TD>
                    </TR>
                  ))}
                </TBody>
              </Table>
            </div>
            <ul className="divide-y md:hidden">
              {list.data.items.map((task) => (
                <li key={task.id}>
                  <Link href={href(task)} className="grid gap-1.5 p-4 active:bg-muted/50">
                    <div className="flex items-start justify-between gap-3">
                      <p className="font-medium">{task.title}</p>
                      <TaskStatusBadge status={task.status} />
                    </div>
                    <p className="text-sm text-muted-foreground">
                      {task.project.name} · {dateTime(task.deadline)}
                    </p>
                    <OverdueBadge days={task.overdueDays} className="w-fit" />
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

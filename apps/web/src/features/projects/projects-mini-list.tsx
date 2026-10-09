'use client';

import type { ProjectListQuery } from '@fluggi/contracts';
import { FolderKanban } from 'lucide-react';
import { useTranslations } from 'next-intl';
import Link from 'next/link';
import { EmptyState, ErrorState, TableSkeleton } from '@/components/shared/states';
import { date } from '@/lib/format';
import { useProjects } from './api';
import { OverdueBadge, ProjectStatusBadge, TaskProgress } from './status';

/** Проекты клиента или сделки — для вкладок в карточках CRM. */
export function ProjectsMiniList({ query }: { query: ProjectListQuery }) {
  const t = useTranslations('projects');
  const list = useProjects({ pageSize: 50, ...query });
  if (list.isPending) return <TableSkeleton rows={2} cols={2} />;
  if (list.isError) return <ErrorState error={list.error} onRetry={() => list.refetch()} />;
  if (list.data.items.length === 0)
    return <EmptyState icon={FolderKanban} title={t('empty')} text={t('emptyText')} />;
  return (
    <ul className="divide-y">
      {list.data.items.map((p) => (
        <li key={p.id}>
          <Link
            href={`/projects/${p.id}`}
            className="flex flex-wrap items-center gap-3 px-5 py-3 hover:bg-muted/40"
          >
            <div className="min-w-0 flex-1">
              <p className="font-medium">
                {p.number} · {p.name}
              </p>
              <p className="text-xs text-muted-foreground">
                {p.rop.name} · {t('fields.deadline')}: {date(p.deadline)}
              </p>
            </div>
            <OverdueBadge days={p.overdueDays} />
            <ProjectStatusBadge status={p.status} />
            <TaskProgress done={p.tasks.done} total={p.tasks.total} overdue={p.tasks.overdue} />
          </Link>
        </li>
      ))}
    </ul>
  );
}

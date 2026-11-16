'use client';

import type { TaskStatus } from '@fluggi/contracts';
import { AlertTriangle } from 'lucide-react';
import { useTranslations } from 'next-intl';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

type Tone = 'neutral' | 'accent' | 'success' | 'warning' | 'danger';

const TASK_TONES: Record<TaskStatus, Tone> = {
  TODO: 'neutral',
  IN_PROGRESS: 'accent',
  REVIEW: 'warning',
  DONE: 'success',
  BLOCKED: 'danger',
  CANCELLED: 'neutral',
};

const PROJECT_TONES: Record<string, Tone> = {
  NEW: 'accent',
  PLANNING: 'accent',
  IN_PROGRESS: 'accent',
  REVIEW: 'warning',
  WAITING_CLIENT: 'warning',
  PAUSED: 'neutral',
  COMPLETED: 'success',
  CANCELLED: 'danger',
};

export function TaskStatusBadge({ status }: { status: TaskStatus }) {
  const t = useTranslations('tasks.status');
  return <Badge tone={TASK_TONES[status]}>{t(status)}</Badge>;
}

export function ProjectStatusBadge({ status }: { status: string }) {
  const t = useTranslations('sales.projectStatus');
  return <Badge tone={PROJECT_TONES[status] ?? 'neutral'}>{t(status)}</Badge>;
}

/** «Просрочено N дней» (ТЗ §24). */
export function OverdueBadge({ days, className }: { days: number; className?: string }) {
  const t = useTranslations('projects');
  if (days <= 0) return null;
  return (
    <Badge tone="danger" className={className}>
      <AlertTriangle className="size-3" /> {t('overdue', { count: days })}
    </Badge>
  );
}

/** Полоса выполнения задач проекта. */
export function TaskProgress({
  done,
  total,
  overdue,
  className,
}: {
  done: number;
  total: number;
  overdue?: number;
  className?: string;
}) {
  const t = useTranslations('projects');
  const pct = total > 0 ? Math.round((done / total) * 100) : 0;
  return (
    <div className={cn('grid min-w-28 gap-1', className)}>
      <div
        className="h-1.5 overflow-hidden rounded-full bg-muted"
        role="progressbar"
        aria-valuenow={pct}
        aria-valuemin={0}
        aria-valuemax={100}
      >
        <div className="h-full rounded-full bg-success" style={{ width: `${pct}%` }} />
      </div>
      <p className="text-xs text-muted-foreground">
        {t('progress', { done, total })}
        {overdue ? (
          <span className="text-danger"> · {t('overdueTasks', { count: overdue })}</span>
        ) : null}
      </p>
    </div>
  );
}

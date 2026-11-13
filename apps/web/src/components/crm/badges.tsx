'use client';

import type { ScoreLevelCode } from '@fluggi/contracts';
import { Flame } from 'lucide-react';
import { useTranslations } from 'next-intl';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

export function StageBadge({ stage }: { stage: { name: string; color: string } }) {
  return (
    <span className="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full border px-2 py-0.5 text-xs font-medium">
      <span className="size-2 rounded-full" style={{ backgroundColor: stage.color }} aria-hidden />
      {stage.name}
    </span>
  );
}

const SCORE_TONE = { LOW: 'neutral', MEDIUM: 'accent', HIGH: 'warning', HOT: 'danger' } as const;

export function ScoreBadge({ level, score }: { level: ScoreLevelCode; score?: number }) {
  const t = useTranslations('crm.score');
  return (
    <Badge tone={SCORE_TONE[level]} title={score !== undefined ? `${score}/100` : undefined}>
      {level === 'HOT' ? <Flame className="size-3" /> : null}
      {t(level)}
      {score !== undefined ? <span className="opacity-70">· {score}</span> : null}
    </Badge>
  );
}

const STATUS_TONE: Record<string, 'neutral' | 'success' | 'danger' | 'warning' | 'accent'> = {
  OPEN: 'accent',
  CONVERTED: 'success',
  WON: 'success',
  LOST: 'danger',
  REJECTED: 'danger',
  PAUSED: 'warning',
  NO_RESPONSE: 'warning',
};

export function StatusBadge({ status, kind }: { status: string; kind: 'lead' | 'deal' }) {
  const t = useTranslations('crm.status');
  return <Badge tone={STATUS_TONE[status] ?? 'neutral'}>{t(`${kind}.${status}`)}</Badge>;
}

const PRIORITY_CLASS: Record<string, string> = {
  LOW: 'text-muted-foreground',
  MEDIUM: 'text-foreground',
  HIGH: 'text-warning',
  URGENT: 'text-danger font-medium',
};

export function PriorityText({ priority }: { priority: string }) {
  const t = useTranslations('crm.priority');
  return <span className={cn('text-sm', PRIORITY_CLASS[priority])}>{t(priority)}</span>;
}

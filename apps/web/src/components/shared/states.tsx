'use client';

import { AlertTriangle, Inbox, Lock } from 'lucide-react';
import { useTranslations } from 'next-intl';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { errorMessage } from '@/lib/api-client';

export function EmptyState({
  title,
  text,
  action,
  icon: Icon = Inbox,
}: {
  title: string;
  text?: string;
  action?: React.ReactNode;
  icon?: React.ComponentType<{ className?: string }>;
}) {
  return (
    <div className="flex flex-col items-center justify-center gap-3 px-6 py-14 text-center">
      <div className="flex size-11 items-center justify-center rounded-full bg-muted">
        <Icon className="size-5 text-muted-foreground" />
      </div>
      <div className="max-w-sm">
        <p className="font-medium">{title}</p>
        {text ? <p className="mt-1 text-sm text-muted-foreground">{text}</p> : null}
      </div>
      {action}
    </div>
  );
}

export function ErrorState({ error, onRetry }: { error: unknown; onRetry?: () => void }) {
  const t = useTranslations('common');
  return (
    <div className="flex flex-col items-center gap-3 px-6 py-12 text-center" role="alert">
      <AlertTriangle className="size-6 text-danger" />
      <div>
        <p className="font-medium">{t('errorTitle')}</p>
        <p className="mt-1 text-sm text-muted-foreground">{errorMessage(error)}</p>
      </div>
      {onRetry ? (
        <Button variant="outline" size="sm" onClick={onRetry}>
          {t('retry')}
        </Button>
      ) : null}
    </div>
  );
}

export function Forbidden() {
  const t = useTranslations('common');
  return <EmptyState icon={Lock} title={t('forbiddenTitle')} text={t('forbiddenText')} />;
}

export function TableSkeleton({ rows = 6, cols = 4 }: { rows?: number; cols?: number }) {
  return (
    <div className="divide-y" aria-busy="true" aria-live="polite">
      {Array.from({ length: rows }, (_, r) => (
        <div key={r} className="flex items-center gap-4 px-4 py-3.5">
          <Skeleton className="size-8 rounded-full" />
          {Array.from({ length: cols }, (_, c) => (
            <Skeleton key={c} className={c === 0 ? 'h-4 w-40' : 'hidden h-4 w-24 sm:block'} />
          ))}
        </div>
      ))}
    </div>
  );
}

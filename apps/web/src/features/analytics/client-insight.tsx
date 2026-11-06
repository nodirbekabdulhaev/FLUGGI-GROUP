'use client';

import { useFormatter, useTranslations } from 'next-intl';
import { Card, CardContent } from '@/components/ui/card';
import { moneyShort } from '@/lib/format';
import { HealthBadge } from './analytics-page';
import { useClientInsight } from './api';

/** LTV и здоровье клиента в карточке клиента (ТЗ §43). */
export function ClientInsight({ id }: { id: string }) {
  const t = useTranslations('analytics');
  const format = useFormatter();
  const q = useClientInsight(id);
  if (!q.data) return null;
  const c = q.data;
  const items: [string, React.ReactNode][] = [
    ['LTV', moneyShort(c.ltv)],
    [t('paidDeals'), c.paidDeals],
    [t('avgCheck'), c.paidDeals ? moneyShort(c.avgCheck) : '—'],
    [t('lastContact'), c.lastContactAt ? format.relativeTime(new Date(c.lastContactAt)) : '—'],
  ];
  return (
    <Card className="mb-6" data-testid="client-insight">
      <CardContent className="grid gap-4 pt-5">
        <div className="flex flex-wrap items-center gap-2">
          <span className="text-sm font-medium">{t('health.title')}:</span>
          <HealthBadge level={c.health.level} score={c.health.score} />
          {c.health.reasons.length ? (
            <span className="text-xs text-muted-foreground">{c.health.reasons.join(' · ')}</span>
          ) : null}
        </div>
        <dl className="grid grid-cols-2 gap-4 sm:grid-cols-4">
          {items.map(([k, v]) => (
            <div key={k}>
              <dt className="text-xs text-muted-foreground">{k}</dt>
              <dd className="text-lg font-semibold tabular-nums">{v}</dd>
            </div>
          ))}
        </dl>
      </CardContent>
    </Card>
  );
}

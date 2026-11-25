'use client';

import type { CategoryAmountDto } from '@fluggi/contracts';
import { useTranslations } from 'next-intl';
import { PeriodSelect, usePeriod } from '@/components/layout/period-select';
import { PageHeader } from '@/components/shared/page-header';
import { ErrorState } from '@/components/shared/states';
import { Card } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { money } from '@/lib/format';
import { useCan } from '@/lib/me-context';
import { cn } from '@/lib/utils';
import { AccountantPackageButton } from './accountant-package';
import { useFinanceSummary } from './api';

function Tile({
  label,
  value,
  hint,
  tone,
}: {
  label: string;
  value: string;
  hint?: string;
  tone?: 'danger' | 'success';
}) {
  return (
    <Card className="grid content-start gap-1 p-4">
      <p className="text-sm text-muted-foreground">{label}</p>
      <p
        className={cn(
          'text-2xl font-semibold tracking-tight tabular-nums',
          tone === 'danger' && 'text-danger',
          tone === 'success' && 'text-success',
        )}
      >
        {value}
      </p>
      {hint ? <p className="text-xs text-muted-foreground">{hint}</p> : null}
    </Card>
  );
}

/** Расходы по категориям: одна величина — одна шкала и один цвет, подписи значений рядом. */
export function CategoryBars({ items }: { items: CategoryAmountDto[] }) {
  const t = useTranslations('finance');
  if (items.length === 0) return <p className="text-sm text-muted-foreground">{t('noExpenses')}</p>;
  const max = Math.max(...items.map((i) => Number(i.amountUzs)), 1);
  return (
    <ul className="grid gap-3" aria-label={t('byCategory')}>
      {items.map((i) => {
        const pct = (Number(i.amountUzs) / max) * 100;
        return (
          <li key={i.category} className="grid gap-1" title={`${i.name}: ${money(i.amountUzs)}`}>
            <div className="flex items-baseline justify-between gap-3 text-sm">
              <span>{i.name}</span>
              <span className="tabular-nums text-muted-foreground">{money(i.amountUzs)}</span>
            </div>
            <div className="h-2 rounded-full bg-muted">
              <div
                className="h-full rounded-full bg-accent"
                style={{ width: `${Math.max(pct, 1)}%` }}
              />
            </div>
          </li>
        );
      })}
    </ul>
  );
}

/** Финансовый дашборд (ТЗ §27). Период — общий фильтр (?period=…). */
export function FinanceDashboardPage() {
  const t = useTranslations('finance');
  const can = useCan();
  const { preset, from, to } = usePeriod();
  const s = useFinanceSummary({ period: preset, from, to });
  // У CEO период уже в шапке; остальным показываем его здесь.
  const showPeriod = !can('dashboard.ceo');

  return (
    <>
      <PageHeader
        title={t('dashboard.title')}
        description={t('dashboard.subtitle')}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <AccountantPackageButton />
            {showPeriod ? <PeriodSelect /> : null}
          </div>
        }
      />
      {s.isPending ? (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          {Array.from({ length: 8 }, (_, i) => (
            <Skeleton key={i} className="h-28" />
          ))}
        </div>
      ) : s.isError ? (
        <Card>
          <ErrorState error={s.error} onRetry={() => s.refetch()} />
        </Card>
      ) : (
        <div className="grid gap-6">
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <Tile
              label={t('kpi.revenue')}
              value={money(s.data.revenueUzs)}
              hint={t('kpi.revenueHint')}
            />
            <Tile
              label={t('kpi.collected')}
              value={money(s.data.collectedUzs)}
              hint={t('kpi.collectedHint')}
            />
            <Tile
              label={t('kpi.receivables')}
              value={money(s.data.receivablesUzs)}
              hint={t('kpi.receivablesHint')}
            />
            <Tile
              label={t('kpi.expenses')}
              value={money(
                Number(s.data.projectExpensesUzs) + Number(s.data.companyExpensesUzs ?? 0),
              )}
              hint={
                s.data.companyExpensesUzs !== null
                  ? t('kpi.expensesHint', {
                      project: money(s.data.projectExpensesUzs),
                      company: money(s.data.companyExpensesUzs),
                    })
                  : t('kpi.projectExpensesHint')
              }
            />
            <Tile
              label={t('kpi.grossProfit')}
              value={money(s.data.grossProfitUzs)}
              hint={t('kpi.grossProfitHint')}
              tone={Number(s.data.grossProfitUzs) < 0 ? 'danger' : undefined}
            />
            <Tile
              label={t('kpi.margin')}
              value={s.data.marginPct === null ? '—' : `${s.data.marginPct}%`}
            />
            <Tile label={t('kpi.refunds')} value={money(s.data.refundsUzs)} />
            <Tile label={t('kpi.commissions')} value={money(s.data.commissionsUzs)} />
            {s.data.otherIncomeUzs !== null ? (
              <Tile
                label={t('kpi.otherIncome')}
                value={money(s.data.otherIncomeUzs)}
                hint={t('kpi.otherIncomeHint')}
              />
            ) : null}
            {s.data.operatingProfitUzs !== null ? (
              <Tile
                label={t('kpi.operatingProfit')}
                value={money(s.data.operatingProfitUzs)}
                hint={t('kpi.operatingProfitHint')}
                tone={Number(s.data.operatingProfitUzs) < 0 ? 'danger' : undefined}
              />
            ) : null}
          </div>
          <Card className="grid gap-4 p-5">
            <h2 className="font-semibold">{t('byCategory')}</h2>
            <CategoryBars items={s.data.expensesByCategory} />
          </Card>
          {s.data.operatingProfitUzs === null ? (
            <p className="text-xs text-muted-foreground">{t('dashboard.companyHint')}</p>
          ) : null}
        </div>
      )}
    </>
  );
}

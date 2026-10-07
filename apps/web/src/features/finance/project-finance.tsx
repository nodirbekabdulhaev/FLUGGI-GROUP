'use client';

import type { ExpenseDto, ProjectDetailDto } from '@fluggi/contracts';
import { Plus } from 'lucide-react';
import { useTranslations } from 'next-intl';
import { useState } from 'react';
import { ErrorState, TableSkeleton } from '@/components/shared/states';
import { Button } from '@/components/ui/button';
import { money } from '@/lib/format';
import { useCan } from '@/lib/me-context';
import { cn } from '@/lib/utils';
import { useExpenses, useProjectFinance } from './api';
import { CategoryBars } from './dashboard-page';
import { ExpenseDialog } from './expense-dialog';
import { ExpensesTable } from './expenses-page';

/** Финансовая карточка проекта (ТЗ §25): стоимость, расходы, прибыль, маржа. */
export function ProjectFinancePanel({ project }: { project: ProjectDetailDto }) {
  const t = useTranslations('finance');
  const can = useCan();
  const f = useProjectFinance(project.id);
  const expenses = useExpenses({ projectId: project.id, pageSize: 100 });
  const [editing, setEditing] = useState<ExpenseDto | null | 'new'>(null);
  if (f.isPending) return <TableSkeleton rows={4} cols={3} />;
  if (f.isError) return <ErrorState error={f.error} onRetry={() => f.refetch()} />;
  const d = f.data;
  const rows: [string, string, string?][] = [
    [t('project.revenue'), money(d.revenueUzs)],
    [t('project.collected'), money(d.collectedUzs)],
    [t('project.receivable'), money(d.receivableUzs)],
    [t('project.expenses'), money(d.expensesUzs)],
    [
      t('project.grossProfit'),
      money(d.grossProfitUzs),
      Number(d.grossProfitUzs) < 0 ? 'text-danger' : 'text-success',
    ],
    [t('project.margin'), d.marginPct === null ? '—' : `${d.marginPct}%`],
    [t('project.commissions'), money(d.commissionsUzs)],
  ];
  return (
    <div className="grid gap-6">
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        {rows.map(([label, value, tone]) => (
          <div key={label} className="rounded-lg border p-3">
            <p className="text-xs text-muted-foreground">{label}</p>
            <p className={cn('text-lg font-semibold tabular-nums', tone)}>{value}</p>
          </div>
        ))}
      </div>
      <div className="grid gap-6 lg:grid-cols-[1fr_2fr]">
        <section className="grid content-start gap-3">
          <h3 className="text-sm font-semibold">{t('byCategory')}</h3>
          <CategoryBars items={d.byCategory} />
        </section>
        <section className="grid content-start gap-3">
          <div className="flex items-center justify-between gap-3">
            <h3 className="text-sm font-semibold">{t('project.expensesList')}</h3>
            {can('expense.create') ? (
              <Button size="sm" onClick={() => setEditing('new')}>
                <Plus /> {t('expenses.new')}
              </Button>
            ) : null}
          </div>
          {expenses.data && expenses.data.items.length > 0 ? (
            <ExpensesTable items={expenses.data.items} onEdit={setEditing} showProject={false} />
          ) : (
            <p className="text-sm text-muted-foreground">{t('noExpenses')}</p>
          )}
        </section>
      </div>
      <ExpenseDialog
        open={editing !== null}
        onOpenChange={(o) => (!o ? setEditing(null) : undefined)}
        expense={editing === 'new' ? null : editing}
        projectId={project.id}
      />
    </div>
  );
}

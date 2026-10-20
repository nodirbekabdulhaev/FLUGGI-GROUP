'use client';

import { ExportMenu } from '@/features/analytics/export-menu';
import { type ExpenseCategory, type ExpenseDto, type ExpenseScope } from '@fluggi/contracts';
import { Pencil, Plus, Receipt, Trash2 } from 'lucide-react';
import { useTranslations } from 'next-intl';
import Link from 'next/link';
import { useState } from 'react';
import { toast } from 'sonner';
import { PageHeader } from '@/components/shared/page-header';
import { Pagination } from '@/components/shared/pagination';
import { EmptyState, ErrorState, TableSkeleton } from '@/components/shared/states';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { NativeSelect } from '@/components/ui/input';
import { TBody, TD, TH, THead, TR, Table } from '@/components/ui/table';
import { useCrmMutation } from '@/features/crm/api';
import { api, errorMessage } from '@/lib/api-client';
import { date, money } from '@/lib/format';
import { useCan } from '@/lib/me-context';
import { useExpenses, useFinanceCategories } from './api';
import { ExpenseDialog } from './expense-dialog';

/** Таблица расходов — на странице «Расходы» и во вкладке «Финансы» проекта. */
export function ExpensesTable({
  items,
  onEdit,
  showProject = true,
}: {
  items: ExpenseDto[];
  onEdit: (e: ExpenseDto) => void;
  showProject?: boolean;
}) {
  const t = useTranslations('finance');
  const remove = useCrmMutation((id: string) => api(`/expenses/${id}`, { method: 'DELETE' }));
  return (
    <div className="overflow-x-auto">
      <Table>
        <THead>
          <tr>
            <TH>{t('expenses.date')}</TH>
            {showProject ? <TH>{t('expenses.project')}</TH> : null}
            <TH>{t('expenses.category')}</TH>
            <TH>{t('expenses.payee')}</TH>
            <TH className="text-right">{t('expenses.amount')}</TH>
            <TH />
          </tr>
        </THead>
        <TBody>
          {items.map((e) => (
            <TR key={e.id}>
              <TD className="whitespace-nowrap">
                {date(e.expenseDate)}
                <span className="block text-xs text-muted-foreground">{e.number}</span>
              </TD>
              {showProject ? (
                <TD>
                  {e.project ? (
                    <Link href={`/projects/${e.project.id}`} className="hover:underline">
                      {e.project.number} · {e.project.name}
                    </Link>
                  ) : (
                    <span className="text-muted-foreground">{t('scope.COMPANY')}</span>
                  )}
                </TD>
              ) : null}
              <TD>
                {e.categoryName}
                {e.description ? (
                  <span className="block max-w-72 truncate text-xs text-muted-foreground">
                    {e.description}
                  </span>
                ) : null}
              </TD>
              <TD className="text-muted-foreground">{e.payee?.name ?? '—'}</TD>
              <TD className="whitespace-nowrap text-right font-medium tabular-nums">
                {money(e.amount, e.currency)}
                {e.currency !== 'UZS' ? (
                  <span className="block text-xs text-muted-foreground">
                    {money(e.amountUzs, 'UZS')}
                  </span>
                ) : null}
              </TD>
              <TD className="whitespace-nowrap text-right">
                {e.canEdit ? (
                  <>
                    <Button
                      size="icon-sm"
                      variant="ghost"
                      aria-label={t('expenses.edit')}
                      onClick={() => onEdit(e)}
                    >
                      <Pencil />
                    </Button>
                    <Button
                      size="icon-sm"
                      variant="ghost"
                      aria-label="Удалить"
                      onClick={async () => {
                        if (!window.confirm(t('expenses.deleteConfirm', { number: e.number })))
                          return;
                        try {
                          await remove.mutateAsync(e.id);
                          toast.success(t('expenses.deleted'));
                        } catch (err) {
                          toast.error(errorMessage(err));
                        }
                      }}
                    >
                      <Trash2 />
                    </Button>
                  </>
                ) : null}
              </TD>
            </TR>
          ))}
        </TBody>
      </Table>
    </div>
  );
}

/** Расходы (ТЗ §26). */
export function ExpensesPage() {
  const t = useTranslations('finance');
  const can = useCan();
  const [scope, setScope] = useState<ExpenseScope | ''>('');
  const [category, setCategory] = useState<ExpenseCategory | ''>('');
  const categories = useFinanceCategories('EXPENSE');
  const [page, setPage] = useState(1);
  const [editing, setEditing] = useState<ExpenseDto | null | 'new'>(null);
  const list = useExpenses({
    scope: scope || undefined,
    category: category || undefined,
    page,
    pageSize: 25,
  });
  return (
    <>
      <PageHeader
        title={t('expenses.title')}
        description={t('expenses.subtitle')}
        actions={
          <div className="flex flex-wrap gap-2">
            <ExportMenu entity="expenses" />
            {can('expense.create') ? (
              <Button onClick={() => setEditing('new')}>
                <Plus /> {t('expenses.new')}
              </Button>
            ) : null}
          </div>
        }
      />
      <Card>
        <div className="flex flex-col gap-3 border-b p-4 sm:flex-row sm:items-end sm:justify-between">
          <div className="flex flex-col gap-3 sm:flex-row">
            {can('finance.company.read', 'ALL') ? (
              <NativeSelect
                aria-label={t('expenses.scope')}
                className="sm:w-48"
                value={scope}
                onChange={(e) => (setScope(e.target.value as ExpenseScope | ''), setPage(1))}
              >
                <option value="">{t('expenses.allScopes')}</option>
                <option value="PROJECT">{t('scope.PROJECT')}</option>
                <option value="COMPANY">{t('scope.COMPANY')}</option>
              </NativeSelect>
            ) : null}
            <NativeSelect
              aria-label={t('expenses.category')}
              className="sm:w-56"
              value={category}
              onChange={(e) => (setCategory(e.target.value as ExpenseCategory | ''), setPage(1))}
            >
              <option value="">{t('expenses.allCategories')}</option>
              {(categories.data ?? []).map((c) => (
                <option key={c.code} value={c.code}>
                  {c.name}
                </option>
              ))}
            </NativeSelect>
          </div>
          {list.data ? (
            <p className="text-right">
              <span className="block text-xs text-muted-foreground">{t('expenses.total')}</span>
              <span className="text-xl font-semibold tabular-nums">
                {money(list.data.totalUzs)}
              </span>
            </p>
          ) : null}
        </div>
        {list.isPending ? (
          <TableSkeleton />
        ) : list.isError ? (
          <ErrorState error={list.error} onRetry={() => list.refetch()} />
        ) : list.data.items.length === 0 ? (
          <EmptyState icon={Receipt} title={t('expenses.empty')} text={t('expenses.emptyText')} />
        ) : (
          <>
            <ExpensesTable items={list.data.items} onEdit={setEditing} />
            <Pagination
              page={list.data.page}
              pageSize={list.data.pageSize}
              total={list.data.total}
              onPage={setPage}
            />
          </>
        )}
      </Card>
      <ExpenseDialog
        open={editing !== null}
        onOpenChange={(o) => (!o ? setEditing(null) : undefined)}
        expense={editing === 'new' ? null : editing}
      />
    </>
  );
}

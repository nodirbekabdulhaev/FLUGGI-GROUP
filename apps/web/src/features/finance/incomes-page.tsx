'use client';

import { CURRENCIES, type Currency, type OtherIncomeDto } from '@fluggi/contracts';
import { HandCoins, Pencil, Plus, Trash2 } from 'lucide-react';
import { useTranslations } from 'next-intl';
import Link from 'next/link';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { PageHeader } from '@/components/shared/page-header';
import { Pagination } from '@/components/shared/pagination';
import { EmptyState, ErrorState, TableSkeleton } from '@/components/shared/states';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Dialog, DialogContent, DialogFooter } from '@/components/ui/dialog';
import { Input, NativeSelect } from '@/components/ui/input';
import { Field } from '@/components/ui/label';
import { MoneyInput } from '@/components/ui/money-input';
import { TBody, TD, TH, THead, TR, Table } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import { useProjects } from '@/features/projects/api';
import { ApiError, api, errorMessage } from '@/lib/api-client';
import { date, money } from '@/lib/format';
import { useFinanceCategories } from './api';
import { useCatalogMutation, useOtherIncomes } from './tariffs-api';

const today = () => new Date(Date.now() + 5 * 3600_000).toISOString().slice(0, 10);

function IncomeDialog({
  income,
  open,
  onOpenChange,
}: {
  income: OtherIncomeDto | null;
  open: boolean;
  onOpenChange: (o: boolean) => void;
}) {
  const t = useTranslations('incomes');
  const categories = useFinanceCategories('INCOME');
  const projects = useProjects({ view: 'all', pageSize: 100 });
  const [v, setV] = useState({
    category: '',
    amount: '',
    currency: 'UZS' as Currency,
    incomeDate: today(),
    projectId: '',
    description: '',
  });
  const [errors, setErrors] = useState<Record<string, string>>({});
  useEffect(() => {
    if (!open) return;
    setErrors({});
    setV({
      category: income?.category ?? '',
      amount: income ? String(Number(income.amount)) : '',
      currency: income?.currency ?? 'UZS',
      incomeDate: income?.incomeDate ?? today(),
      projectId: income?.project?.id ?? '',
      description: income?.description ?? '',
    });
  }, [open, income]);
  const save = useCatalogMutation(() => {
    const body = { ...v, projectId: v.projectId || null, description: v.description || null };
    return income
      ? api(`/other-incomes/${income.id}`, { method: 'PUT', body })
      : api('/other-incomes', { method: 'POST', body });
  });
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent title={income ? t('edit') : t('new')}>
        <form
          className="grid gap-4"
          onSubmit={async (e) => {
            e.preventDefault();
            try {
              await save.mutateAsync(undefined);
              toast.success(t('saved'));
              onOpenChange(false);
            } catch (err) {
              if (err instanceof ApiError) setErrors(err.fieldErrors());
              toast.error(errorMessage(err));
            }
          }}
        >
          <Field label={t('category')} htmlFor="oi-cat" error={errors.category}>
            <NativeSelect
              id="oi-cat"
              required
              value={v.category}
              onChange={(e) => setV((s) => ({ ...s, category: e.target.value }))}
            >
              <option value="">—</option>
              {(categories.data ?? [])
                .filter((c) => c.isActive || c.code === v.category)
                .map((c) => (
                  <option key={c.code} value={c.code}>
                    {c.name}
                  </option>
                ))}
            </NativeSelect>
          </Field>
          <div className="grid gap-4 sm:grid-cols-[1fr_7rem_10rem]">
            <Field label={t('amount')} htmlFor="oi-amount" error={errors.amount}>
              <MoneyInput
                id="oi-amount"
                required
                value={v.amount}
                onChange={(e) => setV((s) => ({ ...s, amount: e.target.value }))}
              />
            </Field>
            <Field label={t('currency')} htmlFor="oi-cur">
              <NativeSelect
                id="oi-cur"
                value={v.currency}
                onChange={(e) => setV((s) => ({ ...s, currency: e.target.value as Currency }))}
              >
                {CURRENCIES.map((c) => (
                  <option key={c}>{c}</option>
                ))}
              </NativeSelect>
            </Field>
            <Field label={t('date')} htmlFor="oi-date" error={errors.incomeDate}>
              <Input
                id="oi-date"
                type="date"
                required
                value={v.incomeDate}
                onChange={(e) => setV((s) => ({ ...s, incomeDate: e.target.value }))}
              />
            </Field>
          </div>
          <Field label={t('project')} htmlFor="oi-project" hint={t('projectHint')}>
            <NativeSelect
              id="oi-project"
              value={v.projectId}
              onChange={(e) => setV((s) => ({ ...s, projectId: e.target.value }))}
            >
              <option value="">{t('noProject')}</option>
              {projects.data?.items.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.number} · {p.name}
                </option>
              ))}
            </NativeSelect>
          </Field>
          <Field label={t('description')} htmlFor="oi-desc">
            <Textarea
              id="oi-desc"
              rows={2}
              value={v.description}
              onChange={(e) => setV((s) => ({ ...s, description: e.target.value }))}
            />
          </Field>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
              {t('cancel')}
            </Button>
            <Button type="submit" loading={save.isPending}>
              {t('save')}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

/** Прочие поступления — доходы не от клиентов (партнёрские, возвраты, проценты банка, курсовая разница). */
export function IncomesPage() {
  const t = useTranslations('incomes');
  const categories = useFinanceCategories('INCOME');
  const [category, setCategory] = useState('');
  const [page, setPage] = useState(1);
  const [editing, setEditing] = useState<OtherIncomeDto | null | 'new'>(null);
  const list = useOtherIncomes({ category: category || undefined, page, pageSize: 25 });
  const remove = useCatalogMutation((id: string) =>
    api(`/other-incomes/${id}`, { method: 'DELETE' }),
  );
  return (
    <>
      <PageHeader
        title={t('title')}
        description={t('subtitle')}
        actions={
          <Button onClick={() => setEditing('new')}>
            <Plus /> {t('new')}
          </Button>
        }
      />
      <Card>
        <div className="flex flex-col gap-3 border-b p-4 sm:flex-row sm:items-end">
          <NativeSelect
            aria-label={t('category')}
            className="sm:w-64"
            value={category}
            onChange={(e) => (setCategory(e.target.value), setPage(1))}
          >
            <option value="">{t('allCategories')}</option>
            {(categories.data ?? []).map((c) => (
              <option key={c.code} value={c.code}>
                {c.name}
              </option>
            ))}
          </NativeSelect>
        </div>
        {list.isPending ? (
          <TableSkeleton />
        ) : list.isError ? (
          <ErrorState error={list.error} onRetry={() => list.refetch()} />
        ) : list.data.items.length === 0 ? (
          <EmptyState icon={HandCoins} title={t('empty')} text={t('emptyText')} />
        ) : (
          <>
            <div className="overflow-x-auto">
              <Table>
                <THead>
                  <tr>
                    <TH>{t('date')}</TH>
                    <TH>{t('category')}</TH>
                    <TH>{t('project')}</TH>
                    <TH className="text-right">{t('amount')}</TH>
                    <TH />
                  </tr>
                </THead>
                <TBody>
                  {list.data.items.map((i) => (
                    <TR key={i.id}>
                      <TD className="whitespace-nowrap">
                        {date(i.incomeDate)}
                        <span className="block text-xs text-muted-foreground">{i.number}</span>
                      </TD>
                      <TD>
                        {i.categoryName}
                        {i.description ? (
                          <span className="block max-w-72 truncate text-xs text-muted-foreground">
                            {i.description}
                          </span>
                        ) : null}
                      </TD>
                      <TD>
                        {i.project ? (
                          <Link href={`/projects/${i.project.id}`} className="hover:underline">
                            {i.project.number} · {i.project.name}
                          </Link>
                        ) : (
                          <span className="text-muted-foreground">—</span>
                        )}
                      </TD>
                      <TD className="whitespace-nowrap text-right font-medium tabular-nums">
                        {money(i.amount, i.currency)}
                        {i.currency !== 'UZS' ? (
                          <span className="block text-xs text-muted-foreground">
                            {money(i.amountUzs, 'UZS')}
                          </span>
                        ) : null}
                      </TD>
                      <TD className="whitespace-nowrap text-right">
                        <Button
                          size="icon-sm"
                          variant="ghost"
                          aria-label={`${t('edit')} ${i.number}`}
                          onClick={() => setEditing(i)}
                        >
                          <Pencil />
                        </Button>
                        <Button
                          size="icon-sm"
                          variant="ghost"
                          aria-label={`${t('delete')} ${i.number}`}
                          onClick={async () => {
                            if (!window.confirm(t('deleteConfirm', { number: i.number }))) return;
                            try {
                              await remove.mutateAsync(i.id);
                              toast.success(t('deleted'));
                            } catch (err) {
                              toast.error(errorMessage(err));
                            }
                          }}
                        >
                          <Trash2 />
                        </Button>
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
      <IncomeDialog
        income={editing === 'new' ? null : editing}
        open={editing !== null}
        onOpenChange={(o) => (!o ? setEditing(null) : undefined)}
      />
    </>
  );
}

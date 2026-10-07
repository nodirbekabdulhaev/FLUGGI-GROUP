'use client';

import {
  CURRENCIES,
  EXECUTOR_SPECIALTIES,
  type Currency,
  type ExecutorSpecialty,
  type FinanceCategoryDto,
  type FinanceCategoryKind,
  type WorkItemDto,
} from '@fluggi/contracts';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { useTranslations } from 'next-intl';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { ErrorState, TableSkeleton } from '@/components/shared/states';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogFooter } from '@/components/ui/dialog';
import { Input, NativeSelect } from '@/components/ui/input';
import { Field } from '@/components/ui/label';
import { MoneyInput } from '@/components/ui/money-input';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { ApiError, api, errorMessage } from '@/lib/api-client';
import { money } from '@/lib/format';
import { useCan } from '@/lib/me-context';
import { useFinanceCategories } from './api';
import { useCatalogMutation, useFinanceSettings, useWorkItems } from './tariffs-api';

function CategoryDialog({
  category,
  kind,
  open,
  onOpenChange,
}: {
  category: FinanceCategoryDto | null;
  kind: FinanceCategoryKind;
  open: boolean;
  onOpenChange: (o: boolean) => void;
}) {
  const t = useTranslations('financeSettings');
  const [v, setV] = useState({ name: '', accountHint: '', isOverhead: false, isActive: true, sort: '100' });
  const [errors, setErrors] = useState<Record<string, string>>({});
  useEffect(() => {
    if (!open) return;
    setErrors({});
    setV({
      name: category?.name ?? '',
      accountHint: category?.accountHint ?? (kind === 'EXPENSE' ? '9420' : '9390'),
      isOverhead: category?.isOverhead ?? false,
      isActive: category?.isActive ?? true,
      sort: String(category?.sort ?? 100),
    });
  }, [open, category, kind]);
  const save = useCatalogMutation(() => {
    const body = { kind, ...v, accountHint: v.accountHint || null, sort: Number(v.sort) };
    return category
      ? api(`/finance-categories/${category.id}`, { method: 'PUT', body })
      : api('/finance-categories', { method: 'POST', body });
  });
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent title={category ? t('categoryEdit') : t(kind === 'EXPENSE' ? 'newExpenseCategory' : 'newIncomeCategory')}>
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
          <Field label={t('name')} htmlFor="fc-name" error={errors.name}>
            <Input id="fc-name" required value={v.name} onChange={(e) => setV((s) => ({ ...s, name: e.target.value }))} />
          </Field>
          <Field label={t('accountHint')} htmlFor="fc-acc" hint={t('accountHintText')}>
            <Input id="fc-acc" value={v.accountHint} onChange={(e) => setV((s) => ({ ...s, accountHint: e.target.value }))} />
          </Field>
          {kind === 'EXPENSE' ? (
            <label className="flex items-start gap-2 text-sm">
              <input
                type="checkbox"
                className="mt-0.5"
                checked={v.isOverhead}
                onChange={(e) => setV((s) => ({ ...s, isOverhead: e.target.checked }))}
              />
              <span>
                {t('isOverhead')}
                <span className="block text-xs text-muted-foreground">{t('isOverheadHint')}</span>
              </span>
            </label>
          ) : null}
          <label className="flex items-center gap-2 text-sm">
            <input type="checkbox" checked={v.isActive} onChange={(e) => setV((s) => ({ ...s, isActive: e.target.checked }))} />
            {t('active')}
          </label>
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

function Categories({ kind }: { kind: FinanceCategoryKind }) {
  const t = useTranslations('financeSettings');
  const list = useFinanceCategories(kind);
  const [dialog, setDialog] = useState<{ open: boolean; c: FinanceCategoryDto | null }>({ open: false, c: null });
  const remove = useCatalogMutation((id: string) => api(`/finance-categories/${id}`, { method: 'DELETE' }));
  return (
    <Card>
      <CardHeader className="flex flex-row flex-wrap items-start justify-between gap-3">
        <div>
          <CardTitle>{t(kind === 'EXPENSE' ? 'expenseCategories' : 'incomeCategories')}</CardTitle>
          <CardDescription>{t(kind === 'EXPENSE' ? 'expenseCategoriesText' : 'incomeCategoriesText')}</CardDescription>
        </div>
        <Button size="sm" onClick={() => setDialog({ open: true, c: null })}>
          <Plus className="size-4" /> {t('add')}
        </Button>
      </CardHeader>
      {list.isPending ? (
        <TableSkeleton rows={5} cols={3} />
      ) : list.isError ? (
        <ErrorState error={list.error} onRetry={() => list.refetch()} />
      ) : (
        <Table>
          <THead>
            <TR>
              <TH>{t('name')}</TH>
              <TH>{t('accountHint')}</TH>
              <TH className="text-right">{t('usage')}</TH>
              <TH className="w-24" />
            </TR>
          </THead>
          <TBody>
            {list.data.map((c) => (
              <TR key={c.id} className={c.isActive ? '' : 'opacity-60'}>
                <TD>
                  <span className="font-medium">{c.name}</span>{' '}
                  {c.isOverhead ? <Badge tone="warning">{t('overheadBadge')}</Badge> : null}
                  {!c.isActive ? <Badge>{t('off')}</Badge> : null}
                </TD>
                <TD className="text-muted-foreground">{c.accountHint ?? '—'}</TD>
                <TD className="text-right tabular-nums">{c.usage}</TD>
                <TD className="whitespace-nowrap text-right">
                  <Button size="sm" variant="ghost" aria-label={`${t('categoryEdit')}: ${c.name}`} onClick={() => setDialog({ open: true, c })}>
                    <Pencil className="size-4" />
                  </Button>
                  {c.usage === 0 ? (
                    <Button
                      size="sm"
                      variant="ghost"
                      aria-label={`${t('delete')}: ${c.name}`}
                      onClick={async () => {
                        if (!window.confirm(t('deleteConfirm', { name: c.name }))) return;
                        try {
                          await remove.mutateAsync(c.id);
                        } catch (err) {
                          toast.error(errorMessage(err));
                        }
                      }}
                    >
                      <Trash2 className="size-4" />
                    </Button>
                  ) : null}
                </TD>
              </TR>
            ))}
          </TBody>
        </Table>
      )}
      <CategoryDialog kind={kind} category={dialog.c} open={dialog.open} onOpenChange={(o) => setDialog((s) => ({ ...s, open: o }))} />
    </Card>
  );
}

function Overhead() {
  const t = useTranslations('financeSettings');
  const s = useFinanceSettings();
  const [mode, setMode] = useState<'auto' | 'fixed'>('auto');
  const [divisor, setDivisor] = useState('5');
  useEffect(() => {
    if (!s.data) return;
    setMode(s.data.overheadDivisor ? 'fixed' : 'auto');
    setDivisor(String(s.data.overheadDivisor ?? 5));
  }, [s.data]);
  const save = useCatalogMutation(() =>
    api('/settings/finance', { method: 'PUT', body: { overheadDivisor: mode === 'fixed' ? Number(divisor) : null } }),
  );
  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('overheadTitle')}</CardTitle>
        <CardDescription>{t('overheadText')}</CardDescription>
      </CardHeader>
      <CardContent>
        <form
          className="grid gap-3 text-sm"
          onSubmit={async (e) => {
            e.preventDefault();
            try {
              await save.mutateAsync(undefined);
              toast.success(t('saved'));
            } catch (err) {
              toast.error(errorMessage(err));
            }
          }}
        >
          <label className="flex items-center gap-2">
            <input type="radio" name="oh" checked={mode === 'auto'} onChange={() => setMode('auto')} />
            {t('overheadAuto')}
          </label>
          <label className="flex flex-wrap items-center gap-2">
            <input type="radio" name="oh" checked={mode === 'fixed'} onChange={() => setMode('fixed')} />
            {t('overheadFixed')}
            <Input
              aria-label={t('divisor')}
              type="number"
              min={1}
              max={1000}
              className="h-8 w-20"
              value={divisor}
              disabled={mode !== 'fixed'}
              onChange={(e) => setDivisor(e.target.value)}
            />
            {t('projects')}
          </label>
          <div>
            <Button type="submit" size="sm" loading={save.isPending}>
              {t('save')}
            </Button>
          </div>
        </form>
      </CardContent>
    </Card>
  );
}

function WorkItemDialog({ item, open, onOpenChange }: { item: WorkItemDto | null; open: boolean; onOpenChange: (o: boolean) => void }) {
  const t = useTranslations('financeSettings');
  const ts = useTranslations('specialties');
  const [v, setV] = useState({ name: '', unit: 'шт', specialty: '' as ExecutorSpecialty | '', defaultRate: '', currency: 'UZS' as Currency, isActive: true });
  useEffect(() => {
    if (!open) return;
    setV({
      name: item?.name ?? '',
      unit: item?.unit ?? 'шт',
      specialty: item?.specialty ?? '',
      defaultRate: item ? String(Number(item.defaultRate)) : '',
      currency: item?.currency ?? 'UZS',
      isActive: item?.isActive ?? true,
    });
  }, [open, item]);
  const save = useCatalogMutation(() => {
    const body = { ...v, specialty: v.specialty || null };
    return item ? api(`/work-items/${item.id}`, { method: 'PUT', body }) : api('/work-items', { method: 'POST', body });
  });
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent title={item ? t('workItemEdit') : t('workItemNew')}>
        <form
          className="grid gap-4"
          onSubmit={async (e) => {
            e.preventDefault();
            try {
              await save.mutateAsync(undefined);
              toast.success(t('saved'));
              onOpenChange(false);
            } catch (err) {
              toast.error(errorMessage(err));
            }
          }}
        >
          <Field label={t('name')} htmlFor="wi-name">
            <Input id="wi-name" required value={v.name} onChange={(e) => setV((s) => ({ ...s, name: e.target.value }))} />
          </Field>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label={t('specialty')} htmlFor="wi-spec">
              <NativeSelect id="wi-spec" value={v.specialty} onChange={(e) => setV((s) => ({ ...s, specialty: e.target.value as ExecutorSpecialty | '' }))}>
                <option value="">—</option>
                {EXECUTOR_SPECIALTIES.map((sp) => (
                  <option key={sp} value={sp}>
                    {ts(sp)}
                  </option>
                ))}
              </NativeSelect>
            </Field>
            <Field label={t('unit')} htmlFor="wi-unit">
              <Input id="wi-unit" value={v.unit} onChange={(e) => setV((s) => ({ ...s, unit: e.target.value }))} />
            </Field>
            <Field label={t('defaultRate')} htmlFor="wi-rate">
              <MoneyInput id="wi-rate" required value={v.defaultRate} onChange={(e) => setV((s) => ({ ...s, defaultRate: e.target.value }))} />
            </Field>
            <Field label={t('currency')} htmlFor="wi-cur">
              <NativeSelect id="wi-cur" value={v.currency} onChange={(e) => setV((s) => ({ ...s, currency: e.target.value as Currency }))}>
                {CURRENCIES.map((c) => (
                  <option key={c}>{c}</option>
                ))}
              </NativeSelect>
            </Field>
          </div>
          <label className="flex items-center gap-2 text-sm">
            <input type="checkbox" checked={v.isActive} onChange={(e) => setV((s) => ({ ...s, isActive: e.target.checked }))} />
            {t('active')}
          </label>
          <DialogFooter>
            <Button type="submit" loading={save.isPending}>
              {t('save')}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

function WorkItems() {
  const t = useTranslations('financeSettings');
  const ts = useTranslations('specialties');
  const list = useWorkItems();
  const [dialog, setDialog] = useState<{ open: boolean; w: WorkItemDto | null }>({ open: false, w: null });
  return (
    <Card>
      <CardHeader className="flex flex-row flex-wrap items-start justify-between gap-3">
        <div>
          <CardTitle>{t('workItems')}</CardTitle>
          <CardDescription>{t('workItemsText')}</CardDescription>
        </div>
        <Button size="sm" onClick={() => setDialog({ open: true, w: null })}>
          <Plus className="size-4" /> {t('add')}
        </Button>
      </CardHeader>
      {list.isPending ? (
        <TableSkeleton rows={4} cols={3} />
      ) : list.isError ? (
        <ErrorState error={list.error} onRetry={() => list.refetch()} />
      ) : (
        <Table>
          <THead>
            <TR>
              <TH>{t('name')}</TH>
              <TH>{t('specialty')}</TH>
              <TH className="text-right">{t('defaultRate')}</TH>
              <TH className="w-12" />
            </TR>
          </THead>
          <TBody>
            {list.data.map((w) => (
              <TR key={w.id} className={w.isActive ? '' : 'opacity-60'}>
                <TD className="font-medium">{w.name}</TD>
                <TD className="text-muted-foreground">{w.specialty ? ts(w.specialty) : '—'}</TD>
                <TD className="whitespace-nowrap text-right tabular-nums">
                  {money(w.defaultRate, w.currency)} / {w.unit}
                </TD>
                <TD>
                  <Button size="sm" variant="ghost" aria-label={`${t('workItemEdit')}: ${w.name}`} onClick={() => setDialog({ open: true, w })}>
                    <Pencil className="size-4" />
                  </Button>
                </TD>
              </TR>
            ))}
          </TBody>
        </Table>
      )}
      <WorkItemDialog item={dialog.w} open={dialog.open} onOpenChange={(o) => setDialog((s) => ({ ...s, open: o }))} />
    </Card>
  );
}

/** Настройки → «Финансы»: категории доходов и расходов, накладные, единицы работ исполнителей. */
export function FinanceSettingsPage() {
  const can = useCan();
  return (
    <div className="grid gap-6 lg:grid-cols-2">
      <Categories kind="EXPENSE" />
      <div className="grid content-start gap-6">
        <Categories kind="INCOME" />
        {can('finance.company.read', 'ALL') ? <Overhead /> : null}
      </div>
      <div className="lg:col-span-2">
        <WorkItems />
      </div>
    </div>
  );
}

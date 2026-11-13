'use client';

import type { ExpenseDto, ProjectCostLineDto, ProjectDetailDto } from '@fluggi/contracts';
import { Ban, CircleDollarSign, Pencil, Plus } from 'lucide-react';
import { useTranslations } from 'next-intl';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { ErrorState, TableSkeleton } from '@/components/shared/states';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter } from '@/components/ui/dialog';
import { Input, NativeSelect } from '@/components/ui/input';
import { Field } from '@/components/ui/label';
import { MoneyInput } from '@/components/ui/money-input';
import { TBody, TD, TH, THead, TR, Table } from '@/components/ui/table';
import { api, errorMessage } from '@/lib/api-client';
import { money } from '@/lib/format';
import { useCan } from '@/lib/me-context';
import { cn } from '@/lib/utils';
import { useExpenses, useProjectFinance } from './api';
import { CategoryBars } from './dashboard-page';
import { ExpenseDialog } from './expense-dialog';
import { ExpensesTable } from './expenses-page';
import { useCatalogMutation, useCostLines } from './tariffs-api';

function CostLineDialog({
  line,
  project,
  onClose,
}: {
  line: ProjectCostLineDto | null;
  project: ProjectDetailDto;
  onClose: () => void;
}) {
  const t = useTranslations('costLines');
  const [v, setV] = useState({ quantity: '1', rate: '', assigneeId: '' });
  useEffect(() => {
    if (!line) return;
    setV({
      quantity: String(Number(line.quantity)),
      rate: String(Number(line.rate)),
      assigneeId: line.assignee?.id ?? '',
    });
  }, [line]);
  const save = useCatalogMutation(() =>
    api(`/cost-lines/${line?.id}`, {
      method: 'PATCH',
      body: { quantity: Number(v.quantity), rate: v.rate, assigneeId: v.assigneeId || null },
    }),
  );
  const members = project.members.filter((m) => m.status === 'ACTIVE');
  return (
    <Dialog open={Boolean(line)} onOpenChange={(o) => !o && onClose()}>
      <DialogContent title={`${t('edit')}: ${line?.label ?? ''}`}>
        <form
          className="grid gap-4"
          onSubmit={async (e) => {
            e.preventDefault();
            try {
              await save.mutateAsync(undefined);
              toast.success(t('saved'));
              onClose();
            } catch (err) {
              toast.error(errorMessage(err));
            }
          }}
        >
          <Field label={t('assignee')} htmlFor="cl-user" hint={t('assigneeHint')}>
            <NativeSelect
              id="cl-user"
              value={v.assigneeId}
              onChange={(e) => setV((s) => ({ ...s, assigneeId: e.target.value }))}
            >
              <option value="">{t('notAssigned')}</option>
              {members.map((m) => (
                <option key={m.user.id} value={m.user.id}>
                  {m.user.name}
                </option>
              ))}
            </NativeSelect>
          </Field>
          <div className="grid gap-4 sm:grid-cols-2">
            {line?.kind === 'PIECE' ? (
              <Field label={t('quantity')} htmlFor="cl-qty">
                <Input
                  id="cl-qty"
                  type="number"
                  min={0.01}
                  step="any"
                  required
                  value={v.quantity}
                  onChange={(e) => setV((s) => ({ ...s, quantity: e.target.value }))}
                />
              </Field>
            ) : null}
            <Field
              label={`${t(line?.kind === 'PIECE' ? 'rate' : 'amount')}, ${line?.currency ?? ''}`}
              htmlFor="cl-rate"
            >
              <MoneyInput
                id="cl-rate"
                required
                value={v.rate}
                onChange={(e) => setV((s) => ({ ...s, rate: e.target.value }))}
              />
            </Field>
          </div>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={onClose}>
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

/** План расходов на исполнителей по тарифу из принятого КП: назначить, поправить, начислить. */
function CostLines({ project }: { project: ProjectDetailDto }) {
  const t = useTranslations('costLines');
  const ts = useTranslations('specialties');
  const can = useCan();
  const lines = useCostLines(project.id);
  const [editing, setEditing] = useState<ProjectCostLineDto | null>(null);
  const accrue = useCatalogMutation((id: string) =>
    api(`/cost-lines/${id}/accrue`, { method: 'POST' }),
  );
  const cancel = useCatalogMutation((id: string) =>
    api(`/cost-lines/${id}/cancel`, { method: 'POST' }),
  );
  const canEdit = can('expense.update');
  if (lines.isPending) return <TableSkeleton rows={3} cols={4} />;
  if (lines.isError) return <ErrorState error={lines.error} onRetry={() => lines.refetch()} />;
  if (lines.data.length === 0)
    return (
      <section className="grid gap-1">
        <h3 className="text-sm font-semibold">{t('title')}</h3>
        <p className="text-sm text-muted-foreground">{t('empty')}</p>
      </section>
    );
  return (
    <section className="grid content-start gap-3">
      <div>
        <h3 className="text-sm font-semibold">{t('title')}</h3>
        <p className="text-xs text-muted-foreground">{t('text')}</p>
      </div>
      <div className="overflow-x-auto">
        <Table>
          <THead>
            <tr>
              <TH>{t('work')}</TH>
              <TH>{t('assignee')}</TH>
              <TH className="text-right">{t('qtyRate')}</TH>
              <TH className="text-right">{t('amount')}</TH>
              <TH>{t('status')}</TH>
              <TH />
            </tr>
          </THead>
          <TBody>
            {lines.data.map((l) => (
              <TR key={l.id}>
                <TD>
                  <span className="font-medium">{l.label}</span>
                  {l.specialty ? (
                    <span className="block text-xs text-muted-foreground">{ts(l.specialty)}</span>
                  ) : null}
                </TD>
                <TD>
                  {l.assignee ? (
                    l.assignee.name
                  ) : (
                    <span className="text-muted-foreground">{t('notAssigned')}</span>
                  )}
                  {l.personalRate ? (
                    <span className="block text-xs text-muted-foreground">{t('personalRate')}</span>
                  ) : null}
                </TD>
                <TD className="whitespace-nowrap text-right tabular-nums">
                  {l.kind === 'PIECE'
                    ? `${Number(l.quantity)} × ${money(l.rate, l.currency)}`
                    : t('fixed')}
                </TD>
                <TD className="whitespace-nowrap text-right font-medium tabular-nums">
                  {money(l.amount, l.currency)}
                </TD>
                <TD>
                  <Badge tone={l.status === 'ACCRUED' ? 'success' : 'neutral'}>
                    {t(`status${l.status}`)}
                  </Badge>
                  {l.expense ? (
                    <span className="block text-xs text-muted-foreground">{l.expense.number}</span>
                  ) : null}
                </TD>
                <TD className="whitespace-nowrap text-right">
                  {canEdit && l.status === 'PLANNED' ? (
                    <>
                      <Button
                        size="icon-sm"
                        variant="ghost"
                        aria-label={`${t('edit')}: ${l.label}`}
                        onClick={() => setEditing(l)}
                      >
                        <Pencil />
                      </Button>
                      <Button
                        size="icon-sm"
                        variant="ghost"
                        aria-label={`${t('accrue')}: ${l.label}`}
                        title={t('accrue')}
                        onClick={async () => {
                          if (!window.confirm(t('accrueConfirm', { label: l.label }))) return;
                          try {
                            await accrue.mutateAsync(l.id);
                            toast.success(t('accrued'));
                          } catch (err) {
                            toast.error(errorMessage(err));
                          }
                        }}
                      >
                        <CircleDollarSign />
                      </Button>
                      <Button
                        size="icon-sm"
                        variant="ghost"
                        aria-label={`${t('cancelLine')}: ${l.label}`}
                        title={t('cancelLine')}
                        onClick={async () => {
                          if (!window.confirm(t('cancelConfirm', { label: l.label }))) return;
                          try {
                            await cancel.mutateAsync(l.id);
                          } catch (err) {
                            toast.error(errorMessage(err));
                          }
                        }}
                      >
                        <Ban />
                      </Button>
                    </>
                  ) : null}
                </TD>
              </TR>
            ))}
          </TBody>
        </Table>
      </div>
      <CostLineDialog line={editing} project={project} onClose={() => setEditing(null)} />
    </section>
  );
}

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
    [t('project.overhead'), money(d.overheadUzs)],
    [
      t('project.netProfit'),
      money(d.netProfitUzs),
      Number(d.netProfitUzs) < 0 ? 'text-danger' : 'text-success',
    ],
    ...(Number(d.plannedCostUzs) > 0
      ? ([[t('project.plannedCost'), money(d.plannedCostUzs)]] as [string, string][])
      : []),
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
      <CostLines project={project} />
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

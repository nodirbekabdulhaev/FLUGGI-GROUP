'use client';

import type { PayrollEntryDto } from '@fluggi/contracts';
import { Calculator, Pencil, Wallet } from 'lucide-react';
import { useTranslations } from 'next-intl';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { PageHeader } from '@/components/shared/page-header';
import { EmptyState, ErrorState, TableSkeleton } from '@/components/shared/states';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Dialog, DialogContent, DialogFooter } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Field } from '@/components/ui/label';
import { MoneyInput } from '@/components/ui/money-input';
import { TBody, TD, TH, THead, TR, Table } from '@/components/ui/table';
import { useCrmMutation } from '@/features/crm/api';
import { api, errorMessage } from '@/lib/api-client';
import { money } from '@/lib/format';
import { useCan } from '@/lib/me-context';
import { currentMonth, usePayroll } from './api';

const TONE = { DRAFT: 'neutral', APPROVED: 'accent', PAID: 'success' } as const;

function EditDialog({ entry, onClose }: { entry: PayrollEntryDto | null; onClose: () => void }) {
  const t = useTranslations('payroll');
  const [v, setV] = useState({
    baseSalary: '',
    kpiBonus: '',
    otherBonus: '',
    penalty: '',
    comment: '',
    kpiBonusTarget: '',
  });
  const [saveBase, setSaveBase] = useState(true);
  // KPI-бонус по «бонусу при 100%»: выполнение KPI × сумма (до 120%)
  const autoBonus = (target: string) =>
    entry?.kpiPct && Number(target) > 0
      ? String(Math.round((Number(target) * Math.min(Number(entry.kpiPct), 120)) / 100))
      : '0';
  useEffect(() => {
    if (!entry) return;
    setV({
      baseSalary: String(Number(entry.baseSalary)),
      kpiBonus: String(Number(entry.kpiBonus)),
      otherBonus: String(Number(entry.otherBonus)),
      penalty: String(Number(entry.penalty)),
      comment: entry.comment ?? '',
      kpiBonusTarget: entry.kpiBonusTarget ? String(Number(entry.kpiBonusTarget)) : '',
    });
  }, [entry]);
  const save = useCrmMutation(() =>
    api(`/payroll/${entry!.id}`, {
      method: 'PATCH',
      body: {
        baseSalary: v.baseSalary || '0',
        kpiBonus: v.kpiBonus || '0',
        otherBonus: v.otherBonus || '0',
        penalty: v.penalty || '0',
        comment: v.comment || null,
        saveBaseSalary: saveBase,
        kpiBonusTarget: v.kpiBonusTarget ? v.kpiBonusTarget : null,
      },
    }),
  );
  const final =
    Number(v.baseSalary || 0) +
    Number(v.kpiBonus || 0) +
    Number(entry?.commission ?? 0) +
    Number(v.otherBonus || 0) -
    Number(v.penalty || 0);
  const field = (k: 'baseSalary' | 'kpiBonus' | 'otherBonus' | 'penalty', label: string) => (
    <Field label={label} htmlFor={`pr-${k}`}>
      <MoneyInput
        id={`pr-${k}`}
        value={v[k]}
        onChange={(e) => setV((s) => ({ ...s, [k]: e.target.value }))}
      />
    </Field>
  );
  return (
    <Dialog open={Boolean(entry)} onOpenChange={(o) => (!o ? onClose() : undefined)}>
      <DialogContent
        title={entry ? t('editTitle', { period: entry.period, name: entry.user.name }) : ''}
      >
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
          <div className="grid gap-4 sm:grid-cols-2">
            {field('baseSalary', t('base'))}
            <Field
              label={t('kpiBonusTarget')}
              htmlFor="pr-kpi-target"
              hint={t('kpiBonusTargetHint')}
            >
              <MoneyInput
                id="pr-kpi-target"
                value={v.kpiBonusTarget}
                onChange={(e) =>
                  setV((s) => ({
                    ...s,
                    kpiBonusTarget: e.target.value,
                    kpiBonus: e.target.value ? autoBonus(e.target.value) : s.kpiBonus,
                  }))
                }
              />
            </Field>
            {field('kpiBonus', `${t('kpiBonus')}${entry?.kpiPct ? ` (KPI ${entry.kpiPct}%)` : ''}`)}
            <Field label={t('commission')} htmlFor="pr-comm">
              <Input id="pr-comm" disabled value={money(entry?.commission ?? 0)} />
            </Field>
            {field('otherBonus', t('otherBonus'))}
            {field('penalty', t('penalty'))}
            <Field label="Комментарий" htmlFor="pr-comment">
              <Input
                id="pr-comment"
                value={v.comment}
                onChange={(e) => setV((s) => ({ ...s, comment: e.target.value }))}
              />
            </Field>
          </div>
          <label className="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              checked={saveBase}
              onChange={(e) => setSaveBase(e.target.checked)}
            />
            {t('saveBase')}
          </label>
          <p className="text-right text-lg font-semibold tabular-nums">
            {t('final')}: {money(final)}
          </p>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={onClose}>
              Отмена
            </Button>
            <Button type="submit" loading={save.isPending}>
              Сохранить
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

/**
 * Зарплата (ТЗ §32). Комиссия — отдельная строка, не смешивается с окладом.
 * Свою видит каждый, всех — только CEO.
 */
export function PayrollPage() {
  const t = useTranslations('payroll');
  const can = useCan();
  const manage = can('payroll.manage', 'ALL');
  const [period, setPeriod] = useState(currentMonth());
  const list = usePayroll(period);
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [editing, setEditing] = useState<PayrollEntryDto | null>(null);
  const calc = useCrmMutation(() =>
    api('/payroll/calculate', { method: 'POST', body: { period } }),
  );
  const act = useCrmMutation(({ to, ids }: { to: 'approve' | 'pay'; ids: string[] }) =>
    api<{ updated: number }>(`/payroll/${to}`, { method: 'POST', body: { ids } }),
  );
  const items = list.data ?? [];
  const chosen = items.filter((e) => selected.has(e.id));
  const run = async (to: 'approve' | 'pay') => {
    try {
      const r = await act.mutateAsync({ to, ids: chosen.map((e) => e.id) });
      toast.success(t(to === 'approve' ? 'approved' : 'paid', { count: r.updated }));
      setSelected(new Set());
    } catch (err) {
      toast.error(errorMessage(err));
    }
  };
  const total = items.reduce((s, e) => s + Number(e.finalSalary), 0);

  return (
    <>
      <PageHeader
        title={t('title')}
        description={manage ? t('subtitle') : t('ownSubtitle')}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <Input
              type="month"
              aria-label={t('period')}
              className="w-44"
              value={period}
              onChange={(e) =>
                e.target.value && (setPeriod(e.target.value), setSelected(new Set()))
              }
            />
            {manage ? (
              <Button
                loading={calc.isPending}
                onClick={async () => {
                  try {
                    await calc.mutateAsync(undefined);
                    toast.success(t('calculated'));
                  } catch (err) {
                    toast.error(errorMessage(err));
                  }
                }}
              >
                <Calculator /> {t('calculate')}
              </Button>
            ) : null}
          </div>
        }
      />
      <Card>
        {manage && chosen.length > 0 ? (
          <div className="flex flex-wrap items-center gap-3 border-b bg-muted/40 px-4 py-2 text-sm">
            <span>
              {chosen.length} · {money(chosen.reduce((s, e) => s + Number(e.finalSalary), 0))}
            </span>
            <Button
              size="sm"
              disabled={!chosen.every((e) => e.status === 'DRAFT')}
              loading={act.isPending}
              onClick={() => run('approve')}
            >
              {t('approve')}
            </Button>
            <Button
              size="sm"
              variant="outline"
              disabled={!chosen.every((e) => e.status === 'APPROVED')}
              loading={act.isPending}
              onClick={() => run('pay')}
            >
              {t('pay')}
            </Button>
          </div>
        ) : null}
        {list.isPending ? (
          <TableSkeleton />
        ) : list.isError ? (
          <ErrorState error={list.error} onRetry={() => list.refetch()} />
        ) : items.length === 0 ? (
          <EmptyState
            icon={Wallet}
            title={t('empty')}
            text={manage ? t('emptyCeo') : t('emptyOwn')}
          />
        ) : (
          <div className="overflow-x-auto">
            <Table>
              <THead>
                <tr>
                  {manage ? (
                    <TH className="w-10">
                      <input
                        type="checkbox"
                        aria-label="Выбрать все"
                        checked={chosen.length === items.length}
                        onChange={(e) =>
                          setSelected(
                            e.target.checked ? new Set(items.map((x) => x.id)) : new Set(),
                          )
                        }
                      />
                    </TH>
                  ) : null}
                  <TH>{t('employee')}</TH>
                  <TH className="text-right">{t('base')}</TH>
                  <TH className="text-right">{t('kpiBonus')}</TH>
                  <TH className="text-right">{t('commission')}</TH>
                  <TH className="text-right">{t('otherBonus')}</TH>
                  <TH className="text-right">{t('penalty')}</TH>
                  <TH className="text-right">{t('final')}</TH>
                  <TH>Статус</TH>
                  {manage ? <TH /> : null}
                </tr>
              </THead>
              <TBody>
                {items.map((e) => (
                  <TR key={e.id}>
                    {manage ? (
                      <TD>
                        <input
                          type="checkbox"
                          aria-label={`Выбрать ${e.user.name}`}
                          checked={selected.has(e.id)}
                          onChange={() =>
                            setSelected((s) => {
                              const n = new Set(s);
                              if (n.has(e.id)) n.delete(e.id);
                              else n.add(e.id);
                              return n;
                            })
                          }
                        />
                      </TD>
                    ) : null}
                    <TD>
                      <span className="font-medium">{e.user.name}</span>
                      <span className="block text-xs text-muted-foreground">
                        {e.period}
                        {e.kpiPct ? ` · ${t('kpi')} ${e.kpiPct}%` : ''}
                      </span>
                    </TD>
                    <TD className="whitespace-nowrap text-right tabular-nums">
                      {money(e.baseSalary)}
                    </TD>
                    <TD className="whitespace-nowrap text-right tabular-nums">
                      {money(e.kpiBonus)}
                    </TD>
                    <TD className="whitespace-nowrap text-right tabular-nums">
                      {money(e.commission)}
                    </TD>
                    <TD className="whitespace-nowrap text-right tabular-nums">
                      {money(e.otherBonus)}
                    </TD>
                    <TD
                      className={`whitespace-nowrap text-right tabular-nums ${Number(e.penalty) ? 'text-danger' : ''}`}
                    >
                      {Number(e.penalty) ? `− ${money(e.penalty)}` : money(0)}
                    </TD>
                    <TD className="whitespace-nowrap text-right font-semibold tabular-nums">
                      {money(e.finalSalary)}
                    </TD>
                    <TD>
                      <Badge tone={TONE[e.status]}>{t(`status.${e.status}`)}</Badge>
                    </TD>
                    {manage ? (
                      <TD className="text-right">
                        {e.status === 'DRAFT' ? (
                          <Button
                            size="icon-sm"
                            variant="ghost"
                            aria-label={t('edit')}
                            onClick={() => setEditing(e)}
                          >
                            <Pencil />
                          </Button>
                        ) : null}
                      </TD>
                    ) : null}
                  </TR>
                ))}
              </TBody>
            </Table>
          </div>
        )}
        {items.length > 1 ? (
          <p className="border-t px-4 py-3 text-right text-sm">
            {t('total')}: <span className="font-semibold tabular-nums">{money(total)}</span>
          </p>
        ) : null}
      </Card>
      {manage ? <p className="mt-3 text-xs text-muted-foreground">{t('calculateHint')}</p> : null}
      <EditDialog entry={editing} onClose={() => setEditing(null)} />
    </>
  );
}

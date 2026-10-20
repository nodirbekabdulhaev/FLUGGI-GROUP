'use client';

import {
  KPI_METRICS,
  type KpiGroup,
  type KpiMetric,
  type KpiRowDto,
  type TargetProgressDto,
} from '@fluggi/contracts';
import { Target } from 'lucide-react';
import { useTranslations } from 'next-intl';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import { PageHeader } from '@/components/shared/page-header';
import { EmptyState, ErrorState, TableSkeleton } from '@/components/shared/states';
import { Tabs } from '@/components/shared/tabs';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Dialog, DialogContent, DialogFooter } from '@/components/ui/dialog';
import { Input, NativeSelect } from '@/components/ui/input';
import { Field } from '@/components/ui/label';
import { MoneyInput } from '@/components/ui/money-input';
import { TBody, TD, TH, THead, TR, Table } from '@/components/ui/table';
import { useCrmMutation } from '@/features/crm/api';
import { api, errorMessage } from '@/lib/api-client';
import { money, moneyShort } from '@/lib/format';
import { useCan, useMe } from '@/lib/me-context';
import { cn } from '@/lib/utils';
import { currentMonth, useKpi } from './api';

/** Метрики, которые имеет смысл задавать роли. */
const METRICS_FOR: Record<KpiGroup, KpiMetric[]> = {
  MANAGER: ['REVENUE', 'ORDERS', 'LEADS', 'MEETINGS'],
  ROP: ['REVENUE', 'ORDERS', 'LEADS', 'MEETINGS'],
  EXECUTOR: ['TASKS'],
};

/** Полоса выполнения цели: 100% — полная, сверх плана — без переполнения. */
export function PctBar({ pct, className }: { pct: string | null; className?: string }) {
  if (pct === null) return <span className="text-xs text-muted-foreground">—</span>;
  const v = Number(pct);
  return (
    <div className={cn('grid min-w-24 gap-1', className)}>
      <div
        className="h-1.5 overflow-hidden rounded-full bg-muted"
        role="progressbar"
        aria-valuenow={v}
      >
        <div
          className={cn(
            'h-full rounded-full',
            v >= 100 ? 'bg-success' : v >= 70 ? 'bg-accent' : 'bg-warning',
          )}
          style={{ width: `${Math.min(v, 100)}%` }}
        />
      </div>
      <span className="text-xs tabular-nums">{pct}%</span>
    </div>
  );
}

function targetText(t: TargetProgressDto, tm: ReturnType<typeof useTranslations>) {
  const fmt = (v: string) =>
    t.metric === 'REVENUE' ? moneyShort(v, t.currency) : Number(v).toLocaleString('ru-RU');
  return `${tm(`metric.${t.metric}`)}: ${fmt(t.fact)} / ${fmt(t.target)}`;
}

function TargetsDialog({
  row,
  period,
  onClose,
}: {
  row: KpiRowDto | null;
  period: string;
  onClose: () => void;
}) {
  const t = useTranslations('kpi');
  const group = (row?.role ?? 'MANAGER') as KpiGroup;
  const metrics = METRICS_FOR[group] ?? KPI_METRICS;
  const [values, setValues] = useState<Record<string, { value: string; currency: string }>>({});
  useEffect(() => {
    if (!row) return;
    setValues(
      Object.fromEntries(
        metrics.map((m) => {
          const cur = row.targets.find((x) => x.metric === m);
          return [
            m,
            { value: cur ? String(Number(cur.target)) : '', currency: cur?.currency ?? 'UZS' },
          ];
        }),
      ),
    );
  }, [row]); // eslint-disable-line react-hooks/exhaustive-deps
  const save = useCrmMutation(() =>
    api('/kpi/targets', {
      method: 'PUT',
      body: {
        userId: row!.user.id,
        period,
        targets: metrics.map((m) => ({
          metric: m,
          value: values[m]?.value || null,
          currency: values[m]?.currency ?? 'UZS',
        })),
      },
    }),
  );
  return (
    <Dialog open={Boolean(row)} onOpenChange={(o) => (!o ? onClose() : undefined)}>
      <DialogContent
        title={row ? t('targetsTitle', { period, name: row.user.name }) : ''}
        description={t('targetsHint')}
      >
        <form
          className="grid gap-4"
          onSubmit={async (e) => {
            e.preventDefault();
            try {
              await save.mutateAsync(undefined);
              toast.success(t('targetsSaved'));
              onClose();
            } catch (err) {
              toast.error(errorMessage(err));
            }
          }}
        >
          {metrics.map((m) => (
            <div key={m} className={cn('grid gap-2', m === 'REVENUE' && 'grid-cols-[1fr_6rem]')}>
              <Field label={t(`metric.${m}`)} htmlFor={`tg-${m}`}>
                {m === 'REVENUE' ? (
                  <MoneyInput
                    id={`tg-${m}`}
                    value={values[m]?.value ?? ''}
                    onChange={(e) =>
                      setValues((s) => ({ ...s, [m]: { ...s[m]!, value: e.target.value } }))
                    }
                  />
                ) : (
                  <Input
                    id={`tg-${m}`}
                    type="number"
                    min={0}
                    value={values[m]?.value ?? ''}
                    onChange={(e) =>
                      setValues((s) => ({
                        ...s,
                        [m]: { value: e.target.value, currency: 'UZS' },
                      }))
                    }
                  />
                )}
              </Field>
              {m === 'REVENUE' ? (
                <Field label="Валюта" htmlFor="tg-cur">
                  <NativeSelect
                    id="tg-cur"
                    value={values[m]?.currency ?? 'UZS'}
                    onChange={(e) =>
                      setValues((s) => ({ ...s, [m]: { ...s[m]!, currency: e.target.value } }))
                    }
                  >
                    <option>UZS</option>
                    <option>USD</option>
                  </NativeSelect>
                </Field>
              ) : null}
            </div>
          ))}
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

function TargetsCell({ row }: { row: KpiRowDto }) {
  const t = useTranslations('kpi');
  if (row.targets.length === 0)
    return <span className="text-xs text-muted-foreground">{t('noTargets')}</span>;
  return (
    <ul className="grid gap-0.5 text-xs text-muted-foreground">
      {row.targets.map((x) => (
        <li key={x.metric}>
          {targetText(x, t)} · <span className="text-foreground">{x.pct ?? '—'}%</span>
        </li>
      ))}
    </ul>
  );
}

/** KPI сотрудников (ТЗ §28–31): таблица по ролям за месяц и цели. */
export function KpiPage() {
  const t = useTranslations('kpi');
  const can = useCan();
  const me = useMe();
  const [period, setPeriod] = useState(currentMonth());
  const all = useKpi(period);
  const groups = useMemo(
    () =>
      (['MANAGER', 'ROP', 'EXECUTOR'] as KpiGroup[]).filter((g) =>
        (all.data ?? []).some((r) => r.role === g),
      ),
    [all.data],
  );
  const [group, setGroup] = useState<KpiGroup | null>(null);
  const active = group && groups.includes(group) ? group : (groups[0] ?? 'MANAGER');
  const rows = (all.data ?? []).filter((r) => r.role === active);
  const [editing, setEditing] = useState<KpiRowDto | null>(null);
  const canSet = (r: KpiRowDto) =>
    can('kpi.target.manage') && (can('kpi.target.manage', 'ALL') || r.user.id !== me.id);

  return (
    <>
      <PageHeader
        title={t('title')}
        description={t('subtitle')}
        actions={
          <Input
            type="month"
            aria-label={t('period')}
            className="w-44"
            value={period}
            onChange={(e) => e.target.value && setPeriod(e.target.value)}
          />
        }
      />
      {groups.length > 1 ? (
        <Tabs
          items={groups.map((g) => ({ key: g, label: t(`groups.${g}`) }))}
          value={active}
          onChange={(k) => setGroup(k as KpiGroup)}
        />
      ) : null}
      <Card>
        {all.isPending ? (
          <TableSkeleton />
        ) : all.isError ? (
          <ErrorState error={all.error} onRetry={() => all.refetch()} />
        ) : rows.length === 0 ? (
          <EmptyState icon={Target} title={t('empty')} text={t('emptyText')} />
        ) : (
          <div className="overflow-x-auto">
            <Table>
              <THead>
                <tr>
                  <TH>{t('employee')}</TH>
                  {active === 'MANAGER' ? (
                    <>
                      <TH className="text-right">{t('m.leads')}</TH>
                      <TH className="text-right">{t('m.meetings')}</TH>
                      <TH className="text-right">{t('m.proposals')}</TH>
                      <TH className="text-right">{t('m.contracts')}</TH>
                      <TH className="text-right">{t('m.orders')}</TH>
                      <TH className="text-right">{t('m.revenue')}</TH>
                      <TH className="text-right">{t('m.avgCheck')}</TH>
                      <TH className="text-right">{t('m.conversion')}</TH>
                    </>
                  ) : active === 'ROP' ? (
                    <>
                      <TH className="text-right">{t('m.teamRevenue')}</TH>
                      <TH className="text-right">{t('m.orders')}</TH>
                      <TH className="text-right">{t('m.avgCheck')}</TH>
                      <TH className="text-right">{t('m.conversion')}</TH>
                      <TH className="text-right">{t('m.managers')}</TH>
                      <TH>{t('m.planPct')}</TH>
                      <TH className="text-right">{t('m.margin')}</TH>
                      <TH className="text-right">{t('m.projects')}</TH>
                      <TH className="text-right">{t('m.overdue')}</TH>
                    </>
                  ) : (
                    <>
                      <TH className="text-right">{t('m.tasks')}</TH>
                      <TH className="text-right">{t('m.done')}</TH>
                      <TH className="text-right">{t('m.overdueCount')}</TH>
                      <TH className="text-right">{t('m.avgHours')}</TH>
                      <TH className="text-right">{t('m.completion')}</TH>
                      <TH className="text-right">{t('m.reworks')}</TH>
                    </>
                  )}
                  <TH>{t('kpi')}</TH>
                  <TH />
                </tr>
              </THead>
              <TBody>
                {rows.map((r) => (
                  <TR key={r.user.id}>
                    <TD className="min-w-56">
                      <span className="font-medium">{r.user.name}</span>
                      <span className="block text-xs text-muted-foreground">
                        {r.team?.name ?? ''}
                      </span>
                      <TargetsCell row={r} />
                    </TD>
                    {r.manager ? (
                      <>
                        <TD className="text-right tabular-nums">
                          {r.manager.leads}
                          <span className="block text-xs text-muted-foreground">
                            {t('m.processedLeads')}: {r.manager.processedLeads}
                          </span>
                        </TD>
                        <TD className="text-right tabular-nums">{r.manager.meetings}</TD>
                        <TD className="text-right tabular-nums">{r.manager.proposals}</TD>
                        <TD className="text-right tabular-nums">{r.manager.contracts}</TD>
                        <TD className="text-right tabular-nums">{r.manager.orders}</TD>
                        <TD className="whitespace-nowrap text-right tabular-nums">
                          {money(r.manager.revenueUzs)}
                        </TD>
                        <TD className="whitespace-nowrap text-right tabular-nums">
                          {money(r.manager.avgCheckUzs)}
                        </TD>
                        <TD className="text-right tabular-nums">
                          {r.manager.conversionPct ? `${r.manager.conversionPct}%` : '—'}
                        </TD>
                      </>
                    ) : r.rop ? (
                      <>
                        <TD className="whitespace-nowrap text-right tabular-nums">
                          {money(r.rop.teamRevenueUzs)}
                        </TD>
                        <TD className="text-right tabular-nums">{r.rop.orders}</TD>
                        <TD className="whitespace-nowrap text-right tabular-nums">
                          {money(r.rop.avgCheckUzs)}
                        </TD>
                        <TD className="text-right tabular-nums">
                          {r.rop.conversionPct ? `${r.rop.conversionPct}%` : '—'}
                        </TD>
                        <TD className="text-right tabular-nums">{r.rop.managers}</TD>
                        <TD>
                          <PctBar pct={r.rop.planPct} />
                          <span className="text-xs text-muted-foreground">
                            {t('m.plan')}: {moneyShort(r.rop.planUzs)}
                          </span>
                        </TD>
                        <TD className="text-right tabular-nums">
                          {r.rop.marginPct ? `${r.rop.marginPct}%` : '—'}
                        </TD>
                        <TD className="text-right tabular-nums">{r.rop.projects}</TD>
                        <TD className="text-right text-xs">
                          {r.rop.overdueTasks} {t('m.overdueTasks')}
                          <span className="block">
                            {r.rop.overdueProjects} {t('m.overdueProjects')}
                          </span>
                        </TD>
                      </>
                    ) : r.executor ? (
                      <>
                        <TD className="text-right tabular-nums">{r.executor.tasks}</TD>
                        <TD className="text-right tabular-nums">{r.executor.done}</TD>
                        <TD
                          className={cn(
                            'text-right tabular-nums',
                            r.executor.overdue > 0 && 'text-danger',
                          )}
                        >
                          {r.executor.overdue}
                        </TD>
                        <TD className="text-right tabular-nums">{r.executor.avgHours ?? '—'}</TD>
                        <TD className="text-right tabular-nums">
                          {r.executor.completionPct ? `${r.executor.completionPct}%` : '—'}
                        </TD>
                        <TD className="text-right tabular-nums">{r.executor.reworks}</TD>
                      </>
                    ) : null}
                    <TD>
                      <PctBar pct={r.kpiPct} />
                    </TD>
                    <TD className="text-right">
                      {canSet(r) ? (
                        <Button size="sm" variant="outline" onClick={() => setEditing(r)}>
                          <Target /> {t('setTargets')}
                        </Button>
                      ) : null}
                    </TD>
                  </TR>
                ))}
              </TBody>
            </Table>
          </div>
        )}
      </Card>
      <TargetsDialog row={editing} period={period} onClose={() => setEditing(null)} />
    </>
  );
}

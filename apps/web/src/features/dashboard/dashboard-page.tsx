'use client';

import type { KpiRowDto, MyKpiDto, TargetProgressDto } from '@fluggi/contracts';
import { Clock, LogIn, LogOut } from 'lucide-react';
import { useTranslations } from 'next-intl';
import Link from 'next/link';
import { toast } from 'sonner';
import { PeriodSelect, usePeriod } from '@/components/layout/period-select';
import { PageHeader } from '@/components/shared/page-header';
import { ErrorState } from '@/components/shared/states';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { TBody, TD, TH, THead, TR, Table } from '@/components/ui/table';
import { useCrmMutation } from '@/features/crm/api';
import { useAttendanceToday, useDashboard } from '@/features/people/api';
import { PctBar } from '@/features/people/kpi-page';
import { api, errorMessage } from '@/lib/api-client';
import { money, moneyShort } from '@/lib/format';
import { useCan, useMe } from '@/lib/me-context';
import { cn } from '@/lib/utils';

function Stat({
  label,
  value,
  hint,
  href,
  tone,
}: {
  label: string;
  value: string | number;
  hint?: React.ReactNode;
  href?: string;
  tone?: 'danger';
}) {
  const body = (
    <Card className={cn('grid h-full content-start gap-1 p-4', href && 'hover:bg-muted/40')}>
      <p className="text-sm text-muted-foreground">{label}</p>
      <p
        className={cn(
          'text-2xl font-semibold tracking-tight tabular-nums',
          tone === 'danger' && 'text-danger',
        )}
      >
        {value}
      </p>
      {hint ? <div className="text-xs text-muted-foreground">{hint}</div> : null}
    </Card>
  );
  return href ? (
    <Link href={href} className="block">
      {body}
    </Link>
  ) : (
    body
  );
}

/** Отметка прихода/ухода (ТЗ §35) прямо на главной. */
function AttendanceCard() {
  const t = useTranslations('attendance');
  const today = useAttendanceToday();
  const check = useCrmMutation((kind: 'in' | 'out') =>
    api(`/attendance/check-${kind}`, { method: 'POST', body: {} }),
  );
  if (today.isPending) return <Skeleton className="h-28" />;
  if (today.isError) return null;
  const d = today.data;
  const r = d.record;
  const run = async (kind: 'in' | 'out') => {
    try {
      await check.mutateAsync(kind);
      toast.success(kind === 'in' ? t('checkedIn') : t('checkedOut'));
    } catch (err) {
      toast.error(errorMessage(err));
    }
  };
  return (
    <Card className="grid content-start gap-3 p-4">
      <div className="flex items-center justify-between gap-3">
        <p className="flex items-center gap-2 text-sm text-muted-foreground">
          <Clock className="size-4" /> {t('today')}
        </p>
        {r ? (
          <Badge tone={r.status === 'LATE' ? 'warning' : 'success'}>
            {t(`status.${r.status}`)}
          </Badge>
        ) : null}
      </div>
      <p className="text-sm">
        {d.schedule
          ? `${t('schedule')}: ${d.schedule.name} ${d.schedule.startTime}–${d.schedule.endTime}`
          : t('noSchedule')}
        {!d.workday ? ` · ${t('dayOff')}` : ''}
      </p>
      {r?.checkIn ? (
        <p className="text-sm tabular-nums">
          {t('in')} {r.checkIn}
          {r.lateMinutes ? ` (${t('late', { minutes: r.lateMinutes })})` : ''}
          {r.checkOut ? ` · ${t('out')} ${r.checkOut}` : ''}
        </p>
      ) : null}
      {!r?.checkIn ? (
        <Button onClick={() => run('in')} loading={check.isPending} className="justify-self-start">
          <LogIn /> {t('checkIn')}
        </Button>
      ) : !r.checkOut ? (
        <Button
          variant="outline"
          onClick={() => run('out')}
          loading={check.isPending}
          className="justify-self-start"
        >
          <LogOut /> {t('checkOut')}
        </Button>
      ) : null}
    </Card>
  );
}

function revenueTarget(row: KpiRowDto): TargetProgressDto | undefined {
  return row.targets.find((x) => x.metric === 'REVENUE');
}

/** «Мой KPI за месяц»: выполнение целей и сумма KPI-бонуса, ожидаемая выплата. */
function MyKpiCard({ k }: { k: MyKpiDto }) {
  const td = useTranslations('dash');
  const tk = useTranslations('kpi');
  const fmt = (t: TargetProgressDto, v: string) =>
    t.metric === 'REVENUE' ? moneyShort(v, t.currency) : Number(v).toLocaleString('ru-RU');
  return (
    <Card>
      <CardHeader>
        <CardTitle>{td('myKpiTitle', { period: k.period })}</CardTitle>
      </CardHeader>
      <CardContent className="grid gap-6 lg:grid-cols-[1fr_20rem]">
        <div className="grid content-start gap-4">
          <div className="flex items-end gap-4">
            <p className="text-4xl font-semibold tabular-nums" data-testid="my-kpi-pct">
              {k.pct ? `${k.pct}%` : '—'}
            </p>
            <div className="flex-1 pb-2">
              <PctBar pct={k.pct} />
            </div>
          </div>
          {k.targets.length ? (
            <ul className="grid gap-2 text-sm">
              {k.targets.map((t) => (
                <li key={t.metric} className="grid grid-cols-[8rem_1fr_7rem] items-center gap-3">
                  <span className="text-muted-foreground">{tk(`metric.${t.metric}`)}</span>
                  <span className="tabular-nums">
                    {fmt(t, t.fact)} / {fmt(t, t.target)}
                  </span>
                  <PctBar pct={t.pct} />
                </li>
              ))}
            </ul>
          ) : (
            <p className="text-sm text-muted-foreground">{td('noKpiTargets')}</p>
          )}
        </div>
        <dl className="grid content-start gap-2 rounded-lg bg-muted/40 p-4 text-sm">
          <div className="flex justify-between gap-3">
            <dt className="text-muted-foreground">{td('kpiBonus')}</dt>
            <dd className="font-semibold tabular-nums" data-testid="my-kpi-bonus">
              {money(k.bonusUzs)}
            </dd>
          </div>
          <p className="-mt-1 text-xs text-muted-foreground">
            {k.bonusTarget
              ? td('kpiBonusHint', { amount: money(k.bonusTarget) })
              : td('kpiBonusNotSet')}
          </p>
          <div className="flex justify-between gap-3">
            <dt className="text-muted-foreground">{td('commissionMonth')}</dt>
            <dd className="tabular-nums">{money(k.commissionUzs)}</dd>
          </div>
          {k.baseSalary ? (
            <div className="flex justify-between gap-3">
              <dt className="text-muted-foreground">{td('baseSalary')}</dt>
              <dd className="tabular-nums">{money(k.baseSalary)}</dd>
            </div>
          ) : null}
          <div className="mt-1 flex justify-between gap-3 border-t pt-2">
            <dt className="font-medium">{td('expectedPay')}</dt>
            <dd className="font-semibold tabular-nums">{money(k.expectedUzs)}</dd>
          </div>
        </dl>
      </CardContent>
    </Card>
  );
}

/** Главный экран по ролям (ТЗ §5, §58–60). */
export function DashboardPage() {
  const t = useTranslations();
  const td = useTranslations('dash');
  const me = useMe();
  const can = useCan();
  const { preset, from, to } = usePeriod();
  const dash = useDashboard({ period: preset, from, to });
  const d = dash.data;

  return (
    <>
      <PageHeader
        title={t('dashboard.greeting', { name: me.fullName.split(' ')[0] ?? me.fullName })}
        description={t('dashboard.subtitle', {
          role: t(`roles.${me.role.code}`),
          team: me.team?.name ?? 'none',
        })}
        actions={can('dashboard.ceo') ? undefined : <PeriodSelect />}
      />
      {dash.isPending ? (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          {Array.from({ length: 8 }, (_, i) => (
            <Skeleton key={i} className="h-28" />
          ))}
        </div>
      ) : dash.isError ? (
        <Card>
          <ErrorState error={dash.error} onRetry={() => dash.refetch()} />
        </Card>
      ) : (
        <div className="grid gap-8">
          {d?.ceo ? (
            <section className="grid gap-3">
              <h2 className="font-semibold">{td('ceoTitle')}</h2>
              <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <Stat
                  label={td('revenue')}
                  value={money(d.ceo.revenueUzs)}
                  href="/finance/revenue"
                />
                <Stat label={td('profit')} value={money(d.ceo.profitUzs)} href="/finance/profit" />
                <Stat label={td('paid')} value={money(d.ceo.paidUzs)} href="/finance/payments" />
                <Stat label={td('expected')} value={money(d.ceo.expectedUzs)} />
                <Stat label={td('newLeads')} value={d.ceo.newLeads} href="/sales/leads" />
                <Stat label={td('newDeals')} value={d.ceo.newDeals} href="/sales/deals" />
                <Stat label={td('contracts')} value={d.ceo.contracts} href="/sales/contracts" />
                <Stat
                  label={td('projectsInProgress')}
                  value={d.ceo.projectsInProgress}
                  href="/projects/active"
                />
              </div>
            </section>
          ) : null}

          {d?.team ? (
            <section className="grid gap-3">
              <h2 className="font-semibold">
                {td('teamTitle', { team: d.team.team?.name ?? '' })}
              </h2>
              <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <Stat
                  label={td('teamSales')}
                  value={money(d.team.kpi.teamRevenueUzs)}
                  hint={
                    Number(d.team.kpi.planUzs) > 0 ? (
                      <>
                        {td('target')}: {money(d.team.kpi.planUzs)}
                        <PctBar pct={d.team.kpi.planPct} className="mt-1" />
                      </>
                    ) : (
                      td('noTarget')
                    )
                  }
                />
                <Stat label={td('leads')} value={d.team.leads} />
                <Stat label={td('meetings')} value={d.team.meetings} />
                <Stat label={td('proposals')} value={d.team.proposals} />
                <Stat label={td('contracts')} value={d.team.contracts} />
                <Stat label={td('payments')} value={d.team.payments} />
                <Stat label={td('avgCheck')} value={money(d.team.kpi.avgCheckUzs)} />
                <Stat
                  label={td('conversion')}
                  value={d.team.kpi.conversionPct ? `${d.team.kpi.conversionPct}%` : '—'}
                />
              </div>
              <Card>
                <CardHeader>
                  <CardTitle>{td('managersTable')}</CardTitle>
                </CardHeader>
                <div className="overflow-x-auto">
                  <Table>
                    <THead>
                      <tr>
                        <TH>{td('manager')}</TH>
                        <TH className="text-right">{td('leads')}</TH>
                        <TH className="text-right">{td('deals')}</TH>
                        <TH className="text-right">{td('sales')}</TH>
                        <TH>KPI</TH>
                      </tr>
                    </THead>
                    <TBody>
                      {d.team.managers.map((m) => (
                        <TR key={m.user.id}>
                          <TD className="font-medium">{m.user.name}</TD>
                          <TD className="text-right tabular-nums">{m.manager?.leads ?? 0}</TD>
                          <TD className="text-right tabular-nums">{m.manager?.orders ?? 0}</TD>
                          <TD className="whitespace-nowrap text-right tabular-nums">
                            {money(m.manager?.revenueUzs ?? 0)}
                          </TD>
                          <TD>
                            <PctBar pct={m.kpiPct} />
                          </TD>
                        </TR>
                      ))}
                    </TBody>
                  </Table>
                </div>
              </Card>
            </section>
          ) : null}

          {d?.own?.kpi.manager ? (
            <section className="grid gap-3">
              <h2 className="font-semibold">{td('ownTitle')}</h2>
              <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <Stat label={td('myLeads')} value={d.own.kpi.manager.leads} href="/sales/leads" />
                <Stat label={td('myDeals')} value={d.own.deals} href="/sales/deals" />
                <Stat
                  label={td('myMeetings')}
                  value={d.own.kpi.manager.meetings}
                  href="/sales/meetings"
                />
                <Stat
                  label={td('myProposals')}
                  value={d.own.kpi.manager.proposals}
                  href="/sales/proposals"
                />
                <Stat
                  label={td('mySales')}
                  value={money(d.own.kpi.manager.revenueUzs)}
                  hint={`${d.own.kpi.manager.orders} · ${td('avgCheck')} ${moneyShort(d.own.kpi.manager.avgCheckUzs)}`}
                />
                <Stat
                  label={td('myTarget')}
                  value={(() => {
                    const r = revenueTarget(d.own.kpi);
                    return r ? moneyShort(r.target, r.currency) : '—';
                  })()}
                  hint={<PctBar pct={revenueTarget(d.own.kpi)?.pct ?? null} />}
                />
                <Stat
                  label={td('myKpi')}
                  value={d.own.kpi.kpiPct ? `${d.own.kpi.kpiPct}%` : '—'}
                  href="/kpi"
                  hint={td('openKpi')}
                />
                <Stat
                  label={td('myCommission')}
                  value={money(d.own.commissionUzs)}
                  href="/finance/commissions"
                />
                <Stat
                  label={td('myTasks')}
                  value={d.own.tasksOpen}
                  href="/tasks"
                  hint={
                    d.own.tasksOverdue
                      ? td('tasksOverdue', { count: d.own.tasksOverdue })
                      : undefined
                  }
                  tone={d.own.tasksOverdue ? 'danger' : undefined}
                />
                <AttendanceCard />
              </div>
            </section>
          ) : null}

          {d?.executor ? (
            <section className="grid gap-3">
              <h2 className="font-semibold">{td('executorTitle')}</h2>
              <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <Stat label={td('projects')} value={d.executor.projects} href="/projects/active" />
                <Stat label={td('today')} value={d.executor.today} href="/tasks" />
                <Stat
                  label={td('overdue')}
                  value={d.executor.overdue}
                  href="/tasks"
                  tone={d.executor.overdue ? 'danger' : undefined}
                />
                <Stat label={td('inProgress')} value={d.executor.inProgress} href="/tasks" />
                <Stat label={td('done')} value={d.executor.done} />
                <AttendanceCard />
              </div>
            </section>
          ) : null}

          {d?.myKpi ? <MyKpiCard k={d.myKpi} /> : null}

          {!d?.own && !d?.executor ? (
            <section className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
              <AttendanceCard />
            </section>
          ) : null}

          {!d?.ceo && !d?.team && !d?.own && !d?.executor ? (
            <Card>
              <CardContent className="pt-5 text-sm text-muted-foreground">
                {t('dashboard.accessText')}
              </CardContent>
            </Card>
          ) : null}
        </div>
      )}
    </>
  );
}

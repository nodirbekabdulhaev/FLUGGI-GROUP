'use client';

import type {
  AnalyticsQuery,
  BreakdownRowDto,
  ClientHealthLevel,
  ClientAnalyticsQuery,
  Granularity,
} from '@fluggi/contracts';
import {
  AlertTriangle,
  CheckCircle2,
  CircleSlash,
  Clock,
  TrendingDown,
  TrendingUp,
} from 'lucide-react';
import Link from 'next/link';
import { usePathname, useRouter, useSearchParams } from 'next/navigation';
import { useFormatter, useTranslations } from 'next-intl';
import { useState } from 'react';
import { BarList, LineChart, SERIES, StackedColumns } from '@/components/charts/charts';
import { PeriodSelect, usePeriod } from '@/components/layout/period-select';
import { PageHeader } from '@/components/shared/page-header';
import { Pagination } from '@/components/shared/pagination';
import { EmptyState, ErrorState, TableSkeleton } from '@/components/shared/states';
import { Tabs } from '@/components/shared/tabs';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { NativeSelect } from '@/components/ui/input';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { useUsers } from '@/features/team/api';
import { money, moneyShort } from '@/lib/format';
import { useCan, useMe } from '@/lib/me-context';
import { cn } from '@/lib/utils';
import {
  useBreakdown,
  useClientAnalytics,
  useForecast,
  useFunnel,
  useLosses,
  useSales,
} from './api';
import { ExportMenu } from './export-menu';

const TABS = ['sales', 'funnel', 'sources', 'services', 'losses', 'forecast', 'clients'] as const;
type Tab = (typeof TABS)[number];

const num = (v: number) => new Intl.NumberFormat('ru-RU').format(v);
const pct = (v: number) =>
  `${new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 1 }).format(v)}%`;

/** Подпись точки графика: день — «7 окт», неделя — «с 5 окт», месяц — «окт 2026». */
function pointLabel(key: string, g: Granularity) {
  if (g === 'month')
    return new Date(`${key}-01T00:00:00Z`).toLocaleDateString('ru-RU', {
      month: 'short',
      year: 'numeric',
      timeZone: 'UTC',
    });
  const d = new Date(`${key}T00:00:00Z`).toLocaleDateString('ru-RU', {
    day: 'numeric',
    month: 'short',
    timeZone: 'UTC',
  });
  return g === 'week' ? `с ${d}` : d;
}

const monthLabel = (m: string) =>
  new Date(`${m}-01T00:00:00Z`).toLocaleDateString('ru-RU', {
    month: 'long',
    year: 'numeric',
    timeZone: 'UTC',
  });

function Stat({ label, value, change }: { label: string; value: string; change?: number | null }) {
  const t = useTranslations('analytics');
  return (
    <Card>
      <CardContent className="grid gap-1 pt-5">
        <p className="text-sm text-muted-foreground">{label}</p>
        <p className="text-2xl font-semibold tabular-nums tracking-tight">{value}</p>
        {change === undefined ? null : change === null ? (
          <p className="text-xs text-muted-foreground">{t('noCompare')}</p>
        ) : (
          <p
            className={cn(
              'flex items-center gap-1 text-xs',
              change >= 0 ? 'text-success' : 'text-danger',
            )}
          >
            {change >= 0 ? (
              <TrendingUp className="size-3.5" />
            ) : (
              <TrendingDown className="size-3.5" />
            )}
            {change > 0 ? '+' : ''}
            {change}% {t('vsPrev')}
          </p>
        )}
      </CardContent>
    </Card>
  );
}

function Loading<T>({
  q,
  children,
}: {
  q: { isPending: boolean; isError: boolean; error: unknown; refetch: () => unknown; data?: T };
  children: (data: T) => React.ReactNode;
}) {
  if (q.isPending)
    return (
      <Card>
        <TableSkeleton rows={6} cols={3} />
      </Card>
    );
  if (q.isError)
    return (
      <Card>
        <ErrorState error={q.error} onRetry={() => q.refetch()} />
      </Card>
    );
  return <>{children(q.data as T)}</>;
}

function SalesTab({ p }: { p: AnalyticsQuery }) {
  const t = useTranslations('analytics');
  const sales = useSales(p);
  const [showTable, setShowTable] = useState(false);
  return (
    <Loading q={sales}>
      {(s) => (
        <div className="grid gap-4">
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Stat
              label={t('revenue')}
              value={moneyShort(s.totals.revenue)}
              change={s.change.revenue}
            />
            <Stat
              label={t('collected')}
              value={moneyShort(s.totals.collected)}
              change={s.change.collected}
            />
            <Stat label={t('won')} value={num(s.totals.won)} change={s.change.won} />
            <Stat label={t('leads')} value={num(s.totals.leads)} change={s.change.leads} />
          </div>
          <Card>
            <CardHeader>
              <CardTitle>{t('salesTitle')}</CardTitle>
              <CardDescription>
                {t('salesText', { avg: moneyShort(s.totals.avgCheck) })}
              </CardDescription>
            </CardHeader>
            <CardContent className="grid gap-3">
              <LineChart
                labels={s.points.map((x) => x.key)}
                series={[
                  {
                    key: 'revenue',
                    label: t('revenue'),
                    color: SERIES.blue,
                    values: s.points.map((x) => Number(x.revenue)),
                  },
                  {
                    key: 'collected',
                    label: t('collected'),
                    color: SERIES.orange,
                    values: s.points.map((x) => Number(x.collected)),
                  },
                ]}
                format={(v) => money(v)}
                formatLabel={(k) => pointLabel(k, s.granularity)}
              />
              <button
                type="button"
                className="justify-self-start text-xs text-muted-foreground underline-offset-2 hover:underline"
                onClick={() => setShowTable((v) => !v)}
              >
                {showTable ? t('hideTable') : t('showTable')}
              </button>
              {showTable ? (
                <div className="max-h-80 overflow-y-auto">
                  <Table>
                    <THead>
                      <TR>
                        <TH>{t('date')}</TH>
                        <TH className="text-right">{t('revenue')}</TH>
                        <TH className="text-right">{t('collected')}</TH>
                        <TH className="text-right">{t('won')}</TH>
                        <TH className="text-right">{t('leads')}</TH>
                      </TR>
                    </THead>
                    <TBody>
                      {s.points.map((x) => (
                        <TR key={x.key}>
                          <TD>{pointLabel(x.key, s.granularity)}</TD>
                          <TD className="whitespace-nowrap text-right tabular-nums">
                            {money(x.revenue)}
                          </TD>
                          <TD className="whitespace-nowrap text-right tabular-nums">
                            {money(x.collected)}
                          </TD>
                          <TD className="whitespace-nowrap text-right tabular-nums">{x.won}</TD>
                          <TD className="whitespace-nowrap text-right tabular-nums">{x.leads}</TD>
                        </TR>
                      ))}
                    </TBody>
                  </Table>
                </div>
              ) : null}
            </CardContent>
          </Card>
        </div>
      )}
    </Loading>
  );
}

function FunnelTab({ p }: { p: AnalyticsQuery }) {
  const t = useTranslations('analytics');
  const funnel = useFunnel(p);
  return (
    <Loading q={funnel}>
      {(steps) => (
        <Card>
          <CardHeader>
            <CardTitle>{t('funnelTitle')}</CardTitle>
            <CardDescription>{t('funnelText')}</CardDescription>
          </CardHeader>
          <CardContent>
            {steps[0]!.count === 0 ? (
              <EmptyState title={t('empty')} />
            ) : (
              <BarList
                rows={steps.map((s, i) => ({
                  key: s.key,
                  label: s.label,
                  value: s.count,
                  display: num(s.count),
                  sub:
                    i === 0 ? undefined : t('fromPrev', { prev: s.fromPrev, start: s.fromStart }),
                }))}
              />
            )}
          </CardContent>
        </Card>
      )}
    </Loading>
  );
}

function BreakdownTab({ p, by }: { p: AnalyticsQuery; by: 'sources' | 'services' }) {
  const t = useTranslations('analytics');
  const data = useBreakdown(by, p);
  return (
    <Loading q={data}>
      {(rows: BreakdownRowDto[]) =>
        rows.length === 0 ? (
          <Card>
            <EmptyState title={t('empty')} />
          </Card>
        ) : (
          <div className="grid gap-4 lg:grid-cols-[2fr_3fr]">
            <Card>
              <CardHeader>
                <CardTitle>{t(by === 'sources' ? 'sourcesTitle' : 'servicesTitle')}</CardTitle>
                <CardDescription>{t('revenueByLeads')}</CardDescription>
              </CardHeader>
              <CardContent>
                <BarList
                  rows={rows.map((r) => ({
                    key: r.id ?? 'none',
                    label: r.name,
                    value: Number(r.revenue),
                    display: moneyShort(r.revenue),
                  }))}
                />
              </CardContent>
            </Card>
            <Card className="overflow-x-auto">
              <Table>
                <THead>
                  <TR>
                    <TH>{t(by === 'sources' ? 'source' : 'service')}</TH>
                    <TH className="text-right">{t('leads')}</TH>
                    <TH className="text-right">{t('deals')}</TH>
                    <TH className="text-right">{t('paidDeals')}</TH>
                    <TH className="text-right">{t('conversion')}</TH>
                    <TH className="text-right">{t('avgCheck')}</TH>
                  </TR>
                </THead>
                <TBody>
                  {rows.map((r) => (
                    <TR key={r.id ?? 'none'}>
                      <TD className="font-medium">{r.name}</TD>
                      <TD className="whitespace-nowrap text-right tabular-nums">{r.leads}</TD>
                      <TD className="whitespace-nowrap text-right tabular-nums">{r.deals}</TD>
                      <TD className="whitespace-nowrap text-right tabular-nums">{r.won}</TD>
                      <TD className="whitespace-nowrap text-right tabular-nums">
                        {pct(r.conversion)}
                      </TD>
                      <TD className="whitespace-nowrap text-right tabular-nums">
                        {moneyShort(r.avgCheck)}
                      </TD>
                    </TR>
                  ))}
                </TBody>
              </Table>
            </Card>
          </div>
        )
      }
    </Loading>
  );
}

function LossesTab({ p }: { p: AnalyticsQuery }) {
  const t = useTranslations('analytics');
  const data = useLosses(p);
  return (
    <Loading q={data}>
      {(rows) => (
        <Card>
          <CardHeader>
            <CardTitle>{t('lossesTitle')}</CardTitle>
            <CardDescription>{t('lossesText')}</CardDescription>
          </CardHeader>
          <CardContent>
            {rows.length === 0 ? (
              <EmptyState title={t('noLosses')} />
            ) : (
              <BarList
                color={SERIES.orange}
                rows={rows.map((r) => ({
                  key: r.id ?? 'none',
                  label: r.name,
                  value: r.leads + r.deals,
                  display: num(r.leads + r.deals),
                  sub: t('lossSub', {
                    leads: r.leads,
                    deals: r.deals,
                    amount: moneyShort(r.amount),
                  }),
                }))}
              />
            )}
          </CardContent>
        </Card>
      )}
    </Loading>
  );
}

function ForecastTab({ p }: { p: AnalyticsQuery }) {
  const t = useTranslations('analytics');
  const data = useForecast({ teamId: p.teamId, userId: p.userId });
  return (
    <Loading q={data}>
      {(f) => (
        <div className="grid gap-4">
          <div className="grid gap-4 sm:grid-cols-3">
            <Stat label={t('forecastMonth')} value={moneyShort(f.months[0]!.total)} />
            <Stat
              label={t('planMonth')}
              value={f.months[0]!.plan ? moneyShort(f.months[0]!.plan) : t('noPlan')}
            />
            <Stat label={t('pipeline')} value={moneyShort(f.pipeline)} />
          </div>
          <Card>
            <CardHeader>
              <CardTitle>{t('forecastTitle')}</CardTitle>
              <CardDescription>{t('forecastText')}</CardDescription>
            </CardHeader>
            <CardContent className="grid gap-4">
              <StackedColumns
                categories={f.months.map((m) => ({ key: m.month, label: monthLabel(m.month) }))}
                segments={[
                  { key: 'collected', label: t('collected'), color: SERIES.blue },
                  { key: 'scheduled', label: t('scheduled'), color: SERIES.orange },
                  { key: 'weighted', label: t('weighted'), color: SERIES.aqua },
                ]}
                values={f.months.map((m) => [
                  Number(m.collected),
                  Number(m.scheduled),
                  Number(m.weighted),
                ])}
                marker={f.months.map((m) => (m.plan ? Number(m.plan) : null))}
                markerLabel={t('plan')}
                format={(v) => money(v)}
              />
              <Table>
                <THead>
                  <TR>
                    <TH>{t('month')}</TH>
                    <TH className="text-right">{t('collected')}</TH>
                    <TH className="text-right">{t('scheduled')}</TH>
                    <TH className="text-right">{t('weighted')}</TH>
                    <TH className="text-right">{t('total')}</TH>
                    <TH className="text-right">{t('plan')}</TH>
                  </TR>
                </THead>
                <TBody>
                  {f.months.map((m) => (
                    <TR key={m.month}>
                      <TD className="capitalize">{monthLabel(m.month)}</TD>
                      <TD className="whitespace-nowrap text-right tabular-nums">
                        {moneyShort(m.collected)}
                      </TD>
                      <TD className="whitespace-nowrap text-right tabular-nums">
                        {moneyShort(m.scheduled)}
                      </TD>
                      <TD className="whitespace-nowrap text-right tabular-nums">
                        {moneyShort(m.weighted)}
                      </TD>
                      <TD className="whitespace-nowrap text-right font-medium tabular-nums">
                        {moneyShort(m.total)}
                      </TD>
                      <TD className="whitespace-nowrap text-right tabular-nums">
                        {m.plan ? moneyShort(m.plan) : '—'}
                      </TD>
                    </TR>
                  ))}
                </TBody>
              </Table>
            </CardContent>
          </Card>
        </div>
      )}
    </Loading>
  );
}

export const HEALTH_TONE: Record<ClientHealthLevel, 'success' | 'warning' | 'danger' | 'neutral'> =
  {
    HEALTHY: 'success',
    ATTENTION: 'warning',
    RISK: 'danger',
    LOST: 'neutral',
  };
const HEALTH_ICON = {
  HEALTHY: CheckCircle2,
  ATTENTION: Clock,
  RISK: AlertTriangle,
  LOST: CircleSlash,
};

/** Здоровье клиента: цвет статуса всегда с иконкой и подписью. */
export function HealthBadge({ level, score }: { level: ClientHealthLevel; score?: number }) {
  const t = useTranslations('analytics.health');
  const Icon = HEALTH_ICON[level];
  return (
    <Badge tone={HEALTH_TONE[level]}>
      <Icon className="size-3" />
      {t(level)}
      {score !== undefined && level !== 'LOST' ? ` · ${score}` : ''}
    </Badge>
  );
}

function ClientsTab() {
  const t = useTranslations('analytics');
  const format = useFormatter();
  const can = useCan();
  const [q, setQ] = useState<ClientAnalyticsQuery>({ sort: 'ltv', page: 1, pageSize: 25 });
  const data = useClientAnalytics(q);
  return (
    <Loading q={data}>
      {(d) => (
        <div className="grid gap-4">
          <div className="grid gap-4 sm:grid-cols-3">
            <Stat label={t('clients')} value={num(d.summary.clients)} />
            <Stat label={t('avgLtv')} value={moneyShort(d.summary.avgLtv)} />
            <Stat label={t('repeatRate')} value={pct(d.summary.repeatRate)} />
          </div>
          <Card>
            <CardHeader className="flex flex-row flex-wrap items-start justify-between gap-3">
              <div>
                <CardTitle>{t('clientsTitle')}</CardTitle>
                <CardDescription>{t('clientsText')}</CardDescription>
              </div>
              {can('export.run') ? <ExportMenu entity="clients" /> : null}
            </CardHeader>
            <CardContent className="grid gap-4">
              <div className="flex flex-wrap gap-2">
                <button
                  type="button"
                  onClick={() => setQ((s) => ({ ...s, health: undefined, page: 1 }))}
                  className={cn(
                    'rounded-full border px-3 py-1 text-xs',
                    !q.health && 'border-foreground bg-muted',
                  )}
                >
                  {t('allClients')} · {d.summary.clients}
                </button>
                {(['RISK', 'ATTENTION', 'HEALTHY', 'LOST'] as const).map((l) => (
                  <button
                    key={l}
                    type="button"
                    aria-pressed={q.health === l}
                    onClick={() => setQ((s) => ({ ...s, health: l, page: 1 }))}
                    className={cn('rounded-full', q.health === l && 'ring-2 ring-foreground/40')}
                  >
                    <HealthBadge level={l} />
                    <span className="ml-1 text-xs text-muted-foreground">
                      {d.summary.byHealth[l]}
                    </span>
                  </button>
                ))}
                <NativeSelect
                  aria-label={t('sort')}
                  className="ml-auto h-8 w-auto text-xs"
                  value={q.sort}
                  onChange={(e) =>
                    setQ((s) => ({
                      ...s,
                      sort: e.target.value as ClientAnalyticsQuery['sort'],
                      page: 1,
                    }))
                  }
                >
                  <option value="ltv">{t('sortLtv')}</option>
                  <option value="health">{t('sortHealth')}</option>
                  <option value="lastPayment">{t('sortLastPayment')}</option>
                </NativeSelect>
              </div>
            </CardContent>
            {d.items.length === 0 ? (
              <EmptyState title={t('empty')} />
            ) : (
              <>
                <div className="overflow-x-auto">
                  <Table>
                    <THead>
                      <TR>
                        <TH>{t('client')}</TH>
                        <TH>{t('health.title')}</TH>
                        <TH className="text-right">LTV</TH>
                        <TH className="text-right">{t('paidDeals')}</TH>
                        <TH className="text-right">{t('avgCheck')}</TH>
                        <TH>{t('lastContact')}</TH>
                        <TH>{t('owner')}</TH>
                      </TR>
                    </THead>
                    <TBody>
                      {d.items.map((c) => (
                        <TR key={c.id}>
                          <TD className="font-medium">
                            <Link href={`/clients/${c.id}`} className="hover:underline">
                              {c.name}
                            </Link>
                          </TD>
                          <TD>
                            <div className="grid gap-0.5">
                              <span>
                                <HealthBadge level={c.health.level} score={c.health.score} />
                              </span>
                              {c.health.reasons.length ? (
                                <span className="max-w-64 text-xs text-muted-foreground">
                                  {c.health.reasons.join(' · ')}
                                </span>
                              ) : null}
                            </div>
                          </TD>
                          <TD className="whitespace-nowrap text-right tabular-nums">
                            {moneyShort(c.ltv)}
                          </TD>
                          <TD className="whitespace-nowrap text-right tabular-nums">
                            {c.paidDeals}
                          </TD>
                          <TD className="whitespace-nowrap text-right tabular-nums">
                            {c.paidDeals ? moneyShort(c.avgCheck) : '—'}
                          </TD>
                          <TD className="whitespace-nowrap text-muted-foreground">
                            {c.lastContactAt ? format.relativeTime(new Date(c.lastContactAt)) : '—'}
                          </TD>
                          <TD>{c.owner.name}</TD>
                        </TR>
                      ))}
                    </TBody>
                  </Table>
                </div>
                <Pagination
                  page={d.page}
                  pageSize={d.pageSize}
                  total={d.total}
                  onPage={(page) => setQ((s) => ({ ...s, page }))}
                />
              </>
            )}
          </Card>
        </div>
      )}
    </Loading>
  );
}

/** Аналитика (ТЗ §39–43). Вкладка — в адресе (?tab=…), период — общий (?period=…). */
export function AnalyticsPage() {
  const t = useTranslations('analytics');
  const router = useRouter();
  const pathname = usePathname();
  const params = useSearchParams();
  const me = useMe();
  const can = useCan();
  const { preset, from, to } = usePeriod();
  const raw = params.get('tab');
  const tab: Tab = (TABS as readonly string[]).includes(raw ?? '') ? (raw as Tab) : 'sales';
  const wide = me.permissions['analytics.read'] !== 'OWN';
  const [userId, setUserId] = useState('');
  const users = useUsers(
    { pageSize: 100, status: 'ACTIVE', roleCode: 'MANAGER' },
    wide && can('employee.read'),
  );
  const p: AnalyticsQuery = { period: preset, from, to, userId: userId || undefined };
  const setTab = (k: string) => {
    const q = new URLSearchParams(params.toString());
    q.set('tab', k);
    router.replace(`${pathname}?${q.toString()}`, { scroll: false });
  };

  return (
    <>
      <PageHeader
        title={t('title')}
        description={t('subtitle')}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            {wide && users.data ? (
              <NativeSelect
                aria-label={t('employee')}
                className="h-9 w-auto"
                value={userId}
                onChange={(e) => setUserId(e.target.value)}
              >
                <option value="">{t('allEmployees')}</option>
                {users.data.items.map((u) => (
                  <option key={u.id} value={u.id}>
                    {u.fullName}
                  </option>
                ))}
              </NativeSelect>
            ) : null}
            {can('dashboard.ceo') || tab === 'forecast' || tab === 'clients' ? null : (
              <PeriodSelect />
            )}
          </div>
        }
      />
      <Tabs
        items={TABS.map((k) => ({ key: k, label: t(`tabs.${k}`) }))}
        value={tab}
        onChange={setTab}
      />
      {tab === 'sales' ? <SalesTab p={p} /> : null}
      {tab === 'funnel' ? <FunnelTab p={p} /> : null}
      {tab === 'sources' ? <BreakdownTab p={p} by="sources" /> : null}
      {tab === 'services' ? <BreakdownTab p={p} by="services" /> : null}
      {tab === 'losses' ? <LossesTab p={p} /> : null}
      {tab === 'forecast' ? <ForecastTab p={p} /> : null}
      {tab === 'clients' ? <ClientsTab /> : null}
    </>
  );
}

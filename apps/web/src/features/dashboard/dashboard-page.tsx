'use client';

import { CheckCircle2, Clock } from 'lucide-react';
import { useTranslations } from 'next-intl';
import Link from 'next/link';
import { Suspense } from 'react';
import { usePeriod } from '@/components/layout/period-select';
import { PageHeader } from '@/components/shared/page-header';
import { ErrorState, TableSkeleton } from '@/components/shared/states';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { useTeams } from '@/features/team/api';
import { useCan, useMe } from '@/lib/me-context';
import { visibleNavigation } from '@/lib/navigation';

function SelectedPeriod() {
  const t = useTranslations();
  const { preset, from, to } = usePeriod();
  const label = preset === 'custom' && from && to ? `${from} — ${to}` : t(`period.${preset}`);
  return (
    <p className="text-xs text-muted-foreground">
      {t('dashboard.periodSelected', { period: label })}
    </p>
  );
}

function TeamsCard() {
  const t = useTranslations('dashboard');
  const teams = useTeams();
  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('teamTitle')}</CardTitle>
      </CardHeader>
      {teams.isPending ? (
        <TableSkeleton rows={2} cols={1} />
      ) : teams.isError ? (
        <ErrorState error={teams.error} onRetry={() => teams.refetch()} />
      ) : teams.data.length === 0 ? (
        <CardContent className="text-sm text-muted-foreground">{t('teamEmpty')}</CardContent>
      ) : (
        <ul className="divide-y border-t">
          {teams.data.map((team) => (
            <li key={team.id} className="flex items-center justify-between gap-3 px-5 py-3 text-sm">
              <div className="min-w-0">
                <p className="truncate font-medium">{team.name}</p>
                <p className="truncate text-xs text-muted-foreground">
                  {team.head?.fullName ?? t('noHead')}
                </p>
              </div>
              <span className="shrink-0 text-muted-foreground">
                {t('members', { count: team.membersCount })}
              </span>
            </li>
          ))}
        </ul>
      )}
    </Card>
  );
}

export function DashboardPage() {
  const t = useTranslations();
  const me = useMe();
  const can = useCan();
  const sections = visibleNavigation(me.permissions).filter((s) => s.key !== 'dashboard');

  return (
    <>
      <PageHeader
        title={t('dashboard.greeting', { name: me.fullName.split(' ')[0] ?? me.fullName })}
        description={t('dashboard.subtitle', {
          role: t(`roles.${me.role.code}`),
          team: me.team?.name ?? 'none',
        })}
      />

      <div className="grid gap-6 lg:grid-cols-3">
        {can('dashboard.ceo') || can('dashboard.team') || can('dashboard.own') ? (
          <Card className="lg:col-span-2">
            <CardHeader>
              <CardTitle>{t('dashboard.metricsTitle')}</CardTitle>
              {can('dashboard.ceo') ? (
                <Suspense>
                  <SelectedPeriod />
                </Suspense>
              ) : null}
            </CardHeader>
            <CardContent>
              <div className="flex items-start gap-3 rounded-md border border-dashed p-4 text-sm text-muted-foreground">
                <Clock className="mt-0.5 size-4 shrink-0" />
                <p>{t('dashboard.metricsPending')}</p>
              </div>
            </CardContent>
          </Card>
        ) : null}

        {can('employee.read') ? <TeamsCard /> : null}

        <Card className="lg:col-span-3">
          <CardHeader>
            <CardTitle>{t('dashboard.accessTitle')}</CardTitle>
            <CardDescription>{t('dashboard.accessText')}</CardDescription>
          </CardHeader>
          <ul className="grid border-t sm:grid-cols-2 lg:grid-cols-3">
            {sections.flatMap((section) =>
              (section.children ?? [section]).map((item) => (
                <li key={item.key} className="border-b sm:odd:border-r lg:border-r">
                  <Link
                    href={item.href}
                    className="flex items-center gap-3 px-5 py-3 text-sm hover:bg-muted/40"
                  >
                    <section.icon className="size-4 shrink-0 text-muted-foreground" />
                    <span className="flex-1 truncate">
                      {section.children ? `${t(`nav.${section.key}`)} · ` : ''}
                      {t(`nav.${item.key}`)}
                    </span>
                    {item.plannedPhase ? (
                      <Badge>{t('dashboard.plannedPhase', { phase: item.plannedPhase })}</Badge>
                    ) : (
                      <Badge tone="success">
                        <CheckCircle2 className="size-3" /> {t('dashboard.available')}
                      </Badge>
                    )}
                  </Link>
                </li>
              )),
            )}
          </ul>
        </Card>
      </div>
    </>
  );
}

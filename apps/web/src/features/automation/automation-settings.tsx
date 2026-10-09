'use client';

import { FOLLOW_UP_KINDS, type AutomationSettings, type FollowUpKind } from '@fluggi/contracts';
import { Play, Plus, Trash2 } from 'lucide-react';
import { useFormatter, useTranslations } from 'next-intl';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { ErrorState, TableSkeleton } from '@/components/shared/states';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input, NativeSelect } from '@/components/ui/input';
import { Field } from '@/components/ui/label';
import { MoneyInput } from '@/components/ui/money-input';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { ApiError, api, errorMessage } from '@/lib/api-client';
import { cn } from '@/lib/utils';
import { useAutomationSettings, useInvalidating, useJobs, useReportPreview } from './api';

function SettingsForm({ initial }: { initial: AutomationSettings }) {
  const t = useTranslations('automation');
  const tk = useTranslations('followUps.kinds');
  const [v, setV] = useState({
    largeAmountUzs: String(initial.largeAmountUzs),
    followUps: initial.followUps.map((f) => ({ days: String(f.days), kind: f.kind })),
    dailyReport: initial.dailyReport,
    weeklyReport: initial.weeklyReport,
  });
  const [errors, setErrors] = useState<Record<string, string>>({});
  useEffect(() => {
    setV({
      largeAmountUzs: String(initial.largeAmountUzs),
      followUps: initial.followUps.map((f) => ({ days: String(f.days), kind: f.kind })),
      dailyReport: initial.dailyReport,
      weeklyReport: initial.weeklyReport,
    });
  }, [initial]);
  const save = useInvalidating([['automation', 'settings']], () =>
    api<AutomationSettings>('/settings/automation', {
      method: 'PUT',
      body: {
        largeAmountUzs: Number(v.largeAmountUzs || 0),
        followUps: v.followUps.map((f) => ({ days: Number(f.days), kind: f.kind })),
        dailyReport: v.dailyReport,
        weeklyReport: v.weeklyReport,
      },
    }),
  );
  const setFollowUp = (i: number, patch: Partial<{ days: string; kind: FollowUpKind }>) =>
    setV((s) => ({
      ...s,
      followUps: s.followUps.map((f, j) => (j === i ? { ...f, ...patch } : f)),
    }));

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('settingsTitle')}</CardTitle>
        <CardDescription>{t('settingsText')}</CardDescription>
      </CardHeader>
      <CardContent>
        <form
          className="grid gap-5"
          onSubmit={async (e) => {
            e.preventDefault();
            setErrors({});
            try {
              await save.mutateAsync(undefined);
              toast.success(t('saved'));
            } catch (err) {
              if (err instanceof ApiError) setErrors(err.fieldErrors());
              toast.error(errorMessage(err));
            }
          }}
        >
          <Field
            label={t('largeAmount')}
            htmlFor="au-large"
            hint={t('largeAmountHint')}
            error={errors.largeAmountUzs}
          >
            <MoneyInput
              id="au-large"
              value={v.largeAmountUzs}
              onChange={(e) => setV((s) => ({ ...s, largeAmountUzs: e.target.value }))}
            />
          </Field>
          <fieldset className="grid gap-2">
            <legend className="mb-1 text-sm font-medium">{t('followUps')}</legend>
            <p className="text-xs text-muted-foreground">{t('followUpsHint')}</p>
            {v.followUps.map((f, i) => (
              <div key={i} className="flex items-center gap-2">
                <Input
                  type="number"
                  min={1}
                  max={730}
                  className="w-20 shrink-0"
                  aria-label={t('days')}
                  value={f.days}
                  onChange={(e) => setFollowUp(i, { days: e.target.value })}
                />
                <span className="shrink-0 text-sm text-muted-foreground">{t('daysAfter')}</span>
                <NativeSelect
                  className="min-w-0 flex-1"
                  aria-label={t('kind')}
                  value={f.kind}
                  onChange={(e) => setFollowUp(i, { kind: e.target.value as FollowUpKind })}
                >
                  {FOLLOW_UP_KINDS.map((k) => (
                    <option key={k} value={k}>
                      {tk(k)}
                    </option>
                  ))}
                </NativeSelect>
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  aria-label={t('remove')}
                  onClick={() =>
                    setV((s) => ({ ...s, followUps: s.followUps.filter((_, j) => j !== i) }))
                  }
                >
                  <Trash2 className="size-4" />
                </Button>
              </div>
            ))}
            {v.followUps.length < 10 ? (
              <div>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  onClick={() =>
                    setV((s) => ({
                      ...s,
                      followUps: [...s.followUps, { days: '30', kind: 'CONTACT' }],
                    }))
                  }
                >
                  <Plus className="size-4" /> {t('addFollowUp')}
                </Button>
              </div>
            ) : null}
          </fieldset>
          <div className="grid gap-2 text-sm">
            <label className="flex items-center gap-2">
              <input
                type="checkbox"
                checked={v.dailyReport}
                onChange={(e) => setV((s) => ({ ...s, dailyReport: e.target.checked }))}
              />
              {t('dailyReport')}
            </label>
            <label className="flex items-center gap-2">
              <input
                type="checkbox"
                checked={v.weeklyReport}
                onChange={(e) => setV((s) => ({ ...s, weeklyReport: e.target.checked }))}
              />
              {t('weeklyReport')}
            </label>
          </div>
          <div>
            <Button type="submit" loading={save.isPending} loadingText={t('saving')}>
              {t('save')}
            </Button>
          </div>
        </form>
      </CardContent>
    </Card>
  );
}

function Jobs() {
  const t = useTranslations('automation');
  const format = useFormatter();
  const jobs = useJobs();
  const run = useInvalidating([['automation', 'jobs']], (name: string) =>
    api<{ error: string | null }>(`/automation/jobs/${name}/run`, { method: 'POST' }),
  );
  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('jobsTitle')}</CardTitle>
        <CardDescription>{t('jobsText')}</CardDescription>
      </CardHeader>
      {jobs.isPending ? (
        <TableSkeleton rows={7} cols={4} />
      ) : jobs.isError ? (
        <ErrorState error={jobs.error} onRetry={() => jobs.refetch()} />
      ) : (
        <Table>
          <THead>
            <TR>
              <TH>{t('job')}</TH>
              <TH>{t('schedule')}</TH>
              <TH>{t('lastRun')}</TH>
              <TH className="w-12" />
            </TR>
          </THead>
          <TBody>
            {jobs.data.map((j) => (
              <TR key={j.name}>
                <TD className="font-medium">{j.label}</TD>
                <TD className="text-muted-foreground">{j.schedule}</TD>
                <TD>
                  {j.last ? (
                    <span className="flex flex-wrap items-center gap-2">
                      {format.dateTime(new Date(j.last.startedAt), {
                        dateStyle: 'short',
                        timeStyle: 'short',
                      })}
                      {j.last.error ? (
                        <Badge tone="danger" title={j.last.error}>
                          {t('failed')}
                        </Badge>
                      ) : j.last.finishedAt ? (
                        <Badge tone="success">{t('ok')}</Badge>
                      ) : (
                        <Badge tone="warning">{t('running')}</Badge>
                      )}
                    </span>
                  ) : (
                    <span className="text-muted-foreground">{t('never')}</span>
                  )}
                </TD>
                <TD>
                  <Button
                    variant="ghost"
                    size="sm"
                    aria-label={t('runNow', { name: j.label })}
                    title={t('runNow', { name: j.label })}
                    disabled={run.isPending}
                    onClick={async () => {
                      try {
                        const r = await run.mutateAsync(j.name);
                        if (r.error) toast.error(r.error);
                        else toast.success(t('done', { name: j.label }));
                      } catch (err) {
                        toast.error(errorMessage(err));
                      }
                    }}
                  >
                    <Play className="size-4" />
                  </Button>
                </TD>
              </TR>
            ))}
          </TBody>
        </Table>
      )}
    </Card>
  );
}

/** Предпросмотр отчёта — тот же текст, что уходит в Telegram. */
export function ReportPreview() {
  const t = useTranslations('automation');
  const [kind, setKind] = useState<'daily' | 'weekly'>('daily');
  const report = useReportPreview(kind, true);
  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('previewTitle')}</CardTitle>
        <CardDescription>{t('previewText')}</CardDescription>
      </CardHeader>
      <CardContent className="grid gap-3">
        <div className="flex gap-1">
          {(['daily', 'weekly'] as const).map((k) => (
            <Button
              key={k}
              size="sm"
              variant={kind === k ? 'default' : 'outline'}
              onClick={() => setKind(k)}
            >
              {t(k)}
            </Button>
          ))}
        </div>
        {report.isPending ? (
          <TableSkeleton rows={6} cols={1} />
        ) : report.isError ? (
          <ErrorState error={report.error} onRetry={() => report.refetch()} />
        ) : (
          <pre
            data-testid="report-preview"
            className={cn(
              'whitespace-pre-wrap rounded-md bg-muted p-4 font-sans text-sm leading-relaxed',
            )}
          >
            {report.data.text.replace(/<[^>]+>/g, '')}
          </pre>
        )}
      </CardContent>
    </Card>
  );
}

export function AutomationSettingsPage() {
  const settings = useAutomationSettings();
  return (
    <div className="grid gap-6 lg:grid-cols-2">
      {settings.isPending ? (
        <TableSkeleton rows={5} cols={1} />
      ) : settings.isError ? (
        <ErrorState error={settings.error} onRetry={() => settings.refetch()} />
      ) : (
        <SettingsForm initial={settings.data} />
      )}
      <ReportPreview />
      <div className="lg:col-span-2">
        <Jobs />
      </div>
    </div>
  );
}

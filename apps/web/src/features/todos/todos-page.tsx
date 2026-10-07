'use client';

import {
  PRIORITIES,
  RECURRENCE_FREQUENCIES,
  TODO_KINDS,
  type RecurrenceFrequency,
  type RecurringTodoDto,
  type TodoDto,
  type TodoKind,
} from '@fluggi/contracts';
import { CalendarClock, Check, Pencil, Plus, RotateCcw, Trash2 } from 'lucide-react';
import Link from 'next/link';
import { usePathname, useRouter, useSearchParams } from 'next/navigation';
import { useTranslations } from 'next-intl';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { PageHeader } from '@/components/shared/page-header';
import { Pagination } from '@/components/shared/pagination';
import { EmptyState, ErrorState, TableSkeleton } from '@/components/shared/states';
import { Tabs } from '@/components/shared/tabs';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogFooter } from '@/components/ui/dialog';
import { Input, NativeSelect } from '@/components/ui/input';
import { Field } from '@/components/ui/label';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import { ApiError, api, errorMessage } from '@/lib/api-client';
import { useCan } from '@/lib/me-context';
import { cn } from '@/lib/utils';
import { useRecurring, useTodoMutation, useTodos } from './api';
import { TodoDialog } from './todo-dialog';
import { dueLabel, KIND_ICON, PRIORITY_DOT } from './todo-dock';

type View = 'mine' | 'assigned' | 'all' | 'recurring';
const MONTHS = [
  'январь',
  'февраль',
  'март',
  'апрель',
  'май',
  'июнь',
  'июль',
  'август',
  'сентябрь',
  'октябрь',
  'ноябрь',
  'декабрь',
];

function TodoList({ view }: { view: 'mine' | 'assigned' | 'all' }) {
  const t = useTranslations('todos');
  const [status, setStatus] = useState<'OPEN' | 'DONE'>('OPEN');
  const [page, setPage] = useState(1);
  const [dialog, setDialog] = useState<{ open: boolean; todo: TodoDto | null }>({
    open: false,
    todo: null,
  });
  useEffect(() => setPage(1), [status, view]);
  const list = useTodos({ view, status, page, pageSize: 50 });
  const act = useTodoMutation(
    ({ id, action }: { id: string; action: 'complete' | 'reopen' | 'delete' }) =>
      action === 'delete'
        ? api(`/todos/${id}`, { method: 'DELETE' })
        : api(`/todos/${id}/${action}`, { method: 'POST' }),
  );
  const run = async (id: string, action: 'complete' | 'reopen' | 'delete') => {
    try {
      await act.mutateAsync({ id, action });
    } catch (err) {
      toast.error(errorMessage(err));
    }
  };

  return (
    <>
      <div className="mb-4 flex flex-wrap gap-2">
        {(['OPEN', 'DONE'] as const).map((s) => (
          <Button
            key={s}
            size="sm"
            variant={status === s ? 'default' : 'outline'}
            onClick={() => setStatus(s)}
          >
            {t(s === 'OPEN' ? 'statusOpen' : 'statusDone')}
          </Button>
        ))}
        <Button size="sm" className="ml-auto" onClick={() => setDialog({ open: true, todo: null })}>
          <Plus className="size-4" /> {t('add')}
        </Button>
      </div>
      <Card>
        {list.isPending ? (
          <TableSkeleton />
        ) : list.isError ? (
          <ErrorState error={list.error} onRetry={() => list.refetch()} />
        ) : list.data.items.length === 0 ? (
          <EmptyState
            title={t(status === 'OPEN' ? 'emptyOpen' : 'emptyDone')}
            text={t('emptyText')}
          />
        ) : (
          <>
            <div className="overflow-x-auto">
              <Table>
                <THead>
                  <TR>
                    <TH>{t('title')}</TH>
                    <TH>{t('kind')}</TH>
                    <TH>{t('due')}</TH>
                    <TH>{t('client')}</TH>
                    <TH>{view === 'mine' ? t('from') : t('owner')}</TH>
                    <TH className="w-28" />
                  </TR>
                </THead>
                <TBody>
                  {list.data.items.map((x) => {
                    const Icon = KIND_ICON[x.kind];
                    return (
                      <TR key={x.id}>
                        <TD>
                          <span className="flex items-start gap-2">
                            <span
                              className={cn(
                                'mt-1.5 size-2 shrink-0 rounded-full',
                                PRIORITY_DOT[x.priority],
                              )}
                            />
                            <span
                              className={cn(
                                'font-medium',
                                x.status === 'DONE' && 'text-muted-foreground line-through',
                              )}
                            >
                              {x.title}
                            </span>
                          </span>
                        </TD>
                        <TD className="whitespace-nowrap text-muted-foreground">
                          <span className="flex items-center gap-1">
                            <Icon className="size-3.5" /> {t(`kinds.${x.kind}`)}
                          </span>
                        </TD>
                        <TD
                          className={cn(
                            'whitespace-nowrap',
                            x.overdue && 'font-medium text-danger',
                          )}
                        >
                          {dueLabel(x.dueAt, t)}
                        </TD>
                        <TD>
                          {x.client ? (
                            <Link href={`/clients/${x.client.id}`} className="hover:underline">
                              {x.client.name}
                            </Link>
                          ) : (
                            '—'
                          )}
                        </TD>
                        <TD className="whitespace-nowrap text-muted-foreground">
                          {view === 'mine'
                            ? x.creator.id === x.owner.id
                              ? '—'
                              : x.creator.name
                            : x.owner.name}
                        </TD>
                        <TD className="whitespace-nowrap text-right">
                          {x.can.update ? (
                            <>
                              {x.status === 'OPEN' ? (
                                <>
                                  <Button
                                    size="sm"
                                    variant="ghost"
                                    aria-label={t('complete', { title: x.title })}
                                    onClick={() => void run(x.id, 'complete')}
                                  >
                                    <Check className="size-4" />
                                  </Button>
                                  <Button
                                    size="sm"
                                    variant="ghost"
                                    aria-label={t('edit')}
                                    onClick={() => setDialog({ open: true, todo: x })}
                                  >
                                    <Pencil className="size-4" />
                                  </Button>
                                </>
                              ) : (
                                <Button
                                  size="sm"
                                  variant="ghost"
                                  aria-label={t('reopen')}
                                  onClick={() => void run(x.id, 'reopen')}
                                >
                                  <RotateCcw className="size-4" />
                                </Button>
                              )}
                            </>
                          ) : null}
                        </TD>
                      </TR>
                    );
                  })}
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
      <TodoDialog
        open={dialog.open}
        todo={dialog.todo}
        onOpenChange={(o) => setDialog((s) => ({ ...s, open: o }))}
      />
    </>
  );
}

function scheduleText(
  r: { frequency: RecurrenceFrequency; dayOfMonth: number; month: number | null },
  t: (k: string, v?: Record<string, string | number>) => string,
) {
  if (r.frequency === 'MONTHLY') return t('scheduleMonthly', { day: r.dayOfMonth });
  if (r.frequency === 'QUARTERLY')
    return t('scheduleQuarterly', { day: r.dayOfMonth, month: r.month ?? 1 });
  return t('scheduleYearly', { day: r.dayOfMonth, month: MONTHS[(r.month ?? 1) - 1]! });
}

function RecurringDialog({
  rule,
  open,
  onOpenChange,
}: {
  rule: RecurringTodoDto | null;
  open: boolean;
  onOpenChange: (o: boolean) => void;
}) {
  const t = useTranslations('todos');
  const tc = useTranslations('crm');
  const empty = {
    title: '',
    description: '',
    kind: 'REPORT' as TodoKind,
    priority: 'HIGH',
    frequency: 'MONTHLY' as RecurrenceFrequency,
    dayOfMonth: '4',
    month: '1',
    remindDaysBefore: '3',
    isActive: true,
  };
  const [v, setV] = useState(empty);
  const [errors, setErrors] = useState<Record<string, string>>({});
  useEffect(() => {
    if (!open) return;
    setErrors({});
    setV(
      rule
        ? {
            title: rule.title,
            description: rule.description ?? '',
            kind: rule.kind,
            priority: rule.priority,
            frequency: rule.frequency,
            dayOfMonth: String(rule.dayOfMonth),
            month: String(rule.month ?? 1),
            remindDaysBefore: String(rule.remindDaysBefore),
            isActive: rule.isActive,
          }
        : empty,
    );
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, rule]);
  const save = useTodoMutation(() => {
    const body = {
      ...v,
      description: v.description || null,
      dayOfMonth: Number(v.dayOfMonth),
      month: v.frequency === 'MONTHLY' ? null : Number(v.month),
      remindDaysBefore: Number(v.remindDaysBefore),
    };
    return rule
      ? api(`/recurring-todos/${rule.id}`, { method: 'PUT', body })
      : api('/recurring-todos', { method: 'POST', body });
  });
  const set =
    (k: keyof typeof v) =>
    (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>) =>
      setV((s) => ({ ...s, [k]: e.target.value }));
  const monthOptions =
    v.frequency === 'QUARTERLY'
      ? [1, 2, 3].map((m) => ({ value: m, label: t('quarterMonth', { n: m }) }))
      : MONTHS.map((m, i) => ({ value: i + 1, label: m }));

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        title={rule ? t('ruleEdit') : t('ruleNew')}
        description={`${t('placeholdersHint')} {прошлый_месяц}, {прошлый_квартал}, {прошлый_год}, {месяц}, {год}`}
      >
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
          <Field label={t('title')} htmlFor="rt-title" error={errors.title}>
            <Input id="rt-title" required value={v.title} onChange={set('title')} />
          </Field>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label={t('frequency')} htmlFor="rt-freq">
              <NativeSelect id="rt-freq" value={v.frequency} onChange={set('frequency')}>
                {RECURRENCE_FREQUENCIES.map((f) => (
                  <option key={f} value={f}>
                    {t(`frequencies.${f}`)}
                  </option>
                ))}
              </NativeSelect>
            </Field>
            <Field label={t('dayOfMonth')} htmlFor="rt-day" error={errors.dayOfMonth}>
              <Input
                id="rt-day"
                type="number"
                min={1}
                max={28}
                value={v.dayOfMonth}
                onChange={set('dayOfMonth')}
              />
            </Field>
            {v.frequency !== 'MONTHLY' ? (
              <Field label={t('month')} htmlFor="rt-month" error={errors.month}>
                <NativeSelect id="rt-month" value={v.month} onChange={set('month')}>
                  {monthOptions.map((m) => (
                    <option key={m.value} value={m.value}>
                      {m.label}
                    </option>
                  ))}
                </NativeSelect>
              </Field>
            ) : null}
            <Field label={t('remindDaysBefore')} htmlFor="rt-remind">
              <Input
                id="rt-remind"
                type="number"
                min={0}
                max={30}
                value={v.remindDaysBefore}
                onChange={set('remindDaysBefore')}
              />
            </Field>
            <Field label={t('kind')} htmlFor="rt-kind">
              <NativeSelect id="rt-kind" value={v.kind} onChange={set('kind')}>
                {TODO_KINDS.map((k) => (
                  <option key={k} value={k}>
                    {t(`kinds.${k}`)}
                  </option>
                ))}
              </NativeSelect>
            </Field>
            <Field label={t('priority')} htmlFor="rt-priority">
              <NativeSelect id="rt-priority" value={v.priority} onChange={set('priority')}>
                {PRIORITIES.map((p) => (
                  <option key={p} value={p}>
                    {tc(`priority.${p}`)}
                  </option>
                ))}
              </NativeSelect>
            </Field>
          </div>
          <Field label={t('description')} htmlFor="rt-desc">
            <Textarea id="rt-desc" rows={2} value={v.description} onChange={set('description')} />
          </Field>
          <label className="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              checked={v.isActive}
              onChange={(e) => setV((s) => ({ ...s, isActive: e.target.checked }))}
            />
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

function RecurringList() {
  const t = useTranslations('todos');
  const rules = useRecurring();
  const [dialog, setDialog] = useState<{ open: boolean; rule: RecurringTodoDto | null }>({
    open: false,
    rule: null,
  });
  const calendar = useTodoMutation(() => api('/recurring-todos/tax-calendar', { method: 'POST' }));
  const remove = useTodoMutation((id: string) =>
    api(`/recurring-todos/${id}`, { method: 'DELETE' }),
  );
  return (
    <>
      <Card>
        <CardHeader className="flex flex-row flex-wrap items-start justify-between gap-3">
          <div>
            <CardTitle>{t('recurringTitle')}</CardTitle>
            <CardDescription>{t('recurringText')}</CardDescription>
          </div>
          <div className="flex flex-wrap gap-2">
            <Button
              variant="outline"
              loading={calendar.isPending}
              onClick={async () => {
                try {
                  await calendar.mutateAsync(undefined);
                  toast.success(t('calendarAdded'));
                } catch (err) {
                  toast.error(errorMessage(err));
                }
              }}
            >
              <CalendarClock className="size-4" /> {t('taxCalendar')}
            </Button>
            <Button onClick={() => setDialog({ open: true, rule: null })}>
              <Plus className="size-4" /> {t('ruleNew')}
            </Button>
          </div>
        </CardHeader>
        {rules.isPending ? (
          <TableSkeleton />
        ) : rules.isError ? (
          <ErrorState error={rules.error} onRetry={() => rules.refetch()} />
        ) : rules.data.length === 0 ? (
          <EmptyState icon={CalendarClock} title={t('noRules')} text={t('noRulesText')} />
        ) : (
          <div className="overflow-x-auto">
            <Table>
              <THead>
                <TR>
                  <TH>{t('title')}</TH>
                  <TH>{t('schedule')}</TH>
                  <TH>{t('nextDue')}</TH>
                  <TH>{t('owner')}</TH>
                  <TH className="w-24" />
                </TR>
              </THead>
              <TBody>
                {rules.data.map((r) => (
                  <TR key={r.id} className={cn(!r.isActive && 'opacity-60')}>
                    <TD>
                      <p className="font-medium">{r.nextTitle}</p>
                      {!r.isActive ? <Badge>{t('paused')}</Badge> : null}
                    </TD>
                    <TD className="whitespace-nowrap text-muted-foreground">
                      {scheduleText(r, t)} · {t('remindBefore', { days: r.remindDaysBefore })}
                    </TD>
                    <TD className="whitespace-nowrap">
                      {r.nextDue.split('-').reverse().join('.')}
                    </TD>
                    <TD className="whitespace-nowrap text-muted-foreground">{r.owner.name}</TD>
                    <TD className="whitespace-nowrap text-right">
                      <Button
                        size="sm"
                        variant="ghost"
                        aria-label={t('ruleEdit')}
                        onClick={() => setDialog({ open: true, rule: r })}
                      >
                        <Pencil className="size-4" />
                      </Button>
                      <Button
                        size="sm"
                        variant="ghost"
                        aria-label={t('ruleDelete')}
                        onClick={async () => {
                          if (!window.confirm(t('ruleDeleteConfirm', { title: r.nextTitle })))
                            return;
                          try {
                            await remove.mutateAsync(r.id);
                          } catch (err) {
                            toast.error(errorMessage(err));
                          }
                        }}
                      >
                        <Trash2 className="size-4" />
                      </Button>
                    </TD>
                  </TR>
                ))}
              </TBody>
            </Table>
          </div>
        )}
      </Card>
      <RecurringDialog
        open={dialog.open}
        rule={dialog.rule}
        onOpenChange={(o) => setDialog((s) => ({ ...s, open: o }))}
      />
    </>
  );
}

/** «Мои дела»: личные дела, поручения и регулярные дела (налоговый календарь). */
export function TodosPage() {
  const t = useTranslations('todos');
  const can = useCan();
  const router = useRouter();
  const pathname = usePathname();
  const params = useSearchParams();
  const views: View[] = [
    'mine',
    'assigned',
    ...(can('task.read', 'ALL') ? (['all'] as const) : []),
    'recurring',
  ];
  const raw = params.get('view') as View | null;
  const view: View = raw && views.includes(raw) ? raw : 'mine';
  return (
    <>
      <PageHeader title={t('pageTitle')} description={t('pageSubtitle')} />
      <Tabs
        items={views.map((k) => ({ key: k, label: t(`views.${k}`) }))}
        value={view}
        onChange={(k) => router.replace(`${pathname}?view=${k}`, { scroll: false })}
      />
      {view === 'recurring' ? <RecurringList /> : <TodoList view={view} />}
    </>
  );
}

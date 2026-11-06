'use client';

import { ATTENDANCE_STATUSES, type AttendanceDto, type AttendanceStatus } from '@fluggi/contracts';
import { CalendarCheck, Pencil, Plus } from 'lucide-react';
import { useTranslations } from 'next-intl';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { PageHeader } from '@/components/shared/page-header';
import { EmptyState, ErrorState, TableSkeleton } from '@/components/shared/states';
import { Tabs } from '@/components/shared/tabs';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Dialog, DialogContent, DialogFooter } from '@/components/ui/dialog';
import { Input, NativeSelect } from '@/components/ui/input';
import { Field } from '@/components/ui/label';
import { TBody, TD, TH, THead, TR, Table } from '@/components/ui/table';
import { useCrmMutation } from '@/features/crm/api';
import { useUsers } from '@/features/team/api';
import { ApiError, api, errorMessage } from '@/lib/api-client';
import { date } from '@/lib/format';
import { useCan } from '@/lib/me-context';
import { useAttendance, useAttendanceSummary } from './api';

const TONE: Record<AttendanceStatus, 'success' | 'warning' | 'danger' | 'neutral' | 'accent'> = {
  PRESENT: 'success',
  LATE: 'warning',
  ABSENT: 'danger',
  DAY_OFF: 'neutral',
  VACATION: 'accent',
  SICK: 'accent',
};

const localToday = () => new Date(Date.now() + 5 * 3_600_000).toISOString().slice(0, 10);
const monthStart = () => `${localToday().slice(0, 8)}01`;

function EditDialog({
  record,
  open,
  onOpenChange,
}: {
  record: AttendanceDto | null;
  open: boolean;
  onOpenChange: (o: boolean) => void;
}) {
  const t = useTranslations('attendance');
  const users = useUsers({ pageSize: 100, status: 'ACTIVE' }, open);
  const [v, setV] = useState({
    userId: '',
    date: localToday(),
    status: 'PRESENT' as AttendanceStatus,
    checkIn: '',
    checkOut: '',
    comment: '',
  });
  const [errors, setErrors] = useState<Record<string, string>>({});
  useEffect(() => {
    if (!open) return;
    setErrors({});
    setV(
      record
        ? {
            userId: record.user.id,
            date: record.date,
            status: record.status,
            checkIn: record.checkIn ?? '',
            checkOut: record.checkOut ?? '',
            comment: record.comment ?? '',
          }
        : {
            userId: '',
            date: localToday(),
            status: 'PRESENT',
            checkIn: '',
            checkOut: '',
            comment: '',
          },
    );
  }, [open, record]);
  const set = (k: keyof typeof v) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) =>
    setV((s) => ({ ...s, [k]: e.target.value }));
  const save = useCrmMutation(() =>
    api('/attendance', {
      method: 'PUT',
      body: {
        userId: v.userId,
        date: v.date,
        status: v.status,
        checkIn: v.checkIn || null,
        checkOut: v.checkOut || null,
        comment: v.comment || null,
      },
    }),
  );
  const present = v.status === 'PRESENT' || v.status === 'LATE';
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent title={t('editTitle')}>
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
          <Field label={t('employee')} htmlFor="at-user" error={errors.userId}>
            <NativeSelect
              id="at-user"
              required
              value={v.userId}
              onChange={set('userId')}
              disabled={Boolean(record)}
            >
              <option value="">—</option>
              {record && !users.data?.items.some((u) => u.id === record.user.id) ? (
                <option value={record.user.id}>{record.user.name}</option>
              ) : null}
              {users.data?.items.map((u) => (
                <option key={u.id} value={u.id}>
                  {u.fullName}
                </option>
              ))}
            </NativeSelect>
          </Field>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label={t('date')} htmlFor="at-date" error={errors.date}>
              <Input
                id="at-date"
                type="date"
                required
                value={v.date}
                onChange={set('date')}
                disabled={Boolean(record)}
              />
            </Field>
            <Field label="Статус" htmlFor="at-status">
              <NativeSelect id="at-status" value={v.status} onChange={set('status')}>
                {ATTENDANCE_STATUSES.map((s) => (
                  <option key={s} value={s}>
                    {t(`status.${s}`)}
                  </option>
                ))}
              </NativeSelect>
            </Field>
            {present ? (
              <>
                <Field label={t('in')} htmlFor="at-in" error={errors.checkIn}>
                  <Input id="at-in" type="time" value={v.checkIn} onChange={set('checkIn')} />
                </Field>
                <Field label={t('out')} htmlFor="at-out" error={errors.checkOut}>
                  <Input id="at-out" type="time" value={v.checkOut} onChange={set('checkOut')} />
                </Field>
              </>
            ) : null}
          </div>
          <Field label={t('comment')} htmlFor="at-comment">
            <Input id="at-comment" value={v.comment} onChange={set('comment')} />
          </Field>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
              Отмена
            </Button>
            <Button type="submit" loading={save.isPending} disabled={!v.userId}>
              Сохранить
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

/** Посещаемость (ТЗ §35): итоги по сотрудникам и журнал отметок за период. */
export function AttendancePage() {
  const t = useTranslations('attendance');
  const can = useCan();
  const manage = can('attendance.manage');
  const [range, setRange] = useState({ dateFrom: monthStart(), dateTo: localToday() });
  const [tab, setTab] = useState('summary');
  const [editing, setEditing] = useState<AttendanceDto | null | 'new'>(null);
  const summary = useAttendanceSummary(range);
  const list = useAttendance(range);
  const hours = (m: number) => (m ? (m / 60).toFixed(1) : '—');

  return (
    <>
      <PageHeader
        title={t('title')}
        description={t('subtitle')}
        actions={
          <div className="flex flex-wrap items-end gap-2">
            <Input
              type="date"
              aria-label={t('from')}
              className="w-auto"
              value={range.dateFrom}
              onChange={(e) =>
                e.target.value && setRange((r) => ({ ...r, dateFrom: e.target.value }))
              }
            />
            <Input
              type="date"
              aria-label={t('to')}
              className="w-auto"
              value={range.dateTo}
              onChange={(e) =>
                e.target.value && setRange((r) => ({ ...r, dateTo: e.target.value }))
              }
            />
            {manage ? (
              <Button onClick={() => setEditing('new')}>
                <Plus /> {t('edit')}
              </Button>
            ) : null}
          </div>
        }
      />
      <Tabs
        items={[
          { key: 'summary', label: t('summaryTitle') },
          { key: 'records', label: t('records'), count: list.data?.length },
        ]}
        value={tab}
        onChange={setTab}
      />
      <Card>
        {tab === 'summary' ? (
          summary.isPending ? (
            <TableSkeleton />
          ) : summary.isError ? (
            <ErrorState error={summary.error} onRetry={() => summary.refetch()} />
          ) : summary.data.length === 0 ? (
            <EmptyState icon={CalendarCheck} title={t('empty')} />
          ) : (
            <div className="overflow-x-auto">
              <Table>
                <THead>
                  <tr>
                    <TH>{t('employee')}</TH>
                    <TH className="text-right">{t('present')}</TH>
                    <TH className="text-right">{t('lateCount')}</TH>
                    <TH className="text-right">{t('lateTotal')}</TH>
                    <TH className="text-right">{t('absent')}</TH>
                    <TH className="text-right">{t('vacation')}</TH>
                    <TH className="text-right">{t('sick')}</TH>
                    <TH className="text-right">{t('workHours')}</TH>
                  </tr>
                </THead>
                <TBody>
                  {summary.data.map((s) => (
                    <TR key={s.user.id}>
                      <TD className="font-medium">{s.user.name}</TD>
                      <TD className="text-right tabular-nums">{s.present}</TD>
                      <TD className={`text-right tabular-nums ${s.late ? 'text-warning' : ''}`}>
                        {s.late}
                      </TD>
                      <TD className="text-right tabular-nums">{s.lateMinutes}</TD>
                      <TD className={`text-right tabular-nums ${s.absent ? 'text-danger' : ''}`}>
                        {s.absent}
                      </TD>
                      <TD className="text-right tabular-nums">{s.vacation}</TD>
                      <TD className="text-right tabular-nums">{s.sick}</TD>
                      <TD className="text-right tabular-nums">{s.workHours}</TD>
                    </TR>
                  ))}
                </TBody>
              </Table>
            </div>
          )
        ) : list.isPending ? (
          <TableSkeleton />
        ) : list.isError ? (
          <ErrorState error={list.error} onRetry={() => list.refetch()} />
        ) : list.data.length === 0 ? (
          <EmptyState icon={CalendarCheck} title={t('empty')} text={t('noMark')} />
        ) : (
          <div className="overflow-x-auto">
            <Table>
              <THead>
                <tr>
                  <TH>{t('date')}</TH>
                  <TH>{t('employee')}</TH>
                  <TH>Статус</TH>
                  <TH>{t('in')}</TH>
                  <TH>{t('out')}</TH>
                  <TH className="text-right">{t('lateMin')}</TH>
                  <TH className="text-right">{t('hours')}</TH>
                  <TH>{t('comment')}</TH>
                  {manage ? <TH /> : null}
                </tr>
              </THead>
              <TBody>
                {list.data.map((r) => (
                  <TR key={r.id}>
                    <TD className="whitespace-nowrap">{date(r.date)}</TD>
                    <TD>{r.user.name}</TD>
                    <TD>
                      <Badge tone={TONE[r.status]}>{t(`status.${r.status}`)}</Badge>
                    </TD>
                    <TD className="tabular-nums">{r.checkIn ?? '—'}</TD>
                    <TD className="tabular-nums">{r.checkOut ?? '—'}</TD>
                    <TD className="text-right tabular-nums">{r.lateMinutes || '—'}</TD>
                    <TD className="text-right tabular-nums">{hours(r.workMinutes)}</TD>
                    <TD className="max-w-56 truncate text-muted-foreground">
                      {r.comment ?? ''}
                      {r.editedBy ? (
                        <span className="block text-xs">✎ {r.editedBy.name}</span>
                      ) : null}
                    </TD>
                    {manage ? (
                      <TD className="text-right">
                        <Button
                          size="icon-sm"
                          variant="ghost"
                          aria-label={t('edit')}
                          onClick={() => setEditing(r)}
                        >
                          <Pencil />
                        </Button>
                      </TD>
                    ) : null}
                  </TR>
                ))}
              </TBody>
            </Table>
          </div>
        )}
      </Card>
      <EditDialog
        record={editing === 'new' ? null : editing}
        open={editing !== null}
        onOpenChange={(o) => (!o ? setEditing(null) : undefined)}
      />
    </>
  );
}

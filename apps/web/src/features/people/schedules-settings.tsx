'use client';

import { ROLE_CODES, type RoleCode, type ScheduleDto } from '@fluggi/contracts';
import { CalendarClock, Pencil, Plus } from 'lucide-react';
import { useTranslations } from 'next-intl';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { EmptyState, ErrorState, TableSkeleton } from '@/components/shared/states';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Dialog, DialogContent, DialogFooter } from '@/components/ui/dialog';
import { Input, NativeSelect } from '@/components/ui/input';
import { Field } from '@/components/ui/label';
import { useCrmMutation } from '@/features/crm/api';
import { useUsers } from '@/features/team/api';
import { ApiError, api, errorMessage } from '@/lib/api-client';
import { useSchedules } from './api';

const DAYS = [1, 2, 3, 4, 5, 6, 7];

function ScheduleDialog({
  schedule,
  open,
  onOpenChange,
}: {
  schedule: ScheduleDto | null;
  open: boolean;
  onOpenChange: (o: boolean) => void;
}) {
  const t = useTranslations('schedules');
  const tr = useTranslations('roles');
  const users = useUsers({ pageSize: 100, status: 'ACTIVE' }, open);
  const [v, setV] = useState({
    name: '',
    roleCode: '' as RoleCode | '',
    startTime: '09:00',
    endTime: '18:00',
    workDays: [1, 2, 3, 4, 5],
    graceMinutes: '10',
    isActive: true,
    userIds: [] as string[],
  });
  const [errors, setErrors] = useState<Record<string, string>>({});
  useEffect(() => {
    if (!open) return;
    setErrors({});
    setV({
      name: schedule?.name ?? '',
      roleCode: schedule?.roleCode ?? '',
      startTime: schedule?.startTime ?? '09:00',
      endTime: schedule?.endTime ?? '18:00',
      workDays: schedule?.workDays ?? [1, 2, 3, 4, 5],
      graceMinutes: String(schedule?.graceMinutes ?? 10),
      isActive: schedule?.isActive ?? true,
      userIds: schedule?.users.map((u) => u.id) ?? [],
    });
  }, [open, schedule]);
  const save = useCrmMutation(() => {
    const body = {
      ...v,
      roleCode: v.roleCode || null,
      graceMinutes: Number(v.graceMinutes),
    };
    return schedule
      ? api(`/work-schedules/${schedule.id}`, { method: 'PUT', body })
      : api('/work-schedules', { method: 'POST', body });
  });
  const toggle = <T,>(list: T[], x: T) =>
    list.includes(x) ? list.filter((y) => y !== x) : [...list, x];

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent title={schedule ? t('edit') : t('new')} description={t('usersHint')}>
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
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label={t('name')} htmlFor="ws-name" error={errors.name}>
              <Input
                id="ws-name"
                required
                value={v.name}
                onChange={(e) => setV((s) => ({ ...s, name: e.target.value }))}
              />
            </Field>
            <Field label={t('role')} htmlFor="ws-role">
              <NativeSelect
                id="ws-role"
                value={v.roleCode}
                onChange={(e) => setV((s) => ({ ...s, roleCode: e.target.value as RoleCode | '' }))}
              >
                <option value="">{t('noRole')}</option>
                {ROLE_CODES.map((r) => (
                  <option key={r} value={r}>
                    {tr(r)}
                  </option>
                ))}
              </NativeSelect>
            </Field>
            <Field label={t('start')} htmlFor="ws-start" error={errors.startTime}>
              <Input
                id="ws-start"
                type="time"
                required
                value={v.startTime}
                onChange={(e) => setV((s) => ({ ...s, startTime: e.target.value }))}
              />
            </Field>
            <Field label={t('end')} htmlFor="ws-end" error={errors.endTime}>
              <Input
                id="ws-end"
                type="time"
                required
                value={v.endTime}
                onChange={(e) => setV((s) => ({ ...s, endTime: e.target.value }))}
              />
            </Field>
            <Field label={t('grace')} htmlFor="ws-grace">
              <Input
                id="ws-grace"
                type="number"
                min={0}
                max={180}
                value={v.graceMinutes}
                onChange={(e) => setV((s) => ({ ...s, graceMinutes: e.target.value }))}
              />
            </Field>
            <label className="flex h-10 items-center gap-2 self-end text-sm">
              <input
                type="checkbox"
                checked={v.isActive}
                onChange={(e) => setV((s) => ({ ...s, isActive: e.target.checked }))}
              />
              {t('active')}
            </label>
          </div>
          <fieldset className="grid gap-2">
            <legend className="mb-1 text-sm font-medium">{t('days')}</legend>
            <div className="flex flex-wrap gap-2">
              {DAYS.map((d) => (
                <label
                  key={d}
                  className="flex items-center gap-1.5 rounded-md border px-2.5 py-1.5 text-sm has-[:checked]:border-accent has-[:checked]:bg-accent-soft"
                >
                  <input
                    type="checkbox"
                    checked={v.workDays.includes(d)}
                    onChange={() => setV((s) => ({ ...s, workDays: toggle(s.workDays, d).sort() }))}
                  />
                  {t(`weekdays.${d}`)}
                </label>
              ))}
            </div>
            {errors.workDays ? <p className="text-xs text-danger">{errors.workDays}</p> : null}
          </fieldset>
          <fieldset className="grid gap-2">
            <legend className="mb-1 text-sm font-medium">{t('users')}</legend>
            <div className="grid max-h-48 gap-1 overflow-y-auto rounded-md border p-2 sm:grid-cols-2">
              {users.data?.items.map((u) => (
                <label key={u.id} className="flex items-center gap-2 text-sm">
                  <input
                    type="checkbox"
                    checked={v.userIds.includes(u.id)}
                    onChange={() => setV((s) => ({ ...s, userIds: toggle(s.userIds, u.id) }))}
                  />
                  {u.fullName}
                </label>
              ))}
            </div>
          </fieldset>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
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

/** Настройки → Рабочие графики (ТЗ §36). */
export function SchedulesSettings() {
  const t = useTranslations('schedules');
  const tr = useTranslations('roles');
  const list = useSchedules();
  const [editing, setEditing] = useState<ScheduleDto | null | 'new'>(null);
  return (
    <div className="grid gap-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <h2 className="font-semibold">{t('title')}</h2>
          <p className="max-w-2xl text-sm text-muted-foreground">{t('subtitle')}</p>
        </div>
        <Button size="sm" onClick={() => setEditing('new')}>
          <Plus /> {t('new')}
        </Button>
      </div>
      <Card>
        {list.isPending ? (
          <TableSkeleton rows={3} cols={3} />
        ) : list.isError ? (
          <ErrorState error={list.error} onRetry={() => list.refetch()} />
        ) : list.data.length === 0 ? (
          <EmptyState icon={CalendarClock} title={t('title')} />
        ) : (
          <ul className="divide-y">
            {list.data.map((s) => (
              <li key={s.id} className="flex flex-wrap items-center gap-3 px-5 py-3">
                <div className="min-w-0 flex-1">
                  <p className="font-medium">
                    {s.name} · {s.startTime}–{s.endTime}
                  </p>
                  <p className="text-sm text-muted-foreground">
                    {s.roleCode ? tr(s.roleCode) : t('noRole')} ·{' '}
                    {s.workDays.map((d) => t(`weekdays.${d}`)).join(', ')} · {t('grace')}:{' '}
                    {s.graceMinutes}
                  </p>
                  {s.users.length ? (
                    <p className="text-xs text-muted-foreground">
                      {s.users.map((u) => u.name).join(', ')}
                    </p>
                  ) : null}
                </div>
                <Badge tone={s.isActive ? 'success' : 'neutral'}>
                  {s.isActive ? t('active') : t('inactive')}
                </Badge>
                <Button
                  size="icon-sm"
                  variant="ghost"
                  aria-label={t('edit')}
                  onClick={() => setEditing(s)}
                >
                  <Pencil />
                </Button>
              </li>
            ))}
          </ul>
        )}
      </Card>
      <ScheduleDialog
        schedule={editing === 'new' ? null : editing}
        open={editing !== null}
        onOpenChange={(o) => (!o ? setEditing(null) : undefined)}
      />
    </div>
  );
}

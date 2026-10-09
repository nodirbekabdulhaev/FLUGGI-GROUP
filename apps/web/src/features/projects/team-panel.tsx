'use client';

import { EXECUTOR_SPECIALTIES, type ProjectDetailDto } from '@fluggi/contracts';
import { UserPlus, Users } from 'lucide-react';
import { useTranslations } from 'next-intl';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import { EmptyState } from '@/components/shared/states';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter } from '@/components/ui/dialog';
import { Input, NativeSelect } from '@/components/ui/input';
import { Field } from '@/components/ui/label';
import { TBody, TD, TH, THead, TR, Table } from '@/components/ui/table';
import { useCrmMutation } from '@/features/crm/api';
import { ApiError, api, errorMessage } from '@/lib/api-client';
import { date } from '@/lib/format';
import { useCandidates } from './api';
import { TaskProgress } from './status';

function AddMemberDialog({
  project,
  open,
  onOpenChange,
}: {
  project: ProjectDetailDto;
  open: boolean;
  onOpenChange: (o: boolean) => void;
}) {
  const t = useTranslations('projects.team');
  const tsp = useTranslations('specialties');
  const tr = useTranslations('roles');
  const candidates = useCandidates(project.id, open);
  const [form, setForm] = useState({ userId: '', role: '', workloadPct: '', deadline: '' });
  const [errors, setErrors] = useState<Record<string, string>>({});
  useEffect(() => {
    if (open) {
      setForm({ userId: '', role: '', workloadPct: '', deadline: '' });
      setErrors({});
    }
  }, [open]);
  const active = useMemo(
    () => new Set(project.members.filter((m) => m.status === 'ACTIVE').map((m) => m.user.id)),
    [project.members],
  );
  const options = (candidates.data ?? []).filter((c) => !active.has(c.id));
  const add = useCrmMutation(() =>
    api(`/projects/${project.id}/members`, {
      method: 'POST',
      body: {
        userId: form.userId,
        role: form.role || null,
        workloadPct: form.workloadPct === '' ? null : Number(form.workloadPct),
        deadline: form.deadline || null,
      },
    }),
  );
  const set =
    (k: keyof typeof form) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) =>
      setForm((f) => ({ ...f, [k]: e.target.value }));

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent title={t('add')}>
        <form
          className="grid gap-4"
          onSubmit={async (e) => {
            e.preventDefault();
            try {
              await add.mutateAsync(undefined);
              toast.success(t('added'));
              onOpenChange(false);
            } catch (err) {
              if (err instanceof ApiError) setErrors(err.fieldErrors());
              toast.error(errorMessage(err));
            }
          }}
        >
          <Field label={t('user')} htmlFor="m-user" error={errors.userId}>
            <NativeSelect
              id="m-user"
              required
              value={form.userId}
              onChange={(e) => {
                const c = options.find((o) => o.id === e.target.value);
                setForm((f) => ({ ...f, userId: e.target.value, role: c?.specialty ?? f.role }));
              }}
            >
              <option value="">—</option>
              {options.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name} · {c.specialty ? tsp(c.specialty) : tr(c.role)}
                </option>
              ))}
            </NativeSelect>
          </Field>
          <div className="grid gap-4 sm:grid-cols-3">
            <Field label={t('role')} htmlFor="m-role" className="sm:col-span-3">
              <NativeSelect id="m-role" value={form.role} onChange={set('role')}>
                <option value="">{t('noRole')}</option>
                {EXECUTOR_SPECIALTIES.map((s) => (
                  <option key={s} value={s}>
                    {tsp(s)}
                  </option>
                ))}
              </NativeSelect>
            </Field>
            <Field label={t('workload')} htmlFor="m-load" error={errors.workloadPct}>
              <Input
                id="m-load"
                type="number"
                min={0}
                max={100}
                value={form.workloadPct}
                onChange={set('workloadPct')}
              />
            </Field>
            <Field
              label={t('deadline')}
              htmlFor="m-deadline"
              error={errors.deadline}
              className="sm:col-span-2"
            >
              <Input id="m-deadline" type="date" value={form.deadline} onChange={set('deadline')} />
            </Field>
          </div>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
              Отмена
            </Button>
            <Button type="submit" loading={add.isPending} disabled={!form.userId}>
              {t('add')}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

/** Команда проекта (ТЗ §21): роль, дата назначения, загрузка, задачи, дедлайн, статус. */
export function TeamPanel({ project }: { project: ProjectDetailDto }) {
  const t = useTranslations('projects.team');
  const tsp = useTranslations('specialties');
  const [adding, setAdding] = useState(false);
  const setStatus = useCrmMutation(({ id, status }: { id: string; status: string }) =>
    api(`/projects/${project.id}/members/${id}`, { method: 'PATCH', body: { status } }),
  );
  const open = !['COMPLETED', 'CANCELLED'].includes(project.status);
  const canAssign = project.can.canAssign && open;

  return (
    <div className="grid gap-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="text-sm text-muted-foreground">
          {t('leads')}: <span className="text-foreground">{project.rop.name}</span> (РОП) ·{' '}
          <span className="text-foreground">{project.manager.name}</span> (менеджер)
        </p>
        {canAssign ? (
          <Button size="sm" onClick={() => setAdding(true)}>
            <UserPlus /> {t('add')}
          </Button>
        ) : null}
      </div>
      {project.members.length === 0 ? (
        <EmptyState icon={Users} title={t('empty')} text={canAssign ? t('emptyText') : undefined} />
      ) : (
        <div className="overflow-x-auto">
          <Table>
            <THead>
              <tr>
                <TH>{t('user')}</TH>
                <TH>{t('role')}</TH>
                <TH>{t('assignedAt')}</TH>
                <TH>{t('workload')}</TH>
                <TH>{t('tasks')}</TH>
                <TH>{t('deadline')}</TH>
                <TH>Статус</TH>
                {canAssign ? <TH /> : null}
              </tr>
            </THead>
            <TBody>
              {project.members.map((m) => (
                <TR key={m.id}>
                  <TD className="font-medium">{m.user.name}</TD>
                  <TD>{m.role ? tsp(m.role) : '—'}</TD>
                  <TD className="whitespace-nowrap">{date(m.assignedAt)}</TD>
                  <TD>{m.workloadPct === null ? '—' : `${m.workloadPct}%`}</TD>
                  <TD>
                    <TaskProgress
                      done={m.tasks.done}
                      total={m.tasks.total}
                      overdue={m.tasks.overdue}
                    />
                  </TD>
                  <TD className="whitespace-nowrap">{date(m.deadline)}</TD>
                  <TD>
                    <Badge
                      tone={
                        m.status === 'ACTIVE'
                          ? 'accent'
                          : m.status === 'DONE'
                            ? 'success'
                            : 'neutral'
                      }
                    >
                      {t(`status.${m.status}`)}
                    </Badge>
                  </TD>
                  {canAssign ? (
                    <TD className="text-right">
                      {m.status === 'ACTIVE' ? (
                        <Button
                          size="sm"
                          variant="ghost"
                          onClick={async () => {
                            if (!window.confirm(t('removeConfirm', { name: m.user.name }))) return;
                            try {
                              await setStatus.mutateAsync({ id: m.id, status: 'REMOVED' });
                              toast.success(t('removed'));
                            } catch (err) {
                              toast.error(errorMessage(err));
                            }
                          }}
                        >
                          {t('remove')}
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
      <AddMemberDialog project={project} open={adding} onOpenChange={setAdding} />
    </div>
  );
}

'use client';

import { PRIORITIES, type Priority, type ProjectDetailDto, type TaskDto } from '@fluggi/contracts';
import { useTranslations } from 'next-intl';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter } from '@/components/ui/dialog';
import { Input, NativeSelect } from '@/components/ui/input';
import { Field } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useCrmMutation } from '@/features/crm/api';
import { ApiError, api, errorMessage } from '@/lib/api-client';
import { fromLocalInput, newIdempotencyKey, toLocalInput } from '@/lib/format';

/** Кому можно поручить задачу: РОП, менеджер и активные участники команды. */
export function assigneeOptions(project: ProjectDetailDto) {
  const map = new Map<string, string>();
  map.set(project.rop.id, `${project.rop.name} (РОП)`);
  map.set(project.manager.id, `${project.manager.name} (менеджер)`);
  for (const m of project.members)
    if (m.status === 'ACTIVE' && !map.has(m.user.id)) map.set(m.user.id, m.user.name);
  return [...map].map(([id, name]) => ({ id, name }));
}

const empty = {
  title: '',
  description: '',
  assigneeId: '',
  priority: 'MEDIUM' as Priority,
  startDate: '',
  deadline: '',
};

export function TaskDialog({
  project,
  task,
  open,
  onOpenChange,
}: {
  project: ProjectDetailDto;
  /** Нет — создание новой задачи. */
  task?: TaskDto | null;
  open: boolean;
  onOpenChange: (o: boolean) => void;
}) {
  const t = useTranslations('tasks');
  const tp = useTranslations('crm.priority');
  const [form, setForm] = useState(empty);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const key = useMemo(() => (open ? newIdempotencyKey() : ''), [open]);
  useEffect(() => {
    if (!open) return;
    setErrors({});
    setForm(
      task
        ? {
            title: task.title,
            description: task.description ?? '',
            assigneeId: task.assignee.id,
            priority: task.priority,
            startDate: task.startDate ?? '',
            deadline: toLocalInput(task.deadline),
          }
        : empty,
    );
  }, [open, task]);

  const save = useCrmMutation(() => {
    const body = {
      title: form.title,
      description: form.description || null,
      assigneeId: form.assigneeId,
      priority: form.priority,
      startDate: form.startDate || null,
      deadline: form.deadline ? fromLocalInput(form.deadline) : null,
    };
    return task
      ? api(`/tasks/${task.id}`, { method: 'PATCH', body })
      : api('/tasks', {
          method: 'POST',
          idempotencyKey: key,
          body: { ...body, projectId: project.id },
        });
  });
  const set =
    (k: keyof typeof form) =>
    (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>) =>
      setForm((f) => ({ ...f, [k]: e.target.value }));
  const people = assigneeOptions(project);
  // Текущий ответственный мог выбыть из команды — оставляем его в списке.
  if (task && !people.some((p) => p.id === task.assignee.id)) people.push(task.assignee);

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent title={task ? `${task.number} · ${t('fields.title')}` : t('new')}>
        <form
          className="grid gap-4"
          onSubmit={async (e) => {
            e.preventDefault();
            try {
              await save.mutateAsync(undefined);
              toast.success(task ? t('saved') : t('created'));
              onOpenChange(false);
            } catch (err) {
              if (err instanceof ApiError) setErrors(err.fieldErrors());
              toast.error(errorMessage(err));
            }
          }}
        >
          <Field label={t('fields.title')} htmlFor="t-title" error={errors.title}>
            <Input id="t-title" required value={form.title} onChange={set('title')} autoFocus />
          </Field>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label={t('fields.assignee')} htmlFor="t-assignee" error={errors.assigneeId}>
              <NativeSelect
                id="t-assignee"
                required
                value={form.assigneeId}
                onChange={set('assigneeId')}
              >
                <option value="">—</option>
                {people.map((p) => (
                  <option key={p.id} value={p.id}>
                    {p.name}
                  </option>
                ))}
              </NativeSelect>
            </Field>
            <Field label={t('fields.priority')} htmlFor="t-priority">
              <NativeSelect id="t-priority" value={form.priority} onChange={set('priority')}>
                {PRIORITIES.map((p) => (
                  <option key={p} value={p}>
                    {tp(p)}
                  </option>
                ))}
              </NativeSelect>
            </Field>
            <Field label={t('fields.startDate')} htmlFor="t-start" error={errors.startDate}>
              <Input id="t-start" type="date" value={form.startDate} onChange={set('startDate')} />
            </Field>
            <Field label={t('fields.deadline')} htmlFor="t-deadline" error={errors.deadline}>
              <Input
                id="t-deadline"
                type="datetime-local"
                value={form.deadline}
                onChange={set('deadline')}
              />
            </Field>
          </div>
          <Field label={t('fields.description')} htmlFor="t-desc" error={errors.description}>
            <Textarea id="t-desc" rows={4} value={form.description} onChange={set('description')} />
          </Field>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
              Отмена
            </Button>
            <Button type="submit" loading={save.isPending} loadingText="Сохранение...">
              {task ? 'Сохранить' : t('new')}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

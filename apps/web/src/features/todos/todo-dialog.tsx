'use client';

import { PRIORITIES, TODO_KINDS, type TodoDto, type TodoKind } from '@fluggi/contracts';
import { useTranslations } from 'next-intl';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter } from '@/components/ui/dialog';
import { Input, NativeSelect } from '@/components/ui/input';
import { Field } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useClients } from '@/features/crm/api';
import { useUsers } from '@/features/team/api';
import { ApiError, api, errorMessage } from '@/lib/api-client';
import { fromLocalInput, toLocalInput } from '@/lib/format';
import { useCan, useMe } from '@/lib/me-context';
import { useTodoMutation } from './api';

/** Создание и правка личного дела. */
export function TodoDialog({
  todo,
  open,
  onOpenChange,
  defaults,
}: {
  todo: TodoDto | null;
  open: boolean;
  onOpenChange: (o: boolean) => void;
  defaults?: { clientId?: string; dealId?: string };
}) {
  const t = useTranslations('todos');
  const tc = useTranslations('crm');
  const me = useMe();
  const can = useCan();
  const canAssign = can('task.create', 'TEAM');
  const users = useUsers(
    { pageSize: 100, status: 'ACTIVE' },
    open && canAssign && can('employee.read'),
  );
  const clients = useClients({ pageSize: 100 }, open && can('client.read'));
  const empty = {
    title: '',
    description: '',
    kind: 'TASK' as TodoKind,
    priority: 'MEDIUM',
    dueAt: '',
    ownerId: me.id,
    clientId: defaults?.clientId ?? '',
  };
  const [v, setV] = useState(empty);
  const [errors, setErrors] = useState<Record<string, string>>({});
  useEffect(() => {
    if (!open) return;
    setErrors({});
    setV(
      todo
        ? {
            title: todo.title,
            description: todo.description ?? '',
            kind: todo.kind,
            priority: todo.priority,
            dueAt: toLocalInput(todo.dueAt),
            ownerId: todo.owner.id,
            clientId: todo.client?.id ?? '',
          }
        : empty,
    );
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, todo]);
  const save = useTodoMutation(() => {
    const body = {
      title: v.title,
      description: v.description || null,
      kind: v.kind,
      priority: v.priority,
      dueAt: v.dueAt ? fromLocalInput(v.dueAt) : null,
      ownerId: v.ownerId,
      clientId: v.clientId || null,
      ...(todo ? {} : defaults?.dealId ? { dealId: defaults.dealId } : {}),
    };
    return todo
      ? api(`/todos/${todo.id}`, { method: 'PATCH', body })
      : api('/todos', { method: 'POST', body });
  });
  const set =
    (k: keyof typeof v) =>
    (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>) =>
      setV((s) => ({ ...s, [k]: e.target.value }));

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent title={todo ? t('edit') : t('new')}>
        <form
          className="grid gap-4"
          onSubmit={async (e) => {
            e.preventDefault();
            try {
              await save.mutateAsync(undefined);
              toast.success(todo ? t('saved') : t('created'));
              onOpenChange(false);
            } catch (err) {
              if (err instanceof ApiError) setErrors(err.fieldErrors());
              toast.error(errorMessage(err));
            }
          }}
        >
          <Field label={t('title')} htmlFor="td-title" error={errors.title}>
            <Input id="td-title" required autoFocus value={v.title} onChange={set('title')} />
          </Field>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label={t('kind')} htmlFor="td-kind">
              <NativeSelect id="td-kind" value={v.kind} onChange={set('kind')}>
                {TODO_KINDS.map((k) => (
                  <option key={k} value={k}>
                    {t(`kinds.${k}`)}
                  </option>
                ))}
              </NativeSelect>
            </Field>
            <Field label={t('priority')} htmlFor="td-priority">
              <NativeSelect id="td-priority" value={v.priority} onChange={set('priority')}>
                {PRIORITIES.map((p) => (
                  <option key={p} value={p}>
                    {tc(`priority.${p}`)}
                  </option>
                ))}
              </NativeSelect>
            </Field>
            <Field label={t('due')} htmlFor="td-due" error={errors.dueAt}>
              <Input id="td-due" type="datetime-local" value={v.dueAt} onChange={set('dueAt')} />
            </Field>
            {canAssign && users.data ? (
              <Field label={t('owner')} htmlFor="td-owner" error={errors.ownerId}>
                <NativeSelect id="td-owner" value={v.ownerId} onChange={set('ownerId')}>
                  {users.data.items.map((u) => (
                    <option key={u.id} value={u.id}>
                      {u.id === me.id ? `${u.fullName} (${t('me')})` : u.fullName}
                    </option>
                  ))}
                </NativeSelect>
              </Field>
            ) : null}
            {clients.data ? (
              <Field label={t('client')} htmlFor="td-client" error={errors.clientId}>
                <NativeSelect id="td-client" value={v.clientId} onChange={set('clientId')}>
                  <option value="">{t('noClient')}</option>
                  {clients.data.items.map((c) => (
                    <option key={c.id} value={c.id}>
                      {c.name}
                    </option>
                  ))}
                </NativeSelect>
              </Field>
            ) : null}
          </div>
          <Field label={t('description')} htmlFor="td-desc">
            <Textarea id="td-desc" rows={3} value={v.description} onChange={set('description')} />
          </Field>
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

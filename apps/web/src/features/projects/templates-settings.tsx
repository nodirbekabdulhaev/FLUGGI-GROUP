'use client';

import {
  EXECUTOR_SPECIALTIES,
  PRIORITIES,
  type ProjectTemplateDto,
  type TaskTemplateInput,
} from '@fluggi/contracts';
import { LayoutTemplate, Pencil, Plus, Trash2 } from 'lucide-react';
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
import { useCrmMutation, useReferences } from '@/features/crm/api';
import { api, errorMessage } from '@/lib/api-client';
import { useTemplates } from './api';

type Row = Required<Pick<TaskTemplateInput, 'title'>> & {
  role: string;
  startOffsetDays: string;
  durationDays: string;
  priority: string;
};

const newRow = (): Row => ({
  title: '',
  role: '',
  startOffsetDays: '0',
  durationDays: '1',
  priority: 'MEDIUM',
});

function TemplateDialog({
  template,
  open,
  onOpenChange,
}: {
  template: ProjectTemplateDto | null;
  open: boolean;
  onOpenChange: (o: boolean) => void;
}) {
  const t = useTranslations('templates');
  const tsp = useTranslations('specialties');
  const tp = useTranslations('crm.priority');
  const refs = useReferences();
  const [name, setName] = useState('');
  const [serviceId, setServiceId] = useState('');
  const [isActive, setIsActive] = useState(true);
  const [rows, setRows] = useState<Row[]>([newRow()]);
  useEffect(() => {
    if (!open) return;
    setName(template?.name ?? '');
    setServiceId(template?.service?.id ?? '');
    setIsActive(template?.isActive ?? true);
    setRows(
      template?.tasks.map((x) => ({
        title: x.title,
        role: x.role ?? '',
        startOffsetDays: String(x.startOffsetDays),
        durationDays: String(x.durationDays),
        priority: x.priority,
      })) ?? [newRow()],
    );
  }, [open, template]);
  const save = useCrmMutation(() => {
    const body = {
      name,
      serviceId: serviceId || null,
      isActive,
      tasks: rows.map((r) => ({
        title: r.title,
        role: r.role || null,
        startOffsetDays: Number(r.startOffsetDays),
        durationDays: Number(r.durationDays),
        priority: r.priority,
      })),
    };
    return template
      ? api(`/project-templates/${template.id}`, { method: 'PUT', body })
      : api('/project-templates', { method: 'POST', body });
  });
  const setRow =
    (i: number, k: keyof Row) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) =>
      setRows((rs) => rs.map((r, j) => (j === i ? { ...r, [k]: e.target.value } : r)));

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent title={template ? t('edit') : t('new')} className="sm:max-w-3xl">
        <form
          className="grid gap-4"
          onSubmit={async (e) => {
            e.preventDefault();
            try {
              await save.mutateAsync(undefined);
              toast.success(t('saved'));
              onOpenChange(false);
            } catch (err) {
              toast.error(errorMessage(err));
            }
          }}
        >
          <div className="grid gap-4 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
            <Field label={t('name')} htmlFor="tpl-name">
              <Input
                id="tpl-name"
                required
                value={name}
                onChange={(e) => setName(e.target.value)}
              />
            </Field>
            <Field label={t('service')} htmlFor="tpl-service">
              <NativeSelect
                id="tpl-service"
                value={serviceId}
                onChange={(e) => setServiceId(e.target.value)}
              >
                <option value="">{t('noService')}</option>
                {refs.data?.services.map((s) => (
                  <option key={s.id} value={s.id}>
                    {s.name}
                  </option>
                ))}
              </NativeSelect>
            </Field>
            <label className="flex h-10 items-center gap-2 text-sm">
              <input
                type="checkbox"
                checked={isActive}
                onChange={(e) => setIsActive(e.target.checked)}
              />
              {t('active')}
            </label>
          </div>
          <div className="grid gap-2">
            <p className="text-sm font-medium">{t('tasks')}</p>
            <p className="text-xs text-muted-foreground">{t('hint')}</p>
            {rows.map((r, i) => (
              <div
                key={i}
                className="grid gap-2 rounded-md border p-2 sm:grid-cols-[1fr_9rem_5rem_5rem_7rem_auto] sm:border-0 sm:p-0"
              >
                <Input
                  aria-label={t('taskTitle')}
                  placeholder={t('taskTitle')}
                  required
                  value={r.title}
                  onChange={setRow(i, 'title')}
                />
                <NativeSelect aria-label={t('role')} value={r.role} onChange={setRow(i, 'role')}>
                  <option value="">{t('anyRole')}</option>
                  {EXECUTOR_SPECIALTIES.map((s) => (
                    <option key={s} value={s}>
                      {tsp(s)}
                    </option>
                  ))}
                </NativeSelect>
                <Input
                  aria-label={t('offset')}
                  title={t('offset')}
                  type="number"
                  min={0}
                  max={365}
                  value={r.startOffsetDays}
                  onChange={setRow(i, 'startOffsetDays')}
                />
                <Input
                  aria-label={t('duration')}
                  title={t('duration')}
                  type="number"
                  min={1}
                  max={365}
                  value={r.durationDays}
                  onChange={setRow(i, 'durationDays')}
                />
                <NativeSelect
                  aria-label="Приоритет"
                  value={r.priority}
                  onChange={setRow(i, 'priority')}
                >
                  {PRIORITIES.map((p) => (
                    <option key={p} value={p}>
                      {tp(p)}
                    </option>
                  ))}
                </NativeSelect>
                <Button
                  type="button"
                  size="icon"
                  variant="ghost"
                  aria-label="Удалить задачу"
                  disabled={rows.length === 1}
                  onClick={() => setRows((rs) => rs.filter((_, j) => j !== i))}
                >
                  <Trash2 />
                </Button>
              </div>
            ))}
            <Button
              type="button"
              size="sm"
              variant="outline"
              className="justify-self-start"
              onClick={() => setRows((rs) => [...rs, newRow()])}
            >
              <Plus /> {t('addTask')}
            </Button>
          </div>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
              Отмена
            </Button>
            <Button type="submit" loading={save.isPending} loadingText="Сохранение...">
              Сохранить
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

/** Настройки → Шаблоны проектов (ТЗ §62). */
export function TemplatesSettings() {
  const t = useTranslations('templates');
  const tsp = useTranslations('specialties');
  const list = useTemplates();
  const [editing, setEditing] = useState<ProjectTemplateDto | null | 'new'>(null);
  return (
    <div className="grid gap-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <h2 className="font-semibold">{t('title')}</h2>
          <p className="text-sm text-muted-foreground">{t('subtitle')}</p>
        </div>
        <Button size="sm" onClick={() => setEditing('new')}>
          <Plus /> {t('new')}
        </Button>
      </div>
      {list.isPending ? (
        <Card>
          <TableSkeleton rows={3} cols={2} />
        </Card>
      ) : list.isError ? (
        <Card>
          <ErrorState error={list.error} onRetry={() => list.refetch()} />
        </Card>
      ) : list.data.length === 0 ? (
        <Card>
          <EmptyState icon={LayoutTemplate} title={t('empty')} />
        </Card>
      ) : (
        <div className="grid gap-3 md:grid-cols-2">
          {list.data.map((tpl) => (
            <Card key={tpl.id} className="grid content-start gap-3 p-4">
              <div className="flex items-start justify-between gap-3">
                <div>
                  <p className="font-medium">{tpl.name}</p>
                  <p className="text-sm text-muted-foreground">
                    {tpl.service?.name ?? t('noService')} ·{' '}
                    {t('count', { count: tpl.tasks.length })}
                  </p>
                </div>
                <div className="flex items-center gap-2">
                  <Badge tone={tpl.isActive ? 'success' : 'neutral'}>
                    {tpl.isActive ? t('active') : t('inactive')}
                  </Badge>
                  <Button
                    size="icon-sm"
                    variant="ghost"
                    aria-label={t('edit')}
                    onClick={() => setEditing(tpl)}
                  >
                    <Pencil />
                  </Button>
                </div>
              </div>
              <ol className="list-decimal pl-5 text-sm text-muted-foreground">
                {tpl.tasks.map((x) => (
                  <li key={x.id}>
                    {x.title}
                    {x.role ? ` — ${tsp(x.role)}` : ''}
                  </li>
                ))}
              </ol>
            </Card>
          ))}
        </div>
      )}
      <TemplateDialog
        template={editing === 'new' ? null : editing}
        open={editing !== null}
        onOpenChange={(o) => (!o ? setEditing(null) : undefined)}
      />
    </div>
  );
}

'use client';

import {
  PRIORITIES,
  PROJECT_STATUSES,
  type ActivityDto,
  type Priority,
  type ProjectDetailDto,
  type ProjectStatus,
} from '@fluggi/contracts';
import {
  ArrowLeft,
  ArrowRight,
  Download,
  LayoutTemplate,
  MoreHorizontal,
  Paperclip,
  Pencil,
  Upload,
  UserRound,
} from 'lucide-react';
import { useTranslations } from 'next-intl';
import Link from 'next/link';
import { usePathname, useRouter, useSearchParams } from 'next/navigation';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { PriorityText } from '@/components/crm/badges';
import { DetailList } from '@/components/shared/detail-list';
import { EmptyState, ErrorState, TableSkeleton } from '@/components/shared/states';
import { Tabs } from '@/components/shared/tabs';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogFooter } from '@/components/ui/dialog';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input, NativeSelect } from '@/components/ui/input';
import { Field } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useCrmMutation } from '@/features/crm/api';
import { ApiError, api, errorMessage } from '@/lib/api-client';
import { date, dateTime, money } from '@/lib/format';
import { useEntityFiles, useProject, useProjectTimeline, useTemplates } from './api';
import { KanbanBoard } from './kanban';
import { OverdueBadge, ProjectStatusBadge, TaskProgress, TaskStatusBadge } from './status';
import { TaskDetailDialog } from './task-detail';
import { TeamPanel } from './team-panel';

function EditProjectDialog({
  project,
  open,
  onOpenChange,
}: {
  project: ProjectDetailDto;
  open: boolean;
  onOpenChange: (o: boolean) => void;
}) {
  const t = useTranslations('projects');
  const tp = useTranslations('crm.priority');
  const [form, setForm] = useState({
    name: '',
    description: '',
    priority: 'MEDIUM' as Priority,
    startDate: '',
    deadline: '',
  });
  const [errors, setErrors] = useState<Record<string, string>>({});
  useEffect(() => {
    if (!open) return;
    setErrors({});
    setForm({
      name: project.name,
      description: project.description ?? '',
      priority: project.priority,
      startDate: project.startDate ?? '',
      deadline: project.deadline ?? '',
    });
  }, [open, project]);
  const save = useCrmMutation(() =>
    api(`/projects/${project.id}`, {
      method: 'PATCH',
      body: {
        name: form.name,
        description: form.description || null,
        priority: form.priority,
        startDate: form.startDate || null,
        deadline: form.deadline || null,
      },
    }),
  );
  const set =
    (k: keyof typeof form) =>
    (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>) =>
      setForm((f) => ({ ...f, [k]: e.target.value }));
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
          <Field label={t('fields.name')} htmlFor="p-name" error={errors.name}>
            <Input id="p-name" required value={form.name} onChange={set('name')} />
          </Field>
          <div className="grid gap-4 sm:grid-cols-3">
            <Field label={t('fields.priority')} htmlFor="p-priority">
              <NativeSelect id="p-priority" value={form.priority} onChange={set('priority')}>
                {PRIORITIES.map((p) => (
                  <option key={p} value={p}>
                    {tp(p)}
                  </option>
                ))}
              </NativeSelect>
            </Field>
            <Field label={t('fields.startDate')} htmlFor="p-start" error={errors.startDate}>
              <Input id="p-start" type="date" value={form.startDate} onChange={set('startDate')} />
            </Field>
            <Field label={t('fields.deadline')} htmlFor="p-deadline" error={errors.deadline}>
              <Input id="p-deadline" type="date" value={form.deadline} onChange={set('deadline')} />
            </Field>
          </div>
          <Field label={t('fields.description')} htmlFor="p-desc">
            <Textarea id="p-desc" rows={4} value={form.description} onChange={set('description')} />
          </Field>
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

function StatusDialog({
  project,
  open,
  onOpenChange,
}: {
  project: ProjectDetailDto;
  open: boolean;
  onOpenChange: (o: boolean) => void;
}) {
  const t = useTranslations('projects');
  const ts = useTranslations('sales.projectStatus');
  const [status, setStatus] = useState<ProjectStatus>(project.status);
  const [comment, setComment] = useState('');
  useEffect(() => {
    if (open) {
      setStatus(project.status);
      setComment('');
    }
  }, [open, project.status]);
  const save = useCrmMutation(() =>
    api(`/projects/${project.id}/status`, {
      method: 'POST',
      body: { status, comment: comment || undefined },
    }),
  );
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent title={t('changeStatus')}>
        <form
          className="grid gap-4"
          onSubmit={async (e) => {
            e.preventDefault();
            try {
              await save.mutateAsync(undefined);
              toast.success(t('statusChanged'));
              onOpenChange(false);
            } catch (err) {
              toast.error(errorMessage(err));
            }
          }}
        >
          <Field label={t('fields.status')} htmlFor="ps-status">
            <NativeSelect
              id="ps-status"
              value={status}
              onChange={(e) => setStatus(e.target.value as ProjectStatus)}
            >
              {PROJECT_STATUSES.map((s) => (
                <option key={s} value={s}>
                  {ts(s)}
                </option>
              ))}
            </NativeSelect>
          </Field>
          <Field
            label={status === 'CANCELLED' ? t('cancelReason') : 'Комментарий'}
            htmlFor="ps-comment"
          >
            <Textarea
              id="ps-comment"
              rows={3}
              required={status === 'CANCELLED'}
              value={comment}
              onChange={(e) => setComment(e.target.value)}
            />
          </Field>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
              Отмена
            </Button>
            <Button type="submit" loading={save.isPending} disabled={status === project.status}>
              {t('changeStatus')}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

function TemplateDialog({
  project,
  open,
  onOpenChange,
}: {
  project: ProjectDetailDto;
  open: boolean;
  onOpenChange: (o: boolean) => void;
}) {
  const t = useTranslations('projects');
  const tt = useTranslations('templates');
  const templates = useTemplates(open);
  const [templateId, setTemplateId] = useState('');
  useEffect(() => setTemplateId(''), [open]);
  const apply = useCrmMutation(() =>
    api(`/projects/${project.id}/apply-template`, { method: 'POST', body: { templateId } }),
  );
  const active = (templates.data ?? []).filter((x) => x.isActive);
  const chosen = active.find((x) => x.id === templateId);
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent title={t('applyTemplate')} description={t('templateHint')}>
        <form
          className="grid gap-4"
          onSubmit={async (e) => {
            e.preventDefault();
            try {
              await apply.mutateAsync(undefined);
              toast.success(t('templateApplied'));
              onOpenChange(false);
            } catch (err) {
              toast.error(errorMessage(err));
            }
          }}
        >
          <Field label={tt('title')} htmlFor="tpl">
            <NativeSelect
              id="tpl"
              required
              value={templateId}
              onChange={(e) => setTemplateId(e.target.value)}
            >
              <option value="">—</option>
              {active.map((x) => (
                <option key={x.id} value={x.id}>
                  {x.name} · {tt('count', { count: x.tasks.length })}
                </option>
              ))}
            </NativeSelect>
          </Field>
          {chosen ? (
            <ol className="list-decimal pl-5 text-sm text-muted-foreground">
              {chosen.tasks.map((x) => (
                <li key={x.id}>{x.title}</li>
              ))}
            </ol>
          ) : null}
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
              Отмена
            </Button>
            <Button type="submit" loading={apply.isPending} disabled={!templateId}>
              {t('applyTemplate')}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

function ProjectFiles({ project }: { project: ProjectDetailDto }) {
  const t = useTranslations('sales.files');
  const files = useEntityFiles({ projectId: project.id });
  const upload = useCrmMutation((file: File) => {
    const form = new FormData();
    form.append('projectId', project.id);
    form.append('category', 'DOCUMENT');
    form.append('file', file);
    return api('/files', { method: 'POST', body: form });
  });
  const pick = () => {
    const input = document.createElement('input');
    input.type = 'file';
    input.accept = '.pdf,.png,.jpg,.jpeg,.webp,.docx,.xlsx,.zip';
    input.onchange = async () => {
      const file = input.files?.[0];
      if (!file) return;
      try {
        await upload.mutateAsync(file);
        toast.success(t('uploaded'));
      } catch (err) {
        toast.error(errorMessage(err));
      }
    };
    input.click();
  };
  return (
    <div className="grid gap-4">
      <div className="flex items-center gap-3">
        <Button size="sm" onClick={pick} loading={upload.isPending} loadingText={t('uploading')}>
          <Upload /> {t('upload')}
        </Button>
        <span className="text-xs text-muted-foreground">{t('hint')}</span>
      </div>
      {files.isPending ? (
        <TableSkeleton rows={2} cols={1} />
      ) : files.isError ? (
        <ErrorState error={files.error} />
      ) : files.data.length === 0 ? (
        <EmptyState icon={Paperclip} title={t('empty')} />
      ) : (
        <ul className="divide-y rounded-lg border">
          {files.data.map((f) => (
            <li key={f.id} className="flex items-center gap-3 px-4 py-2.5 text-sm">
              <Paperclip className="size-4 text-muted-foreground" />
              <span className="min-w-0 flex-1 truncate">{f.originalName}</span>
              <span className="hidden text-xs text-muted-foreground sm:inline">
                {(f.sizeBytes / 1024).toFixed(0)} КБ · {f.uploadedBy.name} · {date(f.createdAt)}
              </span>
              <Button asChild size="icon-sm" variant="ghost" aria-label={t('download')}>
                <a href={`/api/v1/files/${f.id}/download`}>
                  <Download />
                </a>
              </Button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

function describe(
  a: ActivityDto,
  t: ReturnType<typeof useTranslations>,
  ts: ReturnType<typeof useTranslations>,
) {
  const p = a.payload ?? {};
  const label = t.has(`activity.${a.type}`) ? t(`activity.${a.type}`) : a.type;
  switch (a.type) {
    case 'project.status_changed':
      return (
        <>
          {label}: {ts(`projectStatus.${String(p.from)}`)} <ArrowRight className="inline size-3" />{' '}
          {ts(`projectStatus.${String(p.to)}`)}
          {p.comment ? ` («${String(p.comment)}»)` : ''}
        </>
      );
    case 'task.status_changed':
      return (
        <>
          {label} «{String(p.title)}»: <TaskStatusBadge status={p.from as never} />{' '}
          <ArrowRight className="inline size-3" /> <TaskStatusBadge status={p.to as never} />
        </>
      );
    case 'task.created':
    case 'task.updated':
    case 'task.deleted':
    case 'task.commented':
      return `${label} «${String(p.title)}»`;
    case 'project.template_applied':
      return `${label} «${String(p.template)}» (${String(p.tasks)})`;
    case 'project.member_added':
    case 'project.member_removed':
      return `${label}: ${String(p.user)}`;
    default:
      return label;
  }
}

function ProjectTimeline({ id }: { id: string }) {
  const t = useTranslations('crm');
  const ts = useTranslations('sales');
  const q = useProjectTimeline(id);
  if (q.isPending) return <TableSkeleton rows={4} cols={1} />;
  if (q.isError) return <ErrorState error={q.error} onRetry={() => q.refetch()} />;
  if (q.data.length === 0) return <EmptyState title={t('common.noActivity')} />;
  return (
    <ol className="relative grid gap-4 border-l pl-5">
      {q.data.map((a) => (
        <li key={a.id} className="relative text-sm">
          <span
            className="absolute -left-[1.6rem] top-1.5 size-2.5 rounded-full border-2 border-surface bg-accent"
            aria-hidden
          />
          <p className="text-xs text-muted-foreground">
            {dateTime(a.createdAt)} · {a.actor?.name ?? 'Система'}
          </p>
          <p className="mt-0.5">{describe(a, t, ts)}</p>
        </li>
      ))}
    </ol>
  );
}

/** Карточка проекта (ТЗ §20–23). Открытая задача — в адресе (?task=…), чтобы ссылка из уведомления вела к ней. */
export function ProjectCard({ id }: { id: string }) {
  const t = useTranslations('projects');
  const router = useRouter();
  const pathname = usePathname();
  const params = useSearchParams();
  const project = useProject(id);
  const [tab, setTab] = useState('tasks');
  const [dialog, setDialog] = useState<'edit' | 'status' | 'template' | null>(null);
  const taskId = params.get('task');
  const openTask = (tid: string | null) =>
    router.replace(tid ? `${pathname}?task=${tid}` : pathname, { scroll: false });

  if (project.isPending)
    return (
      <Card>
        <TableSkeleton rows={6} cols={2} />
      </Card>
    );
  if (project.isError)
    return (
      <Card>
        <ErrorState error={project.error} onRetry={() => project.refetch()} />
      </Card>
    );
  const p = project.data;

  return (
    <>
      <Link
        href="/projects/all"
        className="mb-4 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
      >
        <ArrowLeft className="size-4" /> {t('back')}
      </Link>
      <div className="mb-6 flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div className="min-w-0">
          <p className="text-xs text-muted-foreground">{p.number}</p>
          <h1 className="text-xl font-semibold tracking-tight sm:text-2xl">{p.name}</h1>
          <div className="mt-2 flex flex-wrap items-center gap-2 text-sm">
            <ProjectStatusBadge status={p.status} />
            <OverdueBadge days={p.overdueDays} />
            <span>{p.client.name}</span>
            <span className="text-muted-foreground">·</span>
            <span className="flex items-center gap-1 text-muted-foreground">
              <UserRound className="size-3.5" /> {p.rop.name}
            </span>
            <PriorityText priority={p.priority} />
          </div>
        </div>
        <div className="flex items-start gap-3">
          <div className="grid gap-1 text-right">
            {p.price !== null ? (
              <p className="text-2xl font-semibold">{money(p.price, p.currency)}</p>
            ) : null}
            <TaskProgress
              done={p.tasks.done}
              total={p.tasks.total}
              overdue={p.tasks.overdue}
              className="w-40 justify-self-end"
            />
          </div>
          {p.can.canUpdate ? (
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button variant="outline" size="icon-sm" aria-label="Действия">
                  <MoreHorizontal />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent>
                <DropdownMenuItem onSelect={() => setDialog('status')}>
                  <ArrowRight /> {t('changeStatus')}
                </DropdownMenuItem>
                <DropdownMenuItem onSelect={() => setDialog('edit')}>
                  <Pencil /> {t('edit')}
                </DropdownMenuItem>
                {!['COMPLETED', 'CANCELLED'].includes(p.status) ? (
                  <DropdownMenuItem onSelect={() => setDialog('template')}>
                    <LayoutTemplate /> {t('applyTemplate')}
                  </DropdownMenuItem>
                ) : null}
              </DropdownMenuContent>
            </DropdownMenu>
          ) : null}
        </div>
      </div>

      <Tabs
        items={[
          { key: 'tasks', label: t('tabs.tasks'), count: p.tasks.total },
          {
            key: 'team',
            label: t('tabs.team'),
            count: p.members.filter((m) => m.status !== 'REMOVED').length,
          },
          { key: 'files', label: t('tabs.files') },
          { key: 'info', label: t('tabs.info') },
          { key: 'history', label: t('tabs.history') },
        ]}
        value={tab}
        onChange={setTab}
      />

      {tab === 'tasks' ? <KanbanBoard project={p} onOpen={openTask} /> : null}
      {tab === 'team' ? (
        <Card>
          <CardContent className="pt-5">
            <TeamPanel project={p} />
          </CardContent>
        </Card>
      ) : null}
      {tab === 'files' ? (
        <Card>
          <CardContent className="pt-5">
            <ProjectFiles project={p} />
          </CardContent>
        </Card>
      ) : null}
      {tab === 'info' ? (
        <Card>
          <CardContent className="grid gap-5 pt-5">
            <DetailList
              rows={[
                [t('fields.client'), p.client.name],
                [
                  t('fields.deal'),
                  p.deal ? (
                    <Link
                      key="d"
                      href={`/sales/deals/${p.deal.id}`}
                      className="text-accent hover:underline"
                    >
                      {p.deal.number} · {p.deal.name}
                    </Link>
                  ) : null,
                ],
                [t('fields.price'), p.price !== null ? money(p.price, p.currency) : t('noMoney')],
                [t('fields.rop'), p.rop.name],
                [t('fields.manager'), p.manager.name],
                [t('fields.startDate'), date(p.startDate)],
                [t('fields.deadline'), date(p.deadline)],
                [t('fields.template'), p.template?.name],
                [t('fields.completedAt'), p.completedAt ? dateTime(p.completedAt) : null],
              ]}
            />
            {p.description ? <p className="whitespace-pre-wrap text-sm">{p.description}</p> : null}
          </CardContent>
        </Card>
      ) : null}
      {tab === 'history' ? (
        <Card>
          <CardContent className="pt-5">
            <ProjectTimeline id={p.id} />
          </CardContent>
        </Card>
      ) : null}

      <TaskDetailDialog project={p} taskId={taskId} onClose={() => openTask(null)} />
      <EditProjectDialog
        project={p}
        open={dialog === 'edit'}
        onOpenChange={(o) => setDialog(o ? 'edit' : null)}
      />
      <StatusDialog
        project={p}
        open={dialog === 'status'}
        onOpenChange={(o) => setDialog(o ? 'status' : null)}
      />
      <TemplateDialog
        project={p}
        open={dialog === 'template'}
        onOpenChange={(o) => setDialog(o ? 'template' : null)}
      />
    </>
  );
}

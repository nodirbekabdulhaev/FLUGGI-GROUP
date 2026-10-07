'use client';

import { TASK_STATUSES, type ProjectDetailDto, type TaskStatus } from '@fluggi/contracts';
import { ArrowRight, Download, Paperclip, Pencil, Trash2, Upload } from 'lucide-react';
import { useTranslations } from 'next-intl';
import Link from 'next/link';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { PriorityText } from '@/components/crm/badges';
import { DetailList } from '@/components/shared/detail-list';
import { ErrorState, TableSkeleton } from '@/components/shared/states';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent } from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import { useCrmMutation } from '@/features/crm/api';
import { api, errorMessage } from '@/lib/api-client';
import { date, dateTime } from '@/lib/format';
import { useMe } from '@/lib/me-context';
import { useEntityFiles, useTask } from './api';
import { OverdueBadge, TaskStatusBadge } from './status';
import { TaskDialog } from './task-dialog';

const run = async (p: Promise<unknown>, ok: string) => {
  try {
    await p;
    toast.success(ok);
    return true;
  } catch (err) {
    toast.error(errorMessage(err));
    return false;
  }
};

function TaskFiles({ taskId }: { taskId: string }) {
  const t = useTranslations('sales.files');
  const files = useEntityFiles({ taskId });
  const upload = useCrmMutation((file: File) => {
    const form = new FormData();
    form.append('taskId', taskId);
    form.append('category', 'DOCUMENT');
    form.append('file', file);
    return api('/files', { method: 'POST', body: form });
  });
  const pick = () => {
    const input = document.createElement('input');
    input.type = 'file';
    input.accept = '.pdf,.png,.jpg,.jpeg,.webp,.docx,.xlsx,.zip';
    input.onchange = () => {
      const file = input.files?.[0];
      if (file) void run(upload.mutateAsync(file), t('uploaded'));
    };
    input.click();
  };
  return (
    <div className="grid gap-2">
      <div className="flex items-center gap-3">
        <Button
          size="sm"
          variant="outline"
          onClick={pick}
          loading={upload.isPending}
          loadingText={t('uploading')}
        >
          <Upload /> {t('upload')}
        </Button>
        <span className="text-xs text-muted-foreground">{t('hint')}</span>
      </div>
      {files.data && files.data.length > 0 ? (
        <ul className="divide-y rounded-lg border">
          {files.data.map((f) => (
            <li key={f.id} className="flex items-center gap-3 px-3 py-2 text-sm">
              <Paperclip className="size-4 text-muted-foreground" />
              <span className="min-w-0 flex-1 truncate">{f.originalName}</span>
              <span className="hidden text-xs text-muted-foreground sm:inline">
                {f.uploadedBy.name} · {date(f.createdAt)}
              </span>
              <Button asChild size="icon-sm" variant="ghost" aria-label={t('download')}>
                <a href={`/api/v1/files/${f.id}/download`}>
                  <Download />
                </a>
              </Button>
            </li>
          ))}
        </ul>
      ) : null}
    </div>
  );
}

/** Карточка задачи: статус, прогресс, файлы, комментарии и история (ТЗ §22, §3.4). */
export function TaskDetailDialog({
  project,
  taskId,
  onClose,
}: {
  project: ProjectDetailDto;
  taskId: string | null;
  onClose: () => void;
}) {
  const t = useTranslations('tasks');
  const tsp = useTranslations('specialties');
  const me = useMe();
  const task = useTask(taskId);
  const [comment, setComment] = useState('');
  const [progress, setProgress] = useState(0);
  const [editing, setEditing] = useState(false);
  useEffect(() => {
    if (task.data) setProgress(task.data.progressPct);
  }, [task.data]);
  useEffect(() => setComment(''), [taskId]);

  const move = useCrmMutation((status: TaskStatus) =>
    api(`/tasks/${taskId}/move`, { method: 'POST', body: { status } }),
  );
  const patch = useCrmMutation((body: object) =>
    api(`/tasks/${taskId}`, { method: 'PATCH', body }),
  );
  const addComment = useCrmMutation((body: string) =>
    api(`/tasks/${taskId}/comments`, { method: 'POST', body: { body } }),
  );
  const remove = useCrmMutation(() => api(`/tasks/${taskId}`, { method: 'DELETE' }));

  const d = task.data;
  const isAssignee = d?.assignee.id === me.id;
  const projectOpen = !['COMPLETED', 'CANCELLED'].includes(project.status);
  const canMove = projectOpen && Boolean(d && (d.canEdit || isAssignee));
  const statuses = TASK_STATUSES.filter(
    (s) =>
      s !== d?.status &&
      (d?.canEdit || (s !== 'CANCELLED' && d?.status !== 'DONE' && d?.status !== 'CANCELLED')),
  );

  return (
    <Dialog open={Boolean(taskId)} onOpenChange={(o) => (!o ? onClose() : undefined)}>
      <DialogContent title={d ? `${d.number} · ${d.title}` : t('open')} className="sm:max-w-2xl">
        {task.isPending ? (
          <TableSkeleton rows={5} cols={2} />
        ) : task.isError ? (
          <ErrorState error={task.error} onRetry={() => task.refetch()} />
        ) : d ? (
          <div className="grid gap-5">
            <div className="flex flex-wrap items-center gap-2">
              <TaskStatusBadge status={d.status} />
              <OverdueBadge days={d.overdueDays} />
              <PriorityText priority={d.priority} />
              {d.canEdit && projectOpen ? (
                <div className="ml-auto flex gap-1">
                  <Button size="sm" variant="outline" onClick={() => setEditing(true)}>
                    <Pencil /> Изменить
                  </Button>
                  <Button
                    size="icon-sm"
                    variant="ghost"
                    aria-label="Удалить"
                    onClick={async () => {
                      if (!window.confirm(t('deleteConfirm', { title: d.title }))) return;
                      if (await run(remove.mutateAsync(undefined), t('deleted'))) onClose();
                    }}
                  >
                    <Trash2 />
                  </Button>
                </div>
              ) : null}
            </div>

            {canMove ? (
              <div className="flex flex-wrap items-center gap-2">
                <span className="text-sm text-muted-foreground">{t('moveTo')}:</span>
                {statuses.map((s) => (
                  <Button
                    key={s}
                    size="sm"
                    variant={s === 'DONE' ? 'accent' : 'outline'}
                    disabled={move.isPending}
                    onClick={() => run(move.mutateAsync(s), t('moved'))}
                  >
                    {t(`status.${s}`)}
                  </Button>
                ))}
              </div>
            ) : null}

            <DetailList
              rows={[
                [
                  t('fields.project'),
                  <Link key="p" href={`/projects/${d.project.id}`} className="hover:underline">
                    {d.project.number} · {d.project.name}
                  </Link>,
                ],
                [
                  t('fields.assignee'),
                  d.waitingForRole && d.templateRole
                    ? `${d.assignee.name} (${t('waitingRole', { role: tsp(d.templateRole) })})`
                    : d.assignee.name,
                ],
                [t('fields.creator'), d.creator.name],
                [t('fields.startDate'), date(d.startDate)],
                [t('fields.deadline'), dateTime(d.deadline)],
                [t('fields.rework'), d.reworkCount ? String(d.reworkCount) : '0'],
              ]}
            />

            <div className="grid gap-2">
              <label htmlFor="task-progress" className="text-sm font-medium">
                {t('fields.progress')}: {progress}%
              </label>
              <input
                id="task-progress"
                type="range"
                min={0}
                max={100}
                step={5}
                value={progress}
                disabled={!canMove || d.status === 'DONE'}
                onChange={(e) => setProgress(Number(e.target.value))}
                onPointerUp={() =>
                  progress !== d.progressPct &&
                  run(patch.mutateAsync({ progressPct: progress }), t('progressSaved'))
                }
                onKeyUp={() =>
                  progress !== d.progressPct &&
                  run(patch.mutateAsync({ progressPct: progress }), t('progressSaved'))
                }
                className="accent-[var(--color-accent)]"
              />
            </div>

            {d.description ? (
              <p className="whitespace-pre-wrap rounded-md bg-muted/50 p-3 text-sm">
                {d.description}
              </p>
            ) : null}

            <section className="grid gap-2">
              <h3 className="text-sm font-semibold">{t('files')}</h3>
              <TaskFiles taskId={d.id} />
            </section>

            <section className="grid gap-3">
              <h3 className="text-sm font-semibold">{t('comments')}</h3>
              {d.comments.length === 0 ? (
                <p className="text-sm text-muted-foreground">{t('noComments')}</p>
              ) : (
                <ul className="grid gap-3">
                  {d.comments.map((c) => (
                    <li key={c.id} className="rounded-md border p-3 text-sm">
                      <p className="mb-1 text-xs text-muted-foreground">
                        {c.author.name} · {dateTime(c.createdAt)}
                      </p>
                      <p className="whitespace-pre-wrap">{c.body}</p>
                    </li>
                  ))}
                </ul>
              )}
              <form
                className="grid gap-2"
                onSubmit={async (e) => {
                  e.preventDefault();
                  if (!comment.trim()) return;
                  if (await run(addComment.mutateAsync(comment.trim()), t('commentAdded')))
                    setComment('');
                }}
              >
                <Textarea
                  aria-label={t('commentPlaceholder')}
                  placeholder={t('commentPlaceholder')}
                  rows={2}
                  value={comment}
                  onChange={(e) => setComment(e.target.value)}
                />
                <Button
                  type="submit"
                  size="sm"
                  className="justify-self-end"
                  loading={addComment.isPending}
                  disabled={!comment.trim()}
                >
                  {t('send')}
                </Button>
              </form>
            </section>

            <section className="grid gap-2">
              <h3 className="text-sm font-semibold">{t('history')}</h3>
              <ol className="grid gap-1.5 text-sm">
                {d.history.map((h) => (
                  <li key={h.id} className="flex flex-wrap items-center gap-1.5">
                    <span className="text-xs text-muted-foreground">
                      {dateTime(h.createdAt)} · {h.changedBy.name}
                    </span>
                    {h.from ? (
                      <>
                        <TaskStatusBadge status={h.from} />
                        <ArrowRight className="size-3" />
                      </>
                    ) : null}
                    <TaskStatusBadge status={h.to} />
                  </li>
                ))}
              </ol>
            </section>
          </div>
        ) : null}
      </DialogContent>
      {d ? (
        <TaskDialog project={project} task={d} open={editing} onOpenChange={setEditing} />
      ) : null}
    </Dialog>
  );
}

'use client';

import {
  DndContext,
  PointerSensor,
  TouchSensor,
  useDraggable,
  useDroppable,
  useSensor,
  useSensors,
  type DragEndEvent,
} from '@dnd-kit/core';
import {
  KANBAN_STATUSES,
  type Paginated,
  type ProjectDetailDto,
  type TaskDto,
  type TaskListQuery,
  type TaskStatus,
} from '@fluggi/contracts';
import { useQueryClient } from '@tanstack/react-query';
import { CalendarClock, MessageSquare, Plus, UserRound } from 'lucide-react';
import { useTranslations } from 'next-intl';
import { useState } from 'react';
import { toast } from 'sonner';
import { ErrorState } from '@/components/shared/states';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { useInvalidateCrm } from '@/features/crm/api';
import { api, errorMessage } from '@/lib/api-client';
import { dateTime } from '@/lib/format';
import { useMe } from '@/lib/me-context';
import { cn } from '@/lib/utils';
import { useTasks } from './api';
import { OverdueBadge, TaskStatusBadge } from './status';
import { TaskDialog } from './task-dialog';

const PRIORITY_BORDER: Record<string, string> = {
  LOW: 'border-l-muted-foreground/30',
  MEDIUM: 'border-l-accent/60',
  HIGH: 'border-l-warning',
  URGENT: 'border-l-danger',
};

function TaskCard({ task, onOpen }: { task: TaskDto; onOpen: (id: string) => void }) {
  const t = useTranslations('tasks');
  const tsp = useTranslations('specialties');
  return (
    <div
      className={cn(
        'rounded-md border border-l-4 bg-surface p-3 text-sm shadow-sm',
        PRIORITY_BORDER[task.priority],
      )}
    >
      <button
        type="button"
        className="text-left font-medium leading-snug hover:underline"
        onPointerDown={(e) => e.stopPropagation()}
        onClick={() => onOpen(task.id)}
      >
        {task.title}
      </button>
      <p className="mt-0.5 text-xs text-muted-foreground">{task.number}</p>
      <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
        <span className="flex items-center gap-1">
          <UserRound className="size-3" />
          {task.assignee.name}
          {task.waitingForRole && task.templateRole
            ? ` · ${t('waitingRole', { role: tsp(task.templateRole) })}`
            : ''}
        </span>
        {task.deadline ? (
          <span className="flex items-center gap-1">
            <CalendarClock className="size-3" /> {dateTime(task.deadline)}
          </span>
        ) : null}
        {task.commentsCount ? (
          <span className="flex items-center gap-1">
            <MessageSquare className="size-3" /> {task.commentsCount}
          </span>
        ) : null}
      </div>
      {task.overdueDays > 0 || (task.progressPct > 0 && task.status !== 'DONE') ? (
        <div className="mt-2 flex items-center gap-2">
          <OverdueBadge days={task.overdueDays} />
          {task.progressPct > 0 && task.status !== 'DONE' ? (
            <span className="text-xs text-muted-foreground">{task.progressPct}%</span>
          ) : null}
        </div>
      ) : null}
    </div>
  );
}

function DraggableTask({
  task,
  disabled,
  onOpen,
}: {
  task: TaskDto;
  disabled: boolean;
  onOpen: (id: string) => void;
}) {
  const drag = useDraggable({ id: task.id, data: { task }, disabled });
  // Карточка — ещё и цель: брошенная на неё задача встаёт перед ней.
  const drop = useDroppable({
    id: `before:${task.id}`,
    data: { status: task.status, beforeId: task.id },
  });
  const style = drag.transform
    ? { transform: `translate3d(${drag.transform.x}px, ${drag.transform.y}px, 0)`, zIndex: 50 }
    : undefined;
  return (
    <div
      ref={(node) => {
        drag.setNodeRef(node);
        drop.setNodeRef(node);
      }}
      style={style}
      {...drag.listeners}
      {...drag.attributes}
      className={cn(
        'rounded-md',
        !disabled && 'cursor-grab touch-none active:cursor-grabbing',
        drag.isDragging && 'opacity-80 ring-2 ring-accent/40',
        drop.isOver && !drag.isDragging && 'ring-2 ring-accent',
      )}
    >
      <TaskCard task={task} onOpen={onOpen} />
    </div>
  );
}

function Column({
  status,
  tasks,
  canDrag,
  onOpen,
}: {
  status: TaskStatus;
  tasks: TaskDto[];
  canDrag: (t: TaskDto) => boolean;
  onOpen: (id: string) => void;
}) {
  const t = useTranslations('tasks');
  const { setNodeRef, isOver } = useDroppable({ id: `col:${status}`, data: { status } });
  return (
    <div className="flex w-72 shrink-0 flex-col rounded-lg bg-muted/60 lg:w-auto lg:min-w-0 lg:flex-1">
      <div className="flex items-center gap-2 border-b border-border/60 p-3">
        <h3 className="flex-1 truncate text-sm font-semibold">{t(`status.${status}`)}</h3>
        <span className="rounded-full bg-surface px-2 text-xs">{tasks.length}</span>
      </div>
      <div
        ref={setNodeRef}
        data-testid={`column-${status}`}
        className={cn(
          'grid min-h-32 flex-1 content-start gap-2 p-2 transition-colors',
          isOver && 'bg-accent-soft',
        )}
      >
        {tasks.map((task) => (
          <DraggableTask key={task.id} task={task} disabled={!canDrag(task)} onOpen={onOpen} />
        ))}
      </div>
    </div>
  );
}

/** Kanban проекта (ТЗ §23): перемещение карточки меняет статус задачи. */
export function KanbanBoard({
  project,
  onOpen,
}: {
  project: ProjectDetailDto;
  onOpen: (id: string) => void;
}) {
  const t = useTranslations('tasks');
  const me = useMe();
  const qc = useQueryClient();
  const invalidate = useInvalidateCrm();
  const [creating, setCreating] = useState(false);
  const query: TaskListQuery = { projectId: project.id, pageSize: 100 };
  const tasks = useTasks(query);
  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 6 } }),
    useSensor(TouchSensor, { activationConstraint: { delay: 200, tolerance: 6 } }),
  );
  const open = !['COMPLETED', 'CANCELLED'].includes(project.status);
  const canDrag = (task: TaskDto) => open && (task.canEdit || task.assignee.id === me.id);

  const onDragEnd = async (e: DragEndEvent) => {
    const task = e.active.data.current?.task as TaskDto | undefined;
    const target = e.over?.data.current as { status: TaskStatus; beforeId?: string } | undefined;
    if (!task || !target || target.beforeId === task.id) return;
    if (target.status === task.status && !target.beforeId) return;
    const key = ['tasks', query];
    const prev = qc.getQueryData<Paginated<TaskDto>>(key);
    // Оптимистично: карточка сразу в новой колонке; при ошибке — откат.
    if (prev) {
      const rest = prev.items.filter((x) => x.id !== task.id);
      const moved = { ...task, status: target.status };
      const at = target.beforeId ? rest.findIndex((x) => x.id === target.beforeId) : -1;
      if (at >= 0) rest.splice(at, 0, moved);
      else rest.push(moved);
      qc.setQueryData(key, { ...prev, items: rest });
    }
    try {
      await api(`/tasks/${task.id}/move`, {
        method: 'POST',
        body: { status: target.status, beforeId: target.beforeId ?? null },
      });
      if (task.status !== target.status) toast.success(t('moved'));
    } catch (err) {
      if (prev) qc.setQueryData(key, prev);
      toast.error(errorMessage(err));
    } finally {
      void invalidate();
    }
  };

  if (tasks.isPending)
    return (
      <div className="grid gap-3 lg:grid-cols-4">
        {KANBAN_STATUSES.map((s) => (
          <Skeleton key={s} className="h-48" />
        ))}
      </div>
    );
  if (tasks.isError) return <ErrorState error={tasks.error} onRetry={() => tasks.refetch()} />;

  const items = tasks.data.items;
  const other = items.filter((x) => x.status === 'BLOCKED' || x.status === 'CANCELLED');

  return (
    <div className="grid gap-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="text-xs text-muted-foreground">
          {project.can.canCreateTasks ? t('kanbanHint') : t('onlyMine')}
          {tasks.data.total > items.length ? ` · показаны первые ${items.length}` : ''}
        </p>
        {project.can.canCreateTasks && open ? (
          <Button size="sm" onClick={() => setCreating(true)}>
            <Plus /> {t('new')}
          </Button>
        ) : null}
      </div>
      <DndContext sensors={sensors} onDragEnd={onDragEnd}>
        <div className="-mx-4 flex gap-3 overflow-x-auto px-4 pb-2 sm:mx-0 sm:px-0">
          {KANBAN_STATUSES.map((s) => (
            <Column
              key={s}
              status={s}
              tasks={items.filter((x) => x.status === s)}
              canDrag={canDrag}
              onOpen={onOpen}
            />
          ))}
        </div>
      </DndContext>
      {other.length > 0 ? (
        <section className="grid gap-2">
          <h3 className="text-sm font-semibold">{t('other')}</h3>
          <ul className="divide-y rounded-lg border">
            {other.map((x) => (
              <li key={x.id} className="flex items-center gap-3 px-3 py-2 text-sm">
                <TaskStatusBadge status={x.status} />
                <button
                  type="button"
                  className="min-w-0 flex-1 truncate text-left hover:underline"
                  onClick={() => onOpen(x.id)}
                >
                  {x.title}
                </button>
                <span className="text-xs text-muted-foreground">{x.assignee.name}</span>
              </li>
            ))}
          </ul>
        </section>
      ) : null}
      <TaskDialog project={project} open={creating} onOpenChange={setCreating} />
    </div>
  );
}

'use client';

import type { Priority, TodoDto, TodoKind } from '@fluggi/contracts';
import {
  Building2,
  CalendarDays,
  Check,
  ChevronDown,
  ChevronRight,
  CircleDot,
  FileText,
  ListChecks,
  Mail,
  Minus,
  Phone,
  Plus,
  Repeat,
  Users,
  Wallet,
} from 'lucide-react';
import Link from 'next/link';
import { useTranslations } from 'next-intl';
import { useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { NativeSelect } from '@/components/ui/input';
import { api, errorMessage } from '@/lib/api-client';
import { cn } from '@/lib/utils';
import { useTodoDock, useTodoMutation } from './api';
import { TodoDialog } from './todo-dialog';

export const KIND_ICON: Record<TodoKind, React.ComponentType<{ className?: string }>> = {
  TASK: ListChecks,
  CALL: Phone,
  EMAIL: Mail,
  MEETING: Users,
  PAYMENT: Wallet,
  REPORT: FileText,
};

/** Цвет приоритета — точка рядом с названием (как в дизайне). */
export const PRIORITY_DOT: Record<Priority, string> = {
  URGENT: 'bg-danger',
  HIGH: 'bg-warning',
  MEDIUM: 'bg-success',
  LOW: 'bg-muted-foreground/40',
};
const PRIORITY_ORDER: Record<Priority, number> = { URGENT: 0, HIGH: 1, MEDIUM: 2, LOW: 3 };

/** «Сегодня 18:00», «Завтра», «12 окт», «Просрочено». */
export function dueLabel(iso: string | null, t: (k: string, v?: Record<string, string>) => string) {
  if (!iso) return t('noDue');
  const d = new Date(iso);
  const day = (x: Date) => new Date(x.getTime() + 5 * 3_600_000).toISOString().slice(0, 10);
  const today = day(new Date());
  const tomorrow = day(new Date(Date.now() + 86_400_000));
  const time = d.toLocaleTimeString('ru-RU', {
    hour: '2-digit',
    minute: '2-digit',
    timeZone: 'Asia/Tashkent',
  });
  if (day(d) === today) return t('today', { time });
  if (day(d) === tomorrow) return t('tomorrow', { time });
  return d.toLocaleDateString('ru-RU', {
    day: 'numeric',
    month: 'short',
    timeZone: 'Asia/Tashkent',
  });
}

type Item = { key: string; due: string | null; priority: Priority; todo: TodoDto };

function TodoRow({ todo, onEdit }: { todo: TodoDto; onEdit: (t: TodoDto) => void }) {
  const t = useTranslations('todos');
  const [open, setOpen] = useState(false);
  const complete = useTodoMutation(() => api(`/todos/${todo.id}/complete`, { method: 'POST' }));
  const Icon = KIND_ICON[todo.kind];
  return (
    <li className="group grid gap-1 py-3" data-testid="dock-todo">
      <div className="flex items-start gap-2">
        <span
          className={cn('mt-1.5 size-2.5 shrink-0 rounded-full', PRIORITY_DOT[todo.priority])}
          aria-hidden
        />
        <button
          type="button"
          onClick={() => onEdit(todo)}
          className="min-w-0 flex-1 text-left text-sm font-semibold leading-snug hover:underline"
        >
          {todo.title}
        </button>
        <button
          type="button"
          aria-label={t('complete', { title: todo.title })}
          title={t('markDone')}
          disabled={complete.isPending}
          onClick={async () => {
            try {
              await complete.mutateAsync(undefined);
              toast.success(t('doneToast'));
            } catch (err) {
              toast.error(errorMessage(err));
            }
          }}
          className="flex size-5 shrink-0 items-center justify-center rounded border border-input text-transparent hover:border-success hover:text-success"
        >
          <Check className="size-3.5" />
        </button>
      </div>
      <div className="flex items-start gap-1 pl-4">
        <button
          type="button"
          aria-expanded={open}
          aria-label={open ? t('collapse') : t('expand')}
          onClick={() => setOpen((o) => !o)}
          className="mt-0.5 text-muted-foreground"
        >
          {open ? <ChevronDown className="size-3.5" /> : <ChevronRight className="size-3.5" />}
        </button>
        <div className="min-w-0 flex-1 text-xs text-muted-foreground">
          {todo.client || todo.deal ? (
            <p className="flex flex-wrap items-center gap-1 text-sm text-foreground">
              <Building2 className="size-3.5 text-accent" />
              {todo.client ? (
                <Link
                  href={`/clients/${todo.client.id}`}
                  className="font-medium underline-offset-2 hover:underline"
                >
                  {todo.client.name}
                </Link>
              ) : null}
              {todo.deal ? (
                <>
                  {todo.client ? <span>·</span> : null}
                  <Link
                    href={`/sales/deals/${todo.deal.id}`}
                    className="underline-offset-2 hover:underline"
                  >
                    {todo.deal.name}
                  </Link>
                </>
              ) : null}
            </p>
          ) : todo.description && !open ? (
            <p className="line-clamp-2">{todo.description}</p>
          ) : null}
          <p className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1">
            <span className="flex items-center gap-1">
              <Icon className="size-3.5" /> {t(`kinds.${todo.kind}`)}
            </span>
            <span
              className={cn('flex items-center gap-1', todo.overdue && 'font-medium text-danger')}
            >
              <CalendarDays className="size-3.5" />
              {todo.overdue ? `${t('overdue')} · ` : ''}
              {dueLabel(todo.dueAt, t)}
            </span>
            <span className="flex items-center gap-1">
              <CircleDot className="size-3.5" /> {t('open')}
            </span>
            {todo.recurring ? (
              <span className="flex items-center gap-1">
                <Repeat className="size-3.5" /> {t('recurring')}
              </span>
            ) : null}
          </p>
          {open ? (
            <div className="mt-2 grid gap-1">
              {todo.description ? (
                <p className="whitespace-pre-wrap text-foreground/80">{todo.description}</p>
              ) : null}
              {todo.creator.id !== todo.owner.id ? (
                <p>{t('from', { name: todo.creator.name })}</p>
              ) : null}
            </div>
          ) : null}
        </div>
      </div>
    </li>
  );
}

/**
 * «Список дел» внизу экрана (по дизайну): только мои личные дела.
 * Задачи проектов — в разделе «Задачи». Свёрнут в полосу; раскрывается в панель с сортировкой.
 */
export function TodoDock() {
  const t = useTranslations('todos');
  const [open, setOpen] = useState(false);
  const [sort, setSort] = useState<'due' | 'priority'>('due');
  const [dialog, setDialog] = useState<{ open: boolean; todo: TodoDto | null }>({
    open: false,
    todo: null,
  });
  const dock = useTodoDock();
  const data = dock.data;
  const count = data?.todos.length ?? 0;
  const overdue = data?.todos.filter((x) => x.overdue).length ?? 0;

  const items: Item[] = [
    ...(data?.todos ?? []).map((todo) => ({
      key: todo.id,
      due: todo.dueAt,
      priority: todo.priority,
      todo,
    })),
  ].sort((a, b) =>
    sort === 'priority'
      ? PRIORITY_ORDER[a.priority] - PRIORITY_ORDER[b.priority] ||
        (a.due ?? '9').localeCompare(b.due ?? '9')
      : (a.due ?? '9').localeCompare(b.due ?? '9') ||
        PRIORITY_ORDER[a.priority] - PRIORITY_ORDER[b.priority],
  );

  return (
    <>
      {open ? (
        <section
          aria-label={t('dockTitle')}
          className="fixed inset-x-0 bottom-12 z-30 flex max-h-[75dvh] flex-col rounded-t-2xl border bg-surface shadow-xl sm:inset-x-auto sm:left-4 sm:w-[440px] lg:left-[17rem] sm:rounded-2xl"
        >
          <header className="grid gap-3 border-b-2 border-success/40 px-5 pb-3 pt-4">
            <div className="flex items-center gap-2">
              <ListChecks className="size-5 text-muted-foreground" />
              <h2 className="text-lg">{t('dockTitle')}</h2>
              <span className="text-xs text-muted-foreground">
                {t('count', { count })}
                {overdue ? ` · ${t('overdueCount', { count: overdue })}` : ''}
              </span>
              <button
                type="button"
                className="ml-auto rounded p-1 text-muted-foreground hover:bg-muted"
                aria-label={t('minimize')}
                onClick={() => setOpen(false)}
              >
                <Minus className="size-5" />
              </button>
            </div>
            <div className="flex flex-wrap gap-2">
              <NativeSelect
                aria-label={t('sort')}
                className="h-8 w-36 text-xs"
                value={sort}
                onChange={(e) => setSort(e.target.value as typeof sort)}
              >
                <option value="due">{t('sortDue')}</option>
                <option value="priority">{t('sortPriority')}</option>
              </NativeSelect>
              <Button
                size="sm"
                className="ml-auto h-8"
                onClick={() => setDialog({ open: true, todo: null })}
              >
                <Plus className="size-4" /> {t('add')}
              </Button>
            </div>
          </header>
          <div className="overflow-y-auto px-5">
            {dock.isPending ? (
              <p className="py-6 text-sm text-muted-foreground">{t('loading')}</p>
            ) : items.length === 0 ? (
              <p className="py-8 text-center text-sm text-muted-foreground">{t('empty')}</p>
            ) : (
              <ul className="divide-y">
                {items.map((it) => (
                  <TodoRow
                    key={it.key}
                    todo={it.todo}
                    onEdit={(todo) => setDialog({ open: true, todo })}
                  />
                ))}
              </ul>
            )}
          </div>
          <footer className="border-t px-5 py-2 text-right">
            <Link
              href="/todos"
              className="text-xs text-muted-foreground hover:underline"
              onClick={() => setOpen(false)}
            >
              {t('openPage')}
            </Link>
          </footer>
        </section>
      ) : null}
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        aria-expanded={open}
        className="fixed inset-x-0 bottom-0 z-30 flex h-12 items-center gap-3 border-t bg-surface px-5 text-sm text-muted-foreground hover:text-foreground lg:left-64"
      >
        <ListChecks className="size-5 text-success" />
        <span className="font-medium">{t('dockTitle')}</span>
        {count ? (
          <span
            className={cn(
              'rounded-full px-2 py-0.5 text-xs font-medium',
              overdue ? 'bg-danger-soft text-danger' : 'bg-muted text-foreground',
            )}
          >
            {count}
          </span>
        ) : null}
      </button>
      <TodoDialog
        open={dialog.open}
        todo={dialog.todo}
        onOpenChange={(o) => setDialog((s) => ({ ...s, open: o }))}
      />
    </>
  );
}

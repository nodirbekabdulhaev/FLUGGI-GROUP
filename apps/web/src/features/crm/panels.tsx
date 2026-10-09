'use client';

import type { ActivityDto, MeetingDto } from '@fluggi/contracts';
import {
  ArrowRight,
  CalendarCheck,
  CalendarClock,
  CheckCircle2,
  MessageSquare,
  Trash2,
} from 'lucide-react';
import { useTranslations } from 'next-intl';
import { useState } from 'react';
import { toast } from 'sonner';
import { EmptyState, ErrorState, TableSkeleton } from '@/components/shared/states';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { api, errorMessage } from '@/lib/api-client';
import { dateTime } from '@/lib/format';
import { useCan } from '@/lib/me-context';
import { useComments, useCrmMutation, useMeetings, useStageHistory, useTimeline } from './api';
import { CompleteMeetingDialog, CreateMeetingDialog } from './meeting-dialogs';

type Target = { leadId?: string; dealId?: string; clientId?: string };

function formatDuration(sec: number) {
  if (sec < 3600) return `${Math.max(1, Math.round(sec / 60))} мин`;
  if (sec < 86400) return `${Math.round(sec / 3600)} ч`;
  return `${Math.round(sec / 86400)} дн`;
}

function describe(a: ActivityDto, t: ReturnType<typeof useTranslations>) {
  const p = a.payload ?? {};
  const label = t.has(`activity.${a.type}`) ? t(`activity.${a.type}`) : a.type;
  switch (a.type) {
    case 'lead.stage_changed':
    case 'deal.stage_changed':
      return (
        <>
          {label}: {String(p.from)} <ArrowRight className="inline size-3" /> {String(p.to)}
          {p.auto ? <span className="text-muted-foreground"> ({t('common.auto')})</span> : null}
        </>
      );
    case 'lead.assigned':
    case 'deal.assigned':
      return `${label}: ${String(p.to)}`;
    case 'lead.closed':
    case 'deal.closed':
      return `${label}: ${t(`closeStatus.${String(p.status)}`)}${p.reason ? ` — ${String(p.reason)}` : ''}${p.comment ? ` («${String(p.comment)}»)` : ''}`;
    case 'deal.amount_changed': {
      const c = (p.changes ?? {}) as Record<string, { old: unknown; new: unknown }>;
      const amount = c.amount ? `${String(c.amount.old)} → ${String(c.amount.new)}` : '';
      const cur = c.currency ? ` (${String(c.currency.old)} → ${String(c.currency.new)})` : '';
      return `${label}: ${amount}${cur}`;
    }
    case 'lead.updated':
    case 'deal.updated':
    case 'client.updated': {
      const fields = Object.keys((p.changes ?? {}) as object)
        .map((f) =>
          t.has(`fields.${f.replace(/Id$/, '')}`) ? t(`fields.${f.replace(/Id$/, '')}`) : f,
        )
        .join(', ');
      return `${label}: ${fields}`;
    }
    case 'meeting.created':
      return `${label} на ${dateTime(String(p.startsAt))}`;
    case 'meeting.completed':
      return `${label}: ${String(p.result ?? '')}`;
    case 'comment.added':
      return `${label}: ${String(p.preview ?? '')}`;
    case 'lead.converted':
      return `${label} (${String(p.client)})`;
    default:
      return label;
  }
}

export function TimelinePanel({ target }: { target: Target }) {
  const t = useTranslations('crm');
  const q = useTimeline(target);
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
          <p className="mt-0.5">{describe(a, t)}</p>
        </li>
      ))}
    </ol>
  );
}

export function HistoryPanel({ target }: { target: Target }) {
  const t = useTranslations('crm');
  const q = useStageHistory(target);
  if (q.isPending) return <TableSkeleton rows={3} cols={1} />;
  if (q.isError) return <ErrorState error={q.error} onRetry={() => q.refetch()} />;
  if (q.data.length === 0) return <EmptyState title={t('common.noHistory')} />;
  return (
    <ul className="divide-y rounded-lg border">
      {q.data.map((h) => (
        <li key={h.id} className="flex flex-wrap items-center gap-2 px-4 py-3 text-sm">
          <span className="w-32 shrink-0 text-xs text-muted-foreground">
            {dateTime(h.createdAt)}
          </span>
          {h.from ? <span className="text-muted-foreground">{h.from.name}</span> : null}
          {h.from ? <ArrowRight className="size-3 text-muted-foreground" /> : null}
          <span className="font-medium">{h.to.name}</span>
          <span className="text-xs text-muted-foreground">
            · {h.changedBy.name}
            {h.durationSec
              ? ` · ${t('common.durationOnStage', { d: formatDuration(h.durationSec) })}`
              : ''}
          </span>
        </li>
      ))}
    </ul>
  );
}

export function CommentsPanel({ target }: { target: Target }) {
  const t = useTranslations('crm');
  const q = useComments(target);
  const [body, setBody] = useState('');
  const add = useCrmMutation(() => api('/comments', { method: 'POST', body: { ...target, body } }));
  const remove = useCrmMutation((id: string) => api(`/comments/${id}`, { method: 'DELETE' }));
  return (
    <div className="grid gap-4">
      <form
        className="grid gap-2"
        onSubmit={async (e) => {
          e.preventDefault();
          try {
            await add.mutateAsync(undefined);
            setBody('');
          } catch (err) {
            toast.error(errorMessage(err));
          }
        }}
      >
        <Textarea
          placeholder={t('common.commentPlaceholder')}
          value={body}
          onChange={(e) => setBody(e.target.value)}
        />
        <div>
          <Button
            type="submit"
            size="sm"
            disabled={!body.trim()}
            loading={add.isPending}
            loadingText={t('common.sending')}
          >
            <MessageSquare /> {t('common.send')}
          </Button>
        </div>
      </form>
      {q.isPending ? (
        <TableSkeleton rows={2} cols={1} />
      ) : q.isError ? (
        <ErrorState error={q.error} onRetry={() => q.refetch()} />
      ) : q.data.length === 0 ? (
        <EmptyState title={t('common.noComments')} />
      ) : (
        <ul className="grid gap-3">
          {q.data.map((c) => (
            <li key={c.id} className="rounded-lg border p-3 text-sm">
              <div className="mb-1 flex items-center justify-between gap-2 text-xs text-muted-foreground">
                <span>
                  <span className="font-medium text-foreground">{c.author.name}</span> ·{' '}
                  {dateTime(c.createdAt)}
                </span>
                {c.canDelete ? (
                  <Button
                    variant="ghost"
                    size="icon-sm"
                    aria-label={t('common.delete')}
                    onClick={() => remove.mutate(c.id)}
                  >
                    <Trash2 />
                  </Button>
                ) : null}
              </div>
              <p className="whitespace-pre-wrap">{c.body}</p>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

export function MeetingRow({
  m,
  onComplete,
}: {
  m: MeetingDto;
  onComplete?: (id: string) => void;
}) {
  const t = useTranslations('crm');
  const done = m.status === 'DONE';
  const past = new Date(m.startsAt).getTime() < Date.now();
  return (
    <li className="flex flex-wrap items-start gap-3 px-4 py-3 text-sm">
      {done ? (
        <CheckCircle2 className="mt-0.5 size-4 text-success" />
      ) : (
        <CalendarClock className="mt-0.5 size-4 text-accent" />
      )}
      <div className="min-w-0 flex-1">
        <p className="font-medium">
          {dateTime(m.startsAt)} · {t(`meetingType.${m.type}`)}
          {m.link ? (
            <span className="ml-1 font-normal text-muted-foreground">· {m.link}</span>
          ) : null}
        </p>
        <p className="text-xs text-muted-foreground">
          {m.lead
            ? `${m.lead.number} ${m.lead.name}`
            : m.deal
              ? `${m.deal.number} ${m.deal.name}`
              : ''}{' '}
          · {m.manager.name}
          {m.rop ? ` · РОП ${m.rop.name}` : ''}
        </p>
        {m.comment ? <p className="mt-1 text-muted-foreground">{m.comment}</p> : null}
        {m.result ? <p className="mt-1">{m.result}</p> : null}
      </div>
      <Badge
        tone={
          done
            ? 'success'
            : m.status === 'CANCELLED' || m.status === 'NO_SHOW'
              ? 'danger'
              : past
                ? 'warning'
                : 'accent'
        }
      >
        {t(`meetingStatus.${m.status}`)}
      </Badge>
      {!done && m.status !== 'CANCELLED' && onComplete ? (
        <Button size="sm" variant="outline" onClick={() => onComplete(m.id)}>
          <CalendarCheck /> {t('common.completeMeeting')}
        </Button>
      ) : null}
    </li>
  );
}

export function MeetingsPanel({
  target,
  canCreate,
}: {
  target: { leadId?: string; dealId?: string };
  canCreate: boolean;
}) {
  const t = useTranslations('crm');
  const can = useCan();
  const q = useMeetings({ ...target, pageSize: 50 });
  const [open, setOpen] = useState(false);
  const [completing, setCompleting] = useState<string | null>(null);
  return (
    <div className="grid gap-4">
      {canCreate && can('meeting.create') ? (
        <div>
          <Button size="sm" onClick={() => setOpen(true)}>
            <CalendarClock /> {t('common.newMeeting')}
          </Button>
        </div>
      ) : null}
      {q.isPending ? (
        <TableSkeleton rows={2} cols={1} />
      ) : q.isError ? (
        <ErrorState error={q.error} onRetry={() => q.refetch()} />
      ) : q.data.items.length === 0 ? (
        <EmptyState title={t('common.noMeetings')} />
      ) : (
        <ul className="divide-y rounded-lg border">
          {q.data.items.map((m) => (
            <MeetingRow
              key={m.id}
              m={m}
              onComplete={can('meeting.update') ? setCompleting : undefined}
            />
          ))}
        </ul>
      )}
      <CreateMeetingDialog open={open} onOpenChange={setOpen} target={target} />
      <CompleteMeetingDialog
        meetingId={completing}
        onOpenChange={(o) => !o && setCompleting(null)}
      />
    </div>
  );
}

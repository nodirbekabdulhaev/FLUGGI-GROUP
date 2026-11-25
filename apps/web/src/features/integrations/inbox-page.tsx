'use client';

import type { SocialChannel, SocialThreadDto } from '@fluggi/contracts';
import { ArrowLeft, AtSign, MessageCircle, MessageSquare, Send, UserPlus } from 'lucide-react';
import { useTranslations } from 'next-intl';
import Link from 'next/link';
import { usePathname, useRouter, useSearchParams } from 'next/navigation';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { PageHeader } from '@/components/shared/page-header';
import { EmptyState, ErrorState, TableSkeleton } from '@/components/shared/states';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { NativeSelect } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { api, errorMessage } from '@/lib/api-client';
import { dateTime } from '@/lib/format';
import { useCan } from '@/lib/me-context';
import { cn } from '@/lib/utils';
import { useInbox, useInboxMutation, useThreadMessages } from './api';

const CHANNEL_ICON: Record<SocialChannel, typeof MessageCircle> = {
  INSTAGRAM_DM: MessageCircle,
  INSTAGRAM_COMMENT: MessageSquare,
  FACEBOOK_COMMENT: MessageSquare,
};

const who = (t: SocialThreadDto) =>
  t.peerUsername ? `@${t.peerUsername}` : (t.peerName ?? t.peerId);

function Conversation({ thread, onBack }: { thread: SocialThreadDto; onBack: () => void }) {
  const t = useTranslations('inbox');
  const can = useCan();
  const messages = useThreadMessages(thread.id);
  const [text, setText] = useState('');
  const [commentId, setCommentId] = useState<string | null>(null);
  const [mode, setMode] = useState<'public' | 'private'>('public');
  const bottom = useRef<HTMLDivElement>(null);
  const isDm = thread.channel === 'INSTAGRAM_DM';
  const count = messages.data?.length ?? 0;
  useEffect(() => {
    bottom.current?.scrollIntoView({ block: 'end' });
  }, [count]);
  useEffect(() => {
    // По умолчанию отвечаем на последний комментарий клиента
    if (isDm || !messages.data) return;
    const last = [...messages.data].reverse().find((m) => m.direction === 'IN' && m.externalId);
    setCommentId((c) => c ?? last?.externalId ?? null);
  }, [isDm, messages.data]);
  const reply = useInboxMutation(() =>
    api(`/inbox/${thread.id}/reply`, {
      method: 'POST',
      body: { text, commentId: isDm ? undefined : (commentId ?? undefined), mode },
    }),
  );
  const lead = useInboxMutation(() => api(`/inbox/${thread.id}/lead`, { method: 'POST' }));

  return (
    <div className="flex h-full min-h-0 flex-col">
      <header className="flex flex-wrap items-center gap-2 border-b px-4 py-3">
        <button
          type="button"
          onClick={onBack}
          aria-label={t('back')}
          className="rounded p-1 hover:bg-muted md:hidden"
        >
          <ArrowLeft className="size-4" />
        </button>
        <div className="min-w-0 flex-1">
          <p className="truncate font-medium">{who(thread)}</p>
          <p className="text-xs text-muted-foreground">
            {t(`channels.${thread.channel}`)}
            {thread.owner ? ` · ${thread.owner.name}` : ''}
          </p>
        </div>
        {thread.lead ? (
          <Link
            href={`/sales/leads/${thread.lead.id}`}
            className="text-sm text-accent hover:underline"
          >
            {thread.lead.number}
          </Link>
        ) : can('lead.create') ? (
          <Button
            size="sm"
            variant="outline"
            loading={lead.isPending}
            onClick={async () => {
              try {
                await lead.mutateAsync(undefined);
                toast.success(t('leadCreated'));
              } catch (err) {
                toast.error(errorMessage(err));
              }
            }}
          >
            <UserPlus className="size-4" /> {t('createLead')}
          </Button>
        ) : null}
      </header>
      <div className="min-h-0 flex-1 overflow-y-auto px-4 py-3" data-testid="social-messages">
        {messages.isPending ? (
          <TableSkeleton rows={3} cols={1} />
        ) : messages.isError ? (
          <ErrorState error={messages.error} onRetry={() => messages.refetch()} />
        ) : (
          <ul className="grid gap-2">
            {messages.data.map((m) => (
              <li
                key={m.id}
                className={cn('flex', m.direction === 'OUT' ? 'justify-end' : 'justify-start')}
              >
                <button
                  type="button"
                  disabled={isDm || m.direction === 'OUT' || !m.externalId}
                  onClick={() => setCommentId(m.externalId)}
                  className={cn(
                    'max-w-[80%] rounded-2xl px-3 py-2 text-left text-sm',
                    m.direction === 'OUT'
                      ? 'rounded-br-sm bg-accent text-white'
                      : 'rounded-bl-sm bg-muted',
                    !isDm &&
                      m.externalId === commentId &&
                      m.direction === 'IN' &&
                      'ring-2 ring-accent',
                  )}
                >
                  <span className="block whitespace-pre-wrap break-words">{m.text}</span>
                  <span
                    className={cn(
                      'mt-0.5 block text-right text-[10px]',
                      m.direction === 'OUT' ? 'text-white/70' : 'text-muted-foreground',
                    )}
                  >
                    {m.author ? `${m.author.name} · ` : ''}
                    {dateTime(m.createdAt)}
                  </span>
                </button>
              </li>
            ))}
          </ul>
        )}
        <div ref={bottom} />
      </div>
      {can('lead.update') ? (
        <form
          className="grid gap-2 border-t p-3"
          onSubmit={async (e) => {
            e.preventDefault();
            if (!text.trim()) return;
            try {
              await reply.mutateAsync(undefined);
              setText('');
            } catch (err) {
              toast.error(errorMessage(err));
            }
          }}
        >
          {!isDm ? (
            <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
              {t('replyTo')}
              <NativeSelect
                aria-label={t('replyMode')}
                className="h-8 w-48 text-xs"
                value={mode}
                onChange={(e) => setMode(e.target.value as 'public' | 'private')}
              >
                <option value="public">{t('modePublic')}</option>
                <option value="private">{t('modePrivate')}</option>
              </NativeSelect>
              {!commentId ? <span className="text-danger">{t('pickComment')}</span> : null}
            </div>
          ) : null}
          <div className="flex gap-2">
            <Textarea
              aria-label={t('message')}
              rows={2}
              placeholder={t('placeholder')}
              value={text}
              maxLength={1000}
              onChange={(e) => setText(e.target.value)}
              data-gramm="false"
            />
            <Button
              type="submit"
              aria-label={t('send')}
              loading={reply.isPending}
              disabled={!text.trim() || (!isDm && !commentId)}
            >
              <Send className="size-4" />
            </Button>
          </div>
          <p className="text-[11px] text-muted-foreground">{t('windowHint')}</p>
        </form>
      ) : null}
    </div>
  );
}

/** «Входящие»: Директ и комментарии Instagram/Facebook в одном окне. */
export function InboxPage() {
  const t = useTranslations('inbox');
  const router = useRouter();
  const pathname = usePathname();
  const params = useSearchParams();
  const [channel, setChannel] = useState<SocialChannel | ''>('');
  const list = useInbox({ channel: channel || undefined, pageSize: 50 });
  const activeId = params.get('thread');
  const active = list.data?.items.find((x) => x.id === activeId) ?? null;
  const open = (id: string | null) =>
    router.replace(id ? `${pathname}?thread=${id}` : pathname, { scroll: false });

  return (
    <>
      <PageHeader title={t('title')} description={t('subtitle')} />
      <Card className="grid h-[calc(100dvh-14rem)] min-h-[420px] overflow-hidden md:grid-cols-[22rem_1fr]">
        <div className={cn('flex min-h-0 flex-col border-r', active && 'max-md:hidden')}>
          <div className="border-b p-3">
            <NativeSelect
              aria-label={t('channel')}
              value={channel}
              onChange={(e) => setChannel(e.target.value as SocialChannel | '')}
            >
              <option value="">{t('allChannels')}</option>
              <option value="INSTAGRAM_DM">{t('channels.INSTAGRAM_DM')}</option>
              <option value="INSTAGRAM_COMMENT">{t('channels.INSTAGRAM_COMMENT')}</option>
              <option value="FACEBOOK_COMMENT">{t('channels.FACEBOOK_COMMENT')}</option>
            </NativeSelect>
          </div>
          {list.isPending ? (
            <TableSkeleton rows={5} cols={1} />
          ) : list.isError ? (
            <ErrorState error={list.error} onRetry={() => list.refetch()} />
          ) : list.data.items.length === 0 ? (
            <EmptyState icon={AtSign} title={t('empty')} text={t('emptyText')} />
          ) : (
            <ul className="min-h-0 flex-1 divide-y overflow-y-auto">
              {list.data.items.map((th) => {
                const Icon = CHANNEL_ICON[th.channel];
                return (
                  <li key={th.id}>
                    <button
                      type="button"
                      onClick={() => open(th.id)}
                      className={cn(
                        'flex w-full gap-3 px-4 py-3 text-left hover:bg-muted/50',
                        th.id === activeId && 'bg-muted',
                      )}
                    >
                      <Icon className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                      <span className="min-w-0 flex-1">
                        <span className="flex items-baseline justify-between gap-2">
                          <span className="truncate text-sm font-medium">{who(th)}</span>
                          <span className="shrink-0 text-[11px] text-muted-foreground">
                            {dateTime(th.lastMessageAt)}
                          </span>
                        </span>
                        <span className="flex items-center justify-between gap-2">
                          <span className="truncate text-xs text-muted-foreground">
                            {th.lastMessage
                              ? `${th.lastMessage.direction === 'OUT' ? `${t('you')}: ` : ''}${th.lastMessage.text}`
                              : ''}
                          </span>
                          {th.unread ? (
                            <span className="shrink-0 rounded-full bg-accent px-1.5 text-[11px] font-medium text-white">
                              {th.unread}
                            </span>
                          ) : th.lead ? (
                            <Badge tone="success">{t('lead')}</Badge>
                          ) : null}
                        </span>
                      </span>
                    </button>
                  </li>
                );
              })}
            </ul>
          )}
        </div>
        <div className={cn('min-h-0', !active && 'max-md:hidden')}>
          {active ? (
            <Conversation key={active.id} thread={active} onBack={() => open(null)} />
          ) : (
            <div className="flex h-full items-center justify-center p-6 text-center text-sm text-muted-foreground">
              {t('pick')}
            </div>
          )}
        </div>
      </Card>
    </>
  );
}

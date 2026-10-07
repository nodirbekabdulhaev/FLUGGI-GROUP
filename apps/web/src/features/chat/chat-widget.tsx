'use client';

import type { ChatMessageDto, ConversationDto } from '@fluggi/contracts';
import { useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, MessageCircle, Plus, Send, X } from 'lucide-react';
import { usePathname, useRouter, useSearchParams } from 'next/navigation';
import { useTranslations } from 'next-intl';
import { useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
import { Input } from '@/components/ui/input';
import { api, errorMessage } from '@/lib/api-client';
import { cn } from '@/lib/utils';
import {
  useChatContacts,
  useChatMessages,
  useChatUnread,
  useConversations,
} from '@/features/todos/api';

const initials = (name: string) =>
  name
    .split(/\s+/)
    .slice(0, 2)
    .map((p) => p[0])
    .join('')
    .toUpperCase();

const time = (iso: string) => {
  const d = new Date(iso);
  const sameDay = d.toDateString() === new Date().toDateString();
  return sameDay
    ? d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })
    : d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' });
};

function Avatar({ name }: { name: string }) {
  return (
    <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-accent-soft text-xs font-semibold text-accent">
      {initials(name)}
    </span>
  );
}

function Conversation({ id, peer, onBack }: { id: string; peer: string; onBack: () => void }) {
  const t = useTranslations('chat');
  const qc = useQueryClient();
  const messages = useChatMessages(id);
  const [text, setText] = useState('');
  const [sending, setSending] = useState(false);
  const bottom = useRef<HTMLDivElement>(null);
  const count = messages.data?.length ?? 0;

  // Прочитано — при открытии и при новых сообщениях
  useEffect(() => {
    void api(`/chats/${id}/read`, { method: 'POST' }).then(() => {
      void qc.invalidateQueries({ queryKey: ['chat', 'unread'] });
      void qc.invalidateQueries({ queryKey: ['chat', 'list'] });
    });
  }, [id, count, qc]);
  useEffect(() => bottom.current?.scrollIntoView({ block: 'end' }), [count]);

  const send = async () => {
    const body = text.trim();
    if (!body) return;
    setSending(true);
    try {
      const m = await api<ChatMessageDto>(`/chats/${id}/messages`, {
        method: 'POST',
        body: { body },
      });
      qc.setQueryData<ChatMessageDto[]>(['chat', 'messages', id], (list) => [...(list ?? []), m]);
      setText('');
      void qc.invalidateQueries({ queryKey: ['chat', 'list'] });
    } catch (err) {
      toast.error(errorMessage(err));
    } finally {
      setSending(false);
    }
  };

  return (
    <>
      <header className="flex items-center gap-2 border-b px-3 py-2">
        <button
          type="button"
          onClick={onBack}
          aria-label={t('back')}
          className="rounded p-1 hover:bg-muted"
        >
          <ArrowLeft className="size-4" />
        </button>
        <Avatar name={peer} />
        <p className="min-w-0 flex-1 truncate text-sm font-medium">{peer}</p>
      </header>
      <div className="flex-1 overflow-y-auto px-3 py-3" data-testid="chat-messages">
        {messages.data?.length === 0 ? (
          <p className="py-8 text-center text-sm text-muted-foreground">{t('firstMessage')}</p>
        ) : null}
        <ul className="grid gap-2">
          {messages.data?.map((m) => (
            <li key={m.id} className={cn('flex', m.mine ? 'justify-end' : 'justify-start')}>
              <div
                className={cn(
                  'max-w-[80%] rounded-2xl px-3 py-2 text-sm',
                  m.mine ? 'rounded-br-sm bg-accent text-white' : 'rounded-bl-sm bg-muted',
                )}
              >
                <p className="whitespace-pre-wrap break-words">{m.body}</p>
                <p
                  className={cn(
                    'mt-0.5 text-right text-[10px]',
                    m.mine ? 'text-white/70' : 'text-muted-foreground',
                  )}
                >
                  {time(m.createdAt)}
                </p>
              </div>
            </li>
          ))}
        </ul>
        <div ref={bottom} />
      </div>
      <form
        className="flex gap-2 border-t p-2"
        onSubmit={(e) => {
          e.preventDefault();
          void send();
        }}
      >
        <Input
          aria-label={t('message')}
          placeholder={t('placeholder')}
          value={text}
          maxLength={4000}
          onChange={(e) => setText(e.target.value)}
          autoFocus
        />
        <button
          type="submit"
          disabled={sending || !text.trim()}
          aria-label={t('send')}
          className="flex size-10 shrink-0 items-center justify-center rounded-md bg-accent text-white disabled:opacity-50"
        >
          <Send className="size-4" />
        </button>
      </form>
    </>
  );
}

/**
 * Чат сотрудников (всплывающее окно). Новое сообщение — уведомление в Telegram;
 * ссылка из Telegram открывает переписку (?chat=…).
 */
export function ChatWidget() {
  const t = useTranslations('chat');
  const router = useRouter();
  const pathname = usePathname();
  const params = useSearchParams();
  const [open, setOpen] = useState(false);
  const [active, setActive] = useState<{ id: string; peer: string } | null>(null);
  const [picking, setPicking] = useState(false);
  const [q, setQ] = useState('');
  const unread = useChatUnread();
  const list = useConversations(open);
  const contacts = useChatContacts(open && picking);
  const fromLink = params.get('chat');

  // Ссылка из Telegram: /dashboard?chat=<id>
  useEffect(() => {
    if (!fromLink) return;
    setOpen(true);
    setActive({ id: fromLink, peer: '' });
    const next = new URLSearchParams(params.toString());
    next.delete('chat');
    router.replace(next.size ? `${pathname}?${next}` : pathname, { scroll: false });
  }, [fromLink, params, pathname, router]);
  // Имя собеседника для переписки, открытой по ссылке
  useEffect(() => {
    if (active && !active.peer && list.data) {
      const c = list.data.find((x) => x.id === active.id);
      if (c) setActive({ id: c.id, peer: c.peer.name });
    }
  }, [active, list.data]);

  const filtered = useMemo(
    () =>
      (contacts.data ?? []).filter((c) => c.name.toLowerCase().includes(q.trim().toLowerCase())),
    [contacts.data, q],
  );

  const startWith = async (userId: string, name: string) => {
    try {
      const c = await api<{ id: string }>('/chats/direct', { method: 'POST', body: { userId } });
      setPicking(false);
      setQ('');
      setActive({ id: c.id, peer: name });
    } catch (err) {
      toast.error(errorMessage(err));
    }
  };
  const count = unread.data?.count ?? 0;

  return (
    <>
      {open ? (
        <section
          aria-label={t('title')}
          className="fixed inset-x-0 bottom-12 z-40 flex h-[min(560px,80dvh)] flex-col overflow-hidden rounded-t-2xl border bg-surface shadow-xl sm:inset-x-auto sm:bottom-[7.5rem] sm:right-4 sm:w-[380px] sm:rounded-2xl"
        >
          {active ? (
            <Conversation id={active.id} peer={active.peer} onBack={() => setActive(null)} />
          ) : (
            <>
              <header className="flex items-center gap-2 border-b px-4 py-3">
                {picking ? (
                  <button
                    type="button"
                    onClick={() => setPicking(false)}
                    aria-label={t('back')}
                    className="rounded p-1 hover:bg-muted"
                  >
                    <ArrowLeft className="size-4" />
                  </button>
                ) : null}
                <h2 className="flex-1 font-medium">{picking ? t('newChat') : t('title')}</h2>
                {!picking ? (
                  <button
                    type="button"
                    onClick={() => setPicking(true)}
                    aria-label={t('newChat')}
                    title={t('newChat')}
                    className="rounded p-1 hover:bg-muted"
                  >
                    <Plus className="size-4" />
                  </button>
                ) : null}
                <button
                  type="button"
                  onClick={() => setOpen(false)}
                  aria-label={t('close')}
                  className="rounded p-1 hover:bg-muted"
                >
                  <X className="size-4" />
                </button>
              </header>
              {picking ? (
                <div className="flex flex-1 flex-col overflow-hidden">
                  <div className="p-3">
                    <Input
                      autoFocus
                      aria-label={t('searchPeople')}
                      placeholder={t('searchPeople')}
                      value={q}
                      onChange={(e) => setQ(e.target.value)}
                    />
                  </div>
                  <ul className="flex-1 overflow-y-auto">
                    {filtered.map((c) => (
                      <li key={c.id}>
                        <button
                          type="button"
                          onClick={() => void startWith(c.id, c.name)}
                          className="flex w-full items-center gap-3 px-4 py-2 text-left hover:bg-muted"
                        >
                          <Avatar name={c.name} />
                          <span className="min-w-0">
                            <span className="block truncate text-sm">{c.name}</span>
                            <span className="block truncate text-xs text-muted-foreground">
                              {[c.role, c.team].filter(Boolean).join(' · ')}
                            </span>
                          </span>
                        </button>
                      </li>
                    ))}
                  </ul>
                </div>
              ) : (
                <ul className="flex-1 overflow-y-auto">
                  {list.data?.length === 0 ? (
                    <li className="px-6 py-10 text-center text-sm text-muted-foreground">
                      {t('empty')}
                    </li>
                  ) : null}
                  {list.data?.map((c: ConversationDto) => (
                    <li key={c.id}>
                      <button
                        type="button"
                        onClick={() => setActive({ id: c.id, peer: c.peer.name })}
                        className="flex w-full items-center gap-3 px-4 py-3 text-left hover:bg-muted"
                      >
                        <Avatar name={c.peer.name} />
                        <span className="min-w-0 flex-1">
                          <span className="flex items-baseline justify-between gap-2">
                            <span className="truncate text-sm font-medium">{c.peer.name}</span>
                            {c.lastMessage ? (
                              <span className="shrink-0 text-[11px] text-muted-foreground">
                                {time(c.lastMessage.createdAt)}
                              </span>
                            ) : null}
                          </span>
                          <span className="flex items-center justify-between gap-2">
                            <span className="truncate text-xs text-muted-foreground">
                              {c.lastMessage
                                ? `${c.lastMessage.mine ? `${t('you')}: ` : ''}${c.lastMessage.body}`
                                : ''}
                            </span>
                            {c.unread ? (
                              <span className="shrink-0 rounded-full bg-accent px-1.5 text-[11px] font-medium text-white">
                                {c.unread}
                              </span>
                            ) : null}
                          </span>
                        </span>
                      </button>
                    </li>
                  ))}
                </ul>
              )}
            </>
          )}
        </section>
      ) : null}
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        aria-label={count ? t('openUnread', { count }) : t('open')}
        className={cn(
          'fixed bottom-14 right-4 z-40 flex size-12 items-center justify-center rounded-full bg-accent text-white shadow-lg hover:bg-accent/90',
          open && 'max-sm:hidden',
        )}
      >
        {open ? <X className="size-5" /> : <MessageCircle className="size-5" />}
        {count && !open ? (
          <span className="absolute -right-1 -top-1 min-w-5 rounded-full bg-danger px-1 text-center text-[11px] font-semibold leading-5">
            {count > 99 ? '99+' : count}
          </span>
        ) : null}
      </button>
    </>
  );
}

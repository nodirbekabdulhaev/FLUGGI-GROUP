'use client';

import type { SearchHitDto, SearchKind } from '@fluggi/contracts';
import {
  Briefcase,
  CheckSquare,
  FileSignature,
  FolderKanban,
  Handshake,
  Search,
  User,
  UserPlus,
} from 'lucide-react';
import { useRouter } from 'next/navigation';
import { useTranslations } from 'next-intl';
import { useEffect, useMemo, useState } from 'react';
import { Dialog, DialogContent } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { errorMessage } from '@/lib/api-client';
import { cn } from '@/lib/utils';
import { useSearch } from './api';

const ICON: Record<SearchKind, React.ComponentType<{ className?: string }>> = {
  client: Briefcase,
  lead: UserPlus,
  deal: Handshake,
  project: FolderKanban,
  contract: FileSignature,
  task: CheckSquare,
  user: User,
};
const ORDER: SearchKind[] = ['client', 'lead', 'deal', 'project', 'contract', 'task', 'user'];

function useDebounced(value: string, ms = 250) {
  const [v, setV] = useState(value);
  useEffect(() => {
    const id = setTimeout(() => setV(value), ms);
    return () => clearTimeout(id);
  }, [value, ms]);
  return v;
}

/** Глобальный поиск (ТЗ §44): кнопка в шапке и ⌘K / Ctrl+K. Ищет только среди доступных записей. */
export function GlobalSearch() {
  const t = useTranslations('search');
  const router = useRouter();
  const [open, setOpen] = useState(false);
  const [text, setText] = useState('');
  const [active, setActive] = useState(0);
  const query = useDebounced(text);
  const result = useSearch(query);

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        setOpen(true);
      }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, []);
  useEffect(() => {
    setActive(0);
  }, [query]);

  const hits = useMemo(() => {
    const list = query.trim().length >= 2 ? (result.data ?? []) : [];
    return [...list].sort((a, b) => ORDER.indexOf(a.kind) - ORDER.indexOf(b.kind));
  }, [result.data, query]);

  const go = (h: SearchHitDto) => {
    setOpen(false);
    setText('');
    router.push(h.link);
  };

  return (
    <>
      <button
        type="button"
        onClick={() => setOpen(true)}
        className="flex h-9 items-center gap-2 rounded-md border bg-surface px-2.5 text-sm text-muted-foreground hover:bg-muted sm:w-56"
        aria-label={t('open')}
      >
        <Search className="size-4 shrink-0" />
        <span className="hidden flex-1 text-left sm:inline">{t('placeholder')}</span>
        <kbd className="hidden rounded border px-1 text-[10px] sm:inline">⌘K</kbd>
      </button>
      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent title={t('title')} className="sm:top-[15%] sm:max-w-xl sm:translate-y-0">
          <Input
            autoFocus
            role="combobox"
            aria-expanded={hits.length > 0}
            aria-controls="global-search-results"
            placeholder={t('hint')}
            value={text}
            onChange={(e) => setText(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === 'ArrowDown') {
                e.preventDefault();
                setActive((i) => Math.min(hits.length - 1, i + 1));
              } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                setActive((i) => Math.max(0, i - 1));
              } else if (e.key === 'Enter' && hits[active]) {
                e.preventDefault();
                go(hits[active]);
              }
            }}
          />
          <div className="min-h-24">
            {query.trim().length < 2 ? (
              <p className="py-6 text-center text-sm text-muted-foreground">{t('minLength')}</p>
            ) : result.isError ? (
              <p className="py-6 text-center text-sm text-danger">{errorMessage(result.error)}</p>
            ) : result.isPending ? (
              <p className="py-6 text-center text-sm text-muted-foreground">{t('searching')}</p>
            ) : hits.length === 0 ? (
              <p className="py-6 text-center text-sm text-muted-foreground">
                {t('nothing', { q: query })}
              </p>
            ) : (
              <ul id="global-search-results" role="listbox" className="grid gap-0.5">
                {hits.map((h, i) => {
                  const Icon = ICON[h.kind];
                  const first = i === 0 || hits[i - 1]!.kind !== h.kind;
                  return (
                    <li key={`${h.kind}:${h.id}`} role="option" aria-selected={i === active}>
                      {first ? (
                        <p className="px-2 pb-1 pt-2 text-xs font-medium text-muted-foreground">
                          {t(`kinds.${h.kind}`)}
                        </p>
                      ) : null}
                      <button
                        type="button"
                        onMouseEnter={() => setActive(i)}
                        onClick={() => go(h)}
                        className={cn(
                          'flex w-full items-center gap-3 rounded-md px-2 py-2 text-left',
                          i === active && 'bg-muted',
                        )}
                      >
                        <Icon className="size-4 shrink-0 text-muted-foreground" />
                        <span className="min-w-0 flex-1">
                          <span className="block truncate text-sm">{h.title}</span>
                          {h.subtitle ? (
                            <span className="block truncate text-xs text-muted-foreground">
                              {h.subtitle}
                            </span>
                          ) : null}
                        </span>
                      </button>
                    </li>
                  );
                })}
              </ul>
            )}
          </div>
        </DialogContent>
      </Dialog>
    </>
  );
}

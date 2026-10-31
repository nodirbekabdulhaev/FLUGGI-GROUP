'use client';

import type { DocumentCheckDto } from '@fluggi/contracts';
import { useQuery } from '@tanstack/react-query';
import { AlertTriangle, Copy, FileDown, FileText, Printer } from 'lucide-react';
import { useTranslations } from 'next-intl';
import { useRef } from 'react';
import { toast } from 'sonner';
import { ErrorState } from '@/components/shared/states';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent } from '@/components/ui/dialog';
import { Skeleton } from '@/components/ui/skeleton';
import { api } from '@/lib/api-client';

export type DocumentKind = 'proposals' | 'contracts';

const url = (kind: DocumentKind, id: string, format: string, download = false) =>
  `/api/v1/documents/${kind}/${id}?format=${format}${download ? '&download=true' : ''}`;

async function fetchText(u: string) {
  const res = await fetch(u, { credentials: 'same-origin' });
  if (!res.ok) {
    const body = (await res.json().catch(() => null)) as { error?: { message?: string } } | null;
    throw new Error(body?.error?.message ?? 'Не удалось сформировать документ');
  }
  return res.text();
}

/**
 * Предпросмотр документа (КП или договор) с закреплённым дизайном и скачивание:
 * Word, PDF, печать, копирование текста, TXT. Документ формирует сервер с проверкой прав.
 */
export function DocumentDialog({
  kind,
  id,
  title,
  onClose,
}: {
  kind: DocumentKind;
  id: string | null;
  title: string;
  onClose: () => void;
}) {
  const t = useTranslations('documents');
  const frame = useRef<HTMLIFrameElement>(null);
  const html = useQuery({
    queryKey: ['documents', kind, id, 'html'],
    queryFn: () => fetchText(url(kind, id!, 'html')),
    enabled: Boolean(id),
    staleTime: 0,
    gcTime: 0,
  });
  const check = useQuery({
    queryKey: ['documents', kind, id, 'check'],
    queryFn: () => api<DocumentCheckDto>(`/documents/${kind}/${id}/check`),
    enabled: Boolean(id),
    staleTime: 0,
  });

  // Ответ с Content-Disposition: attachment — браузер скачивает файл, страница остаётся на месте
  const download = (format: 'docx' | 'pdf' | 'txt') => {
    window.location.href = url(kind, id!, format, true);
  };

  return (
    <Dialog open={Boolean(id)} onOpenChange={(o) => !o && onClose()}>
      <DialogContent title={title} className="sm:max-w-5xl">
        <div className="flex flex-wrap gap-2">
          <Button size="sm" onClick={() => download('docx')}>
            <FileDown /> {t('word')}
          </Button>
          <Button size="sm" variant="outline" onClick={() => download('pdf')}>
            <FileDown /> {t('pdf')}
          </Button>
          <Button
            size="sm"
            variant="outline"
            disabled={!html.data}
            onClick={() => frame.current?.contentWindow?.print()}
          >
            <Printer /> {t('print')}
          </Button>
          <Button
            size="sm"
            variant="outline"
            onClick={async () => {
              try {
                await navigator.clipboard.writeText(await fetchText(url(kind, id!, 'txt')));
                toast.success(t('copied'));
              } catch (err) {
                toast.error((err as Error).message);
              }
            }}
          >
            <Copy /> {t('copy')}
          </Button>
          <Button size="sm" variant="ghost" onClick={() => download('txt')}>
            <FileText /> {t('txt')}
          </Button>
        </div>
        {check.data && check.data.missing.length > 0 ? (
          <div className="flex gap-2 rounded-md border border-warning/40 bg-warning-soft p-3 text-sm">
            <AlertTriangle className="mt-0.5 size-4 shrink-0 text-warning" />
            <div>
              <p className="font-medium">{t('missingTitle')}</p>
              <ul className="list-disc pl-5">
                {check.data.missing.map((m) => (
                  <li key={m}>{m}</li>
                ))}
              </ul>
            </div>
          </div>
        ) : null}
        {html.isPending ? (
          <Skeleton className="h-[60vh]" />
        ) : html.isError ? (
          <ErrorState error={html.error} onRetry={() => html.refetch()} />
        ) : (
          <iframe
            ref={frame}
            title={t('preview')}
            srcDoc={html.data}
            sandbox="allow-modals allow-same-origin"
            className="h-[65vh] w-full rounded-md border bg-muted"
          />
        )}
      </DialogContent>
    </Dialog>
  );
}

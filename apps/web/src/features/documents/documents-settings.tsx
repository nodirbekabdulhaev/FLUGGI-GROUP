'use client';

import {
  CONTRACT_PLACEHOLDERS,
  DEFAULT_CONTRACT_TEMPLATE,
  type DocumentSettings,
} from '@fluggi/contracts';
import { useQuery } from '@tanstack/react-query';
import { useTranslations } from 'next-intl';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { ErrorState, TableSkeleton } from '@/components/shared/states';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Field } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { CompanySettingsCard } from '@/features/automation/company-settings';
import { useInvalidating } from '@/features/automation/api';
import { ApiError, api, errorMessage } from '@/lib/api-client';

function TemplatesCard() {
  const t = useTranslations('documents');
  const q = useQuery({
    queryKey: ['settings', 'documents'],
    queryFn: () => api<DocumentSettings>('/settings/documents'),
  });
  const [v, setV] = useState<DocumentSettings | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const area = useRef<HTMLTextAreaElement>(null);
  useEffect(() => {
    if (q.data) setV(q.data);
  }, [q.data]);
  const save = useInvalidating([['settings', 'documents'], ['documents']], () =>
    api<DocumentSettings>('/settings/documents', { method: 'PUT', body: v }),
  );

  const insert = (key: string) => {
    const el = area.current;
    if (!el || !v) return;
    const token = `{{${key}}}`;
    const { selectionStart: a, selectionEnd: b } = el;
    const next = v.contractTemplate.slice(0, a) + token + v.contractTemplate.slice(b);
    setV({ ...v, contractTemplate: next });
    requestAnimationFrame(() => {
      el.focus();
      el.setSelectionRange(a + token.length, a + token.length);
    });
  };

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('templatesTitle')}</CardTitle>
        <CardDescription>{t('templatesText')}</CardDescription>
      </CardHeader>
      <CardContent>
        {q.isError ? (
          <ErrorState error={q.error} onRetry={() => q.refetch()} />
        ) : !v ? (
          <TableSkeleton rows={4} cols={1} />
        ) : (
          <form
            className="grid gap-4"
            onSubmit={async (e) => {
              e.preventDefault();
              setErrors({});
              try {
                await save.mutateAsync(undefined);
                toast.success(t('saved'));
              } catch (err) {
                if (err instanceof ApiError) setErrors(err.fieldErrors());
                toast.error(errorMessage(err));
              }
            }}
          >
            <Field label={t('city')} htmlFor="ds-city" error={errors.city}>
              <Input
                id="ds-city"
                className="sm:w-64"
                value={v.city}
                onChange={(e) => setV({ ...v, city: e.target.value })}
              />
            </Field>
            <Field label={t('proposalIntro')} htmlFor="ds-intro">
              <Textarea
                id="ds-intro"
                rows={2}
                value={v.proposalIntro}
                onChange={(e) => setV({ ...v, proposalIntro: e.target.value })}
              />
            </Field>
            <Field label={t('proposalNote')} htmlFor="ds-note" hint={t('proposalNoteHint')}>
              <Textarea
                id="ds-note"
                rows={2}
                value={v.proposalNote}
                onChange={(e) => setV({ ...v, proposalNote: e.target.value })}
              />
            </Field>
            <div className="grid gap-4 lg:grid-cols-[1fr_16rem]">
              <Field
                label={t('contractTemplate')}
                htmlFor="ds-contract"
                hint={t('contractTemplateHint')}
                error={errors.contractTemplate}
              >
                <Textarea
                  id="ds-contract"
                  ref={area}
                  rows={22}
                  className="font-mono text-xs"
                  value={v.contractTemplate}
                  onChange={(e) => setV({ ...v, contractTemplate: e.target.value })}
                />
              </Field>
              <div className="grid content-start gap-1 text-xs">
                <p className="text-sm font-medium">{t('placeholders')}</p>
                <p className="text-muted-foreground">{t('placeholdersText')}</p>
                {CONTRACT_PLACEHOLDERS.map((p) => (
                  <button
                    key={p.key}
                    type="button"
                    className="rounded border px-2 py-1 text-left hover:bg-muted"
                    onClick={() => insert(p.key)}
                  >
                    <code className="text-accent">{`{{${p.key}}}`}</code>
                    <span className="block text-muted-foreground">{p.label}</span>
                  </button>
                ))}
                <p className="mt-2 text-muted-foreground">{t('blocksText')}</p>
              </div>
            </div>
            <div className="flex flex-wrap gap-2">
              <Button type="submit" loading={save.isPending}>
                {t('saveTemplates')}
              </Button>
              <Button
                type="button"
                variant="outline"
                onClick={() => {
                  if (window.confirm(t('resetConfirm')))
                    setV({ ...v, contractTemplate: DEFAULT_CONTRACT_TEMPLATE });
                }}
              >
                {t('reset')}
              </Button>
            </div>
          </form>
        )}
      </CardContent>
    </Card>
  );
}

/** Настройки → «Документы»: реквизиты компании и шаблоны КП и договора. */
export function DocumentsSettingsPage() {
  return (
    <div className="grid gap-6">
      <CompanySettingsCard />
      <TemplatesCard />
    </div>
  );
}

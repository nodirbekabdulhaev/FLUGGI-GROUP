'use client';

import {
  DEFAULT_FORM_FIELDS,
  FORM_FIELD_TYPES,
  type FormField,
  type FormFieldType,
  type IntegrationSettings,
  type LeadFormDto,
} from '@fluggi/contracts';
import { Code2, Copy, ExternalLink, Inbox, Pencil, Plus, Trash2 } from 'lucide-react';
import { useTranslations } from 'next-intl';
import Link from 'next/link';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { EmptyState, ErrorState, TableSkeleton } from '@/components/shared/states';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogFooter } from '@/components/ui/dialog';
import { Input, NativeSelect } from '@/components/ui/input';
import { Field } from '@/components/ui/label';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import { useReferences } from '@/features/crm/api';
import { useTeams, useUsers } from '@/features/team/api';
import { ApiError, api, errorMessage } from '@/lib/api-client';
import { dateTime } from '@/lib/format';
import {
  useFormSubmissions,
  useIntegrationMutation,
  useIntegrationSettings,
  useLeadForms,
  useMetaStatus,
} from './api';

const origin = () => (typeof window === 'undefined' ? '' : window.location.origin);

async function copy(text: string, done: string) {
  try {
    await navigator.clipboard.writeText(text);
    toast.success(done);
  } catch {
    toast.error('Не удалось скопировать — выделите текст вручную');
  }
}

/** Кнопки «назначить ответственного»: сотрудник или отдел (по очереди). */
function OwnerFields({
  ownerId,
  teamId,
  onChange,
  prefix,
}: {
  ownerId: string;
  teamId: string;
  onChange: (v: { ownerId?: string; teamId?: string }) => void;
  prefix: string;
}) {
  const t = useTranslations('integrations');
  const users = useUsers({ pageSize: 100, status: 'ACTIVE' });
  const teams = useTeams();
  return (
    <>
      <Field label={t('owner')} htmlFor={`${prefix}-owner`} hint={t('ownerHint')}>
        <NativeSelect
          id={`${prefix}-owner`}
          value={ownerId}
          onChange={(e) => onChange({ ownerId: e.target.value })}
        >
          <option value="">{t('ownerAuto')}</option>
          {users.data?.items
            .filter((u) => ['MANAGER', 'ROP'].includes(u.role.code))
            .map((u) => (
              <option key={u.id} value={u.id}>
                {u.fullName}
              </option>
            ))}
        </NativeSelect>
      </Field>
      <Field label={t('team')} htmlFor={`${prefix}-team`}>
        <NativeSelect
          id={`${prefix}-team`}
          value={teamId}
          onChange={(e) => onChange({ teamId: e.target.value })}
        >
          <option value="">{t('anyTeam')}</option>
          {teams.data?.map((tm) => (
            <option key={tm.id} value={tm.id}>
              {tm.name}
            </option>
          ))}
        </NativeSelect>
      </Field>
    </>
  );
}

function FormDialog({
  form,
  open,
  onOpenChange,
}: {
  form: LeadFormDto | null;
  open: boolean;
  onOpenChange: (o: boolean) => void;
}) {
  const t = useTranslations('integrations');
  const refs = useReferences();
  const empty = {
    name: '',
    title: 'Оставьте заявку',
    description: '',
    buttonText: 'Отправить',
    successMessage: 'Спасибо! Мы свяжемся с вами в ближайшее время.',
    serviceId: '',
    sourceId: '',
    ownerId: '',
    teamId: '',
    isActive: true,
  };
  const [v, setV] = useState(empty);
  const [fields, setFields] = useState<FormField[]>(DEFAULT_FORM_FIELDS);
  const [errors, setErrors] = useState<Record<string, string>>({});
  useEffect(() => {
    if (!open) return;
    setErrors({});
    setV(
      form
        ? {
            name: form.name,
            title: form.title,
            description: form.description ?? '',
            buttonText: form.buttonText,
            successMessage: form.successMessage,
            serviceId: form.serviceId ?? '',
            sourceId: form.sourceId ?? '',
            ownerId: form.owner?.id ?? '',
            teamId: form.teamId ?? '',
            isActive: form.isActive,
          }
        : empty,
    );
    setFields(form ? form.fields : DEFAULT_FORM_FIELDS);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, form]);
  const save = useIntegrationMutation(() => {
    const body = {
      ...v,
      description: v.description || null,
      serviceId: v.serviceId || null,
      sourceId: v.sourceId || null,
      ownerId: v.ownerId || null,
      teamId: v.teamId || null,
      fields: fields.map((f) => ({
        ...f,
        options: f.type === 'select' ? (f.options ?? []).filter(Boolean) : undefined,
      })),
    };
    return form
      ? api(`/lead-forms/${form.id}`, { method: 'PUT', body })
      : api('/lead-forms', { method: 'POST', body });
  });
  const patch = (i: number, p: Partial<FormField>) =>
    setFields((s) => s.map((f, j) => (j === i ? { ...f, ...p } : f)));
  const set =
    (k: keyof typeof empty) =>
    (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>) =>
      setV((s) => ({ ...s, [k]: e.target.value }));

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent title={form ? t('formEdit') : t('formNew')} className="sm:max-w-3xl">
        <form
          className="grid gap-4"
          onSubmit={async (e) => {
            e.preventDefault();
            try {
              await save.mutateAsync(undefined);
              toast.success(t('formSaved'));
              onOpenChange(false);
            } catch (err) {
              if (err instanceof ApiError) setErrors(err.fieldErrors());
              toast.error(errorMessage(err));
            }
          }}
        >
          <div className="grid gap-4 sm:grid-cols-2">
            <Field
              label={t('formName')}
              htmlFor="lf-name"
              error={errors.name}
              hint={t('formNameHint')}
            >
              <Input id="lf-name" required value={v.name} onChange={set('name')} />
            </Field>
            <Field label={t('formTitle')} htmlFor="lf-title" error={errors.title}>
              <Input id="lf-title" required value={v.title} onChange={set('title')} />
            </Field>
          </div>
          <Field label={t('formDescription')} htmlFor="lf-desc">
            <Textarea id="lf-desc" rows={2} value={v.description} onChange={set('description')} />
          </Field>

          <div className="grid gap-2">
            <div className="flex items-center justify-between gap-2">
              <span className="text-sm font-medium">{t('fields')}</span>
              <Button
                type="button"
                size="sm"
                variant="outline"
                onClick={() =>
                  setFields((s) => [
                    ...s,
                    { key: `field_${s.length + 1}`, label: '', type: 'text', required: false },
                  ])
                }
              >
                <Plus className="size-4" /> {t('addField')}
              </Button>
            </div>
            <p className="text-xs text-muted-foreground">{t('fieldsHint')}</p>
            {errors.fields ? <p className="text-xs text-danger">{errors.fields}</p> : null}
            {fields.map((f, i) => (
              <div
                key={i}
                className="grid gap-2 rounded-md border p-2 sm:grid-cols-[1fr_8rem_8rem_auto_2rem] sm:items-center"
              >
                <Input
                  aria-label={`${t('fieldLabel')} ${i + 1}`}
                  placeholder={t('fieldLabel')}
                  required
                  value={f.label}
                  onChange={(e) => patch(i, { label: e.target.value })}
                />
                <Input
                  aria-label={`${t('fieldKey')} ${i + 1}`}
                  placeholder="key"
                  className="font-mono text-xs"
                  value={f.key}
                  onChange={(e) =>
                    patch(i, { key: e.target.value.toLowerCase().replace(/[^a-z0-9_]/g, '_') })
                  }
                />
                <NativeSelect
                  aria-label={`${t('fieldType')} ${i + 1}`}
                  value={f.type}
                  onChange={(e) => patch(i, { type: e.target.value as FormFieldType })}
                >
                  {FORM_FIELD_TYPES.map((ft) => (
                    <option key={ft} value={ft}>
                      {t(`types.${ft}`)}
                    </option>
                  ))}
                </NativeSelect>
                <label className="flex items-center gap-1 text-xs">
                  <input
                    type="checkbox"
                    checked={f.required}
                    onChange={(e) => patch(i, { required: e.target.checked })}
                  />
                  {t('required')}
                </label>
                <Button
                  type="button"
                  size="icon-sm"
                  variant="ghost"
                  aria-label={t('removeField')}
                  onClick={() => setFields((s) => s.filter((_, j) => j !== i))}
                >
                  <Trash2 />
                </Button>
                {f.type === 'select' ? (
                  <Input
                    className="sm:col-span-5"
                    aria-label={`${t('options')} ${i + 1}`}
                    placeholder={t('optionsHint')}
                    value={(f.options ?? []).join('; ')}
                    onChange={(e) =>
                      patch(i, { options: e.target.value.split(';').map((x) => x.trim()) })
                    }
                  />
                ) : null}
              </div>
            ))}
          </div>

          <div className="grid gap-4 sm:grid-cols-2">
            <Field label={t('buttonText')} htmlFor="lf-btn">
              <Input id="lf-btn" value={v.buttonText} onChange={set('buttonText')} />
            </Field>
            <Field label={t('successMessage')} htmlFor="lf-ok">
              <Input id="lf-ok" value={v.successMessage} onChange={set('successMessage')} />
            </Field>
            <Field label={t('service')} htmlFor="lf-svc" hint={t('serviceHint')}>
              <NativeSelect id="lf-svc" value={v.serviceId} onChange={set('serviceId')}>
                <option value="">—</option>
                {refs.data?.services.map((s) => (
                  <option key={s.id} value={s.id}>
                    {s.name}
                  </option>
                ))}
              </NativeSelect>
            </Field>
            <Field label={t('source')} htmlFor="lf-src">
              <NativeSelect id="lf-src" value={v.sourceId} onChange={set('sourceId')}>
                <option value="">{t('sourceDefault')}</option>
                {refs.data?.sources.map((s) => (
                  <option key={s.id} value={s.id}>
                    {s.name}
                  </option>
                ))}
              </NativeSelect>
            </Field>
            <OwnerFields
              prefix="lf"
              ownerId={v.ownerId}
              teamId={v.teamId}
              onChange={(p) => setV((s) => ({ ...s, ...p }))}
            />
          </div>
          <label className="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              checked={v.isActive}
              onChange={(e) => setV((s) => ({ ...s, isActive: e.target.checked }))}
            />
            {t('active')}
          </label>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
              {t('cancel')}
            </Button>
            <Button type="submit" loading={save.isPending}>
              {t('save')}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

function CodeDialog({ form, onClose }: { form: LeadFormDto | null; onClose: () => void }) {
  const t = useTranslations('integrations');
  if (!form) return null;
  const embed = `<div data-fluggi-form="${form.key}"></div>\n<script src="${origin()}/embed.js" async></script>`;
  const link = `${origin()}/f/${form.key}`;
  const webhook = `${origin()}/api/v1/public/forms/${form.key}`;
  const block = (title: string, hint: string, text: string, testId: string) => (
    <div className="grid gap-1">
      <p className="text-sm font-medium">{title}</p>
      <p className="text-xs text-muted-foreground">{hint}</p>
      <div className="flex gap-2">
        <pre
          data-testid={testId}
          className="flex-1 overflow-x-auto whitespace-pre-wrap break-all rounded-md bg-muted p-2 font-mono text-xs"
        >
          {text}
        </pre>
        <Button
          size="icon-sm"
          variant="outline"
          aria-label={`${t('copy')}: ${title}`}
          onClick={() => copy(text, t('copied'))}
        >
          <Copy />
        </Button>
      </div>
    </div>
  );
  return (
    <Dialog open onOpenChange={(o) => !o && onClose()}>
      <DialogContent title={`${t('code')}: ${form.name}`} className="sm:max-w-2xl">
        <div className="grid gap-4">
          {block(t('embedTitle'), t('embedHint'), embed, 'embed-code')}
          {block(t('linkTitle'), t('linkHint'), link, 'form-link')}
          {block(t('webhookTitle'), t('webhookHint'), webhook, 'form-webhook')}
          <a
            href={link}
            target="_blank"
            rel="noopener noreferrer"
            className="inline-flex items-center gap-1 text-sm text-accent hover:underline"
          >
            <ExternalLink className="size-4" /> {t('preview')}
          </a>
        </div>
      </DialogContent>
    </Dialog>
  );
}

function SubmissionsDialog({ form, onClose }: { form: LeadFormDto | null; onClose: () => void }) {
  const t = useTranslations('integrations');
  const list = useFormSubmissions(form?.id ?? null);
  return (
    <Dialog open={Boolean(form)} onOpenChange={(o) => !o && onClose()}>
      <DialogContent title={`${t('submissions')}: ${form?.name ?? ''}`} className="sm:max-w-3xl">
        {list.isPending ? (
          <TableSkeleton rows={4} cols={3} />
        ) : list.isError ? (
          <ErrorState error={list.error} onRetry={() => list.refetch()} />
        ) : list.data.length === 0 ? (
          <EmptyState icon={Inbox} title={t('noSubmissions')} />
        ) : (
          <div className="max-h-[60vh] overflow-y-auto">
            <Table>
              <THead>
                <tr>
                  <TH>{t('when')}</TH>
                  <TH>{t('data')}</TH>
                  <TH>{t('result')}</TH>
                </tr>
              </THead>
              <TBody>
                {list.data.map((s) => (
                  <TR key={s.id}>
                    <TD className="whitespace-nowrap text-xs">{dateTime(s.createdAt)}</TD>
                    <TD className="text-xs">
                      {Object.entries(s.data)
                        .map(([k, v]) => `${k}: ${v}`)
                        .join(' · ')}
                      {s.utm ? (
                        <span className="block text-muted-foreground">
                          {Object.entries(s.utm)
                            .map(([k, v]) => `${k}=${v}`)
                            .join(', ')}
                        </span>
                      ) : null}
                    </TD>
                    <TD className="whitespace-nowrap text-xs">
                      <Badge
                        tone={
                          s.result === 'LEAD_CREATED'
                            ? 'success'
                            : s.result === 'SPAM'
                              ? 'danger'
                              : 'neutral'
                        }
                      >
                        {t(`results.${s.result}`)}
                      </Badge>
                      {s.lead ? (
                        <Link
                          href={`/sales/leads/${s.lead.id}`}
                          className="ml-1 text-accent hover:underline"
                        >
                          {s.lead.number}
                        </Link>
                      ) : null}
                    </TD>
                  </TR>
                ))}
              </TBody>
            </Table>
          </div>
        )}
      </DialogContent>
    </Dialog>
  );
}

function FormsCard() {
  const t = useTranslations('integrations');
  const list = useLeadForms();
  const [dialog, setDialog] = useState<{ open: boolean; form: LeadFormDto | null }>({
    open: false,
    form: null,
  });
  const [code, setCode] = useState<LeadFormDto | null>(null);
  const [subs, setSubs] = useState<LeadFormDto | null>(null);
  const remove = useIntegrationMutation((id: string) =>
    api(`/lead-forms/${id}`, { method: 'DELETE' }),
  );
  return (
    <Card>
      <CardHeader className="flex flex-row flex-wrap items-start justify-between gap-3">
        <div>
          <CardTitle>{t('formsTitle')}</CardTitle>
          <CardDescription>{t('formsText')}</CardDescription>
        </div>
        <Button size="sm" onClick={() => setDialog({ open: true, form: null })}>
          <Plus className="size-4" /> {t('formNew')}
        </Button>
      </CardHeader>
      {list.isPending ? (
        <TableSkeleton rows={3} cols={3} />
      ) : list.isError ? (
        <ErrorState error={list.error} onRetry={() => list.refetch()} />
      ) : list.data.length === 0 ? (
        <EmptyState icon={Code2} title={t('noForms')} text={t('noFormsText')} />
      ) : (
        <Table>
          <THead>
            <tr>
              <TH>{t('formName')}</TH>
              <TH className="text-right">{t('stats')}</TH>
              <TH>{t('last')}</TH>
              <TH />
            </tr>
          </THead>
          <TBody>
            {list.data.map((f) => (
              <TR key={f.id} className={f.isActive ? '' : 'opacity-60'}>
                <TD>
                  <span className="font-medium">{f.name}</span>{' '}
                  {!f.isActive ? <Badge>{t('off')}</Badge> : null}
                  <span className="block text-xs text-muted-foreground">
                    {f.owner?.name ?? t('ownerAuto')}
                  </span>
                </TD>
                <TD className="whitespace-nowrap text-right tabular-nums">
                  {t('statsValue', { submissions: f.submissions, leads: f.leads })}
                </TD>
                <TD className="whitespace-nowrap text-xs text-muted-foreground">
                  {f.lastSubmissionAt ? dateTime(f.lastSubmissionAt) : '—'}
                </TD>
                <TD className="whitespace-nowrap text-right">
                  <Button size="sm" variant="outline" onClick={() => setCode(f)}>
                    <Code2 className="size-4" /> {t('code')}
                  </Button>
                  <Button
                    size="icon-sm"
                    variant="ghost"
                    aria-label={`${t('submissions')}: ${f.name}`}
                    onClick={() => setSubs(f)}
                  >
                    <Inbox />
                  </Button>
                  <Button
                    size="icon-sm"
                    variant="ghost"
                    aria-label={`${t('formEdit')}: ${f.name}`}
                    onClick={() => setDialog({ open: true, form: f })}
                  >
                    <Pencil />
                  </Button>
                  <Button
                    size="icon-sm"
                    variant="ghost"
                    aria-label={`${t('delete')}: ${f.name}`}
                    onClick={async () => {
                      if (!window.confirm(t('deleteConfirm', { name: f.name }))) return;
                      try {
                        await remove.mutateAsync(f.id);
                      } catch (err) {
                        toast.error(errorMessage(err));
                      }
                    }}
                  >
                    <Trash2 />
                  </Button>
                </TD>
              </TR>
            ))}
          </TBody>
        </Table>
      )}
      <FormDialog
        form={dialog.form}
        open={dialog.open}
        onOpenChange={(o) => setDialog((s) => ({ ...s, open: o }))}
      />
      <CodeDialog form={code} onClose={() => setCode(null)} />
      <SubmissionsDialog form={subs} onClose={() => setSubs(null)} />
    </Card>
  );
}

function MetaCard() {
  const t = useTranslations('integrations');
  const status = useMetaStatus();
  const settings = useIntegrationSettings();
  const refs = useReferences();
  const [v, setV] = useState<IntegrationSettings | null>(null);
  const [keywords, setKeywords] = useState('');
  useEffect(() => {
    if (!settings.data) return;
    setV(settings.data);
    setKeywords(settings.data.commentKeywords.join(', '));
  }, [settings.data]);
  const save = useIntegrationMutation(() =>
    api('/settings/integrations', {
      method: 'PUT',
      body: {
        ...v,
        commentKeywords: keywords
          .split(',')
          .map((k) => k.trim())
          .filter((k) => k.length >= 2),
      },
    }),
  );
  const s = status.data;
  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('metaTitle')}</CardTitle>
        <CardDescription>{t('metaText')}</CardDescription>
      </CardHeader>
      <CardContent className="grid gap-6">
        {s ? (
          <div className="grid gap-2 rounded-lg border p-3 text-sm">
            <p>
              {s.configured ? (
                <Badge tone="success">{t('metaOn')}</Badge>
              ) : (
                <Badge tone="warning">{t('metaOff')}</Badge>
              )}{' '}
              {!s.configured ? (
                <span className="text-muted-foreground">
                  {t('metaMissing')} <code className="text-xs">{s.missing.join(', ')}</code>
                </span>
              ) : null}
            </p>
            <div className="flex flex-wrap items-center gap-2">
              <span className="text-muted-foreground">{t('webhookUrl')}:</span>
              <code className="break-all text-xs" data-testid="meta-webhook">
                {s.webhookUrl}
              </code>
              <Button
                size="icon-sm"
                variant="ghost"
                aria-label={t('copy')}
                onClick={() => copy(s.webhookUrl, t('copied'))}
              >
                <Copy />
              </Button>
            </div>
            <p className="text-muted-foreground">
              {t('metaStats', { threads: s.threads, ads: s.adsLeads })}
              {s.lastEventAt ? ` · ${t('lastEvent')}: ${dateTime(s.lastEventAt)}` : ''}
            </p>
            {s.lastError ? (
              <p className="text-danger">
                {t('lastError')}: {s.lastError}
              </p>
            ) : null}
            <details className="text-muted-foreground">
              <summary className="cursor-pointer text-foreground">{t('howTo')}</summary>
              <ol className="mt-2 list-decimal space-y-1 pl-5 text-xs">
                <li>{t('how1')}</li>
                <li>{t('how2')}</li>
                <li>{t('how3')}</li>
                <li>{t('how4')}</li>
                <li>{t('how5')}</li>
                <li>{t('how6')}</li>
              </ol>
            </details>
          </div>
        ) : null}

        {v ? (
          <form
            className="grid gap-4 sm:grid-cols-2"
            onSubmit={async (e) => {
              e.preventDefault();
              try {
                await save.mutateAsync(undefined);
                toast.success(t('settingsSaved'));
              } catch (err) {
                toast.error(errorMessage(err));
              }
            }}
          >
            <OwnerFields
              prefix="meta"
              ownerId={v.ownerId ?? ''}
              teamId={v.teamId ?? ''}
              onChange={(p) =>
                setV((s) =>
                  s
                    ? {
                        ...s,
                        ...(p.ownerId !== undefined ? { ownerId: p.ownerId || null } : {}),
                        ...(p.teamId !== undefined ? { teamId: p.teamId || null } : {}),
                      }
                    : s,
                )
              }
            />
            <Field label={t('service')} htmlFor="meta-svc">
              <NativeSelect
                id="meta-svc"
                value={v.serviceId ?? ''}
                onChange={(e) => setV({ ...v, serviceId: e.target.value || null })}
              >
                <option value="">—</option>
                {refs.data?.services.map((sv) => (
                  <option key={sv.id} value={sv.id}>
                    {sv.name}
                  </option>
                ))}
              </NativeSelect>
            </Field>
            <Field label={t('commentsMode')} htmlFor="meta-comments">
              <NativeSelect
                id="meta-comments"
                value={v.autoLeadFromComments}
                onChange={(e) =>
                  setV({
                    ...v,
                    autoLeadFromComments: e.target
                      .value as IntegrationSettings['autoLeadFromComments'],
                  })
                }
              >
                <option value="keywords">{t('commentsKeywords')}</option>
                <option value="all">{t('commentsAll')}</option>
                <option value="off">{t('commentsOff')}</option>
              </NativeSelect>
            </Field>
            <label className="flex items-center gap-2 text-sm sm:col-span-2">
              <input
                type="checkbox"
                checked={v.autoLeadFromDirect}
                onChange={(e) => setV({ ...v, autoLeadFromDirect: e.target.checked })}
              />
              {t('directAuto')}
            </label>
            <div className="sm:col-span-2">
              <Field label={t('keywords')} htmlFor="meta-kw" hint={t('keywordsHint')}>
                <Input
                  id="meta-kw"
                  value={keywords}
                  onChange={(e) => setKeywords(e.target.value)}
                />
              </Field>
            </div>
            <div>
              <Button type="submit" loading={save.isPending}>
                {t('save')}
              </Button>
            </div>
          </form>
        ) : null}
      </CardContent>
    </Card>
  );
}

/** Настройки → «Интеграции»: формы для сайта, Instagram/Facebook и таргет. */
export function IntegrationsSettingsPage() {
  return (
    <div className="grid gap-6">
      <FormsCard />
      <MetaCard />
    </div>
  );
}

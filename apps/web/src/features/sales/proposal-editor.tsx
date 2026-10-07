'use client';

import { CURRENCIES, type Currency, type ProposalDto } from '@fluggi/contracts';
import { Plus, Trash2 } from 'lucide-react';
import { useTranslations } from 'next-intl';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter } from '@/components/ui/dialog';
import { Input, NativeSelect } from '@/components/ui/input';
import { Field } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useCrmMutation, useReferences } from '@/features/crm/api';
import { ApiError, api, errorMessage } from '@/lib/api-client';
import { money, newIdempotencyKey } from '@/lib/format';

interface Line {
  serviceId: string;
  description: string;
  quantity: string;
  unitPrice: string;
  discountPct: string;
}

const emptyLine = (): Line => ({
  serviceId: '',
  description: '',
  quantity: '1',
  unitPrice: '',
  discountPct: '0',
});
const n = (v: string) =>
  Number.isFinite(Number(v.replace(',', '.'))) ? Number(v.replace(',', '.')) : 0;

/** Редактор КП: позиции со скидками, итог считается на лету (сервер пересчитывает сам). */
export function ProposalEditor({
  open,
  onOpenChange,
  dealId,
  proposal,
}: {
  open: boolean;
  onOpenChange: (o: boolean) => void;
  dealId: string;
  proposal?: ProposalDto | null;
}) {
  const t = useTranslations('sales.proposals');
  const refs = useReferences();
  const [title, setTitle] = useState('');
  const [currency, setCurrency] = useState<Currency>('UZS');
  const [lines, setLines] = useState<Line[]>([emptyLine()]);
  const [description, setDescription] = useState('');
  const [term, setTerm] = useState('');
  const [payTerms, setPayTerms] = useState('');
  const [validUntil, setValidUntil] = useState('');
  const [versionComment, setVersionComment] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const key = useMemo(() => (open ? newIdempotencyKey() : ''), [open]);

  useEffect(() => {
    if (!open) return;
    setErrors({});
    setTitle(proposal?.title ?? '');
    setCurrency(proposal?.currency ?? 'UZS');
    setDescription(proposal?.description ?? '');
    setTerm(proposal?.implementationTerm ?? '');
    setPayTerms(proposal?.paymentTerms ?? '');
    setValidUntil(proposal?.validUntil ?? '');
    setVersionComment('');
    setLines(
      proposal?.items.length
        ? proposal.items.map((i) => ({
            serviceId: i.service?.id ?? '',
            description: i.description,
            quantity: String(Number(i.quantity)),
            unitPrice: String(Number(i.unitPrice)),
            discountPct: String(Number(i.discountPct)),
          }))
        : [emptyLine()],
    );
  }, [open, proposal]);

  const totals = lines.reduce(
    (acc, l) => {
      const gross = n(l.quantity) * n(l.unitPrice);
      const disc = (gross * Math.min(100, Math.max(0, n(l.discountPct)))) / 100;
      return { sub: acc.sub + gross, disc: acc.disc + disc };
    },
    { sub: 0, disc: 0 },
  );

  const setLine = (i: number, k: keyof Line, v: string) =>
    setLines((ls) => ls.map((l, idx) => (idx === i ? { ...l, [k]: v } : l)));

  const save = useCrmMutation(() => {
    const body = {
      title,
      description: description || null,
      currency,
      implementationTerm: term || null,
      paymentTerms: payTerms || null,
      validUntil: validUntil || null,
      versionComment: versionComment || undefined,
      items: lines.map((l) => ({
        serviceId: l.serviceId || null,
        description: l.description,
        quantity: n(l.quantity),
        unitPrice: l.unitPrice,
        discountPct: n(l.discountPct),
      })),
    };
    return proposal
      ? api<ProposalDto>(`/proposals/${proposal.id}`, { method: 'PUT', body })
      : api<ProposalDto>('/proposals', {
          method: 'POST',
          body: { ...body, dealId },
          idempotencyKey: key,
        });
  });

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        title={proposal ? `${t('edit')} ${proposal.number}` : t('new')}
        description={proposal && proposal.status !== 'DRAFT' ? t('editResets') : undefined}
        className="sm:max-w-4xl"
      >
        <form
          className="grid gap-4"
          noValidate
          onSubmit={async (e) => {
            e.preventDefault();
            setErrors({});
            try {
              const saved = await save.mutateAsync(undefined);
              toast.success(t('saved', { v: saved.currentVersion }));
              onOpenChange(false);
            } catch (err) {
              if (err instanceof ApiError) setErrors(err.fieldErrors());
              toast.error(errorMessage(err));
            }
          }}
        >
          <div className="grid gap-4 sm:grid-cols-[1fr_7rem]">
            <Field label="Название" htmlFor="kp-title" error={errors.title}>
              <Input
                id="kp-title"
                value={title}
                onChange={(e) => setTitle(e.target.value)}
                autoFocus
              />
            </Field>
            <Field label="Валюта" htmlFor="kp-cur">
              <NativeSelect
                id="kp-cur"
                value={currency}
                onChange={(e) => setCurrency(e.target.value as Currency)}
              >
                {CURRENCIES.map((c) => (
                  <option key={c}>{c}</option>
                ))}
              </NativeSelect>
            </Field>
          </div>

          <div>
            <p className="mb-2 text-sm font-medium">{t('items')}</p>
            <div className="grid gap-2">
              {lines.map((l, i) => {
                const gross = n(l.quantity) * n(l.unitPrice);
                const total = gross - (gross * Math.min(100, n(l.discountPct))) / 100;
                return (
                  <div
                    key={i}
                    className="grid grid-cols-2 gap-2 rounded-md border p-2 sm:grid-cols-[10rem_1fr_5rem_8rem_5rem_8rem_2rem] sm:items-center sm:border-0 sm:p-0"
                  >
                    <NativeSelect
                      aria-label="Услуга"
                      className="h-9"
                      value={l.serviceId}
                      onChange={(e) => {
                        const svc = refs.data?.services.find((s) => s.id === e.target.value);
                        setLines((ls) =>
                          ls.map((x, idx) =>
                            idx === i
                              ? {
                                  ...x,
                                  serviceId: e.target.value,
                                  description: x.description || svc?.name || '',
                                  unitPrice:
                                    x.unitPrice ||
                                    (svc?.basePrice ? String(Number(svc.basePrice)) : ''),
                                }
                              : x,
                          ),
                        );
                      }}
                    >
                      <option value="">Услуга</option>
                      {refs.data?.services
                        .filter((s) => s.isActive || s.id === l.serviceId)
                        .map((s) => (
                          <option key={s.id} value={s.id}>
                            {s.name}
                          </option>
                        ))}
                    </NativeSelect>
                    <Input
                      aria-label={t('description')}
                      placeholder={t('description')}
                      className="h-9"
                      value={l.description}
                      onChange={(e) => setLine(i, 'description', e.target.value)}
                      aria-invalid={Boolean(errors[`items.${i}.description`])}
                    />
                    <Input
                      aria-label={t('qty')}
                      className="h-9"
                      inputMode="decimal"
                      value={l.quantity}
                      onChange={(e) => setLine(i, 'quantity', e.target.value)}
                    />
                    <Input
                      aria-label={t('price')}
                      placeholder={t('price')}
                      className="h-9"
                      inputMode="decimal"
                      value={l.unitPrice}
                      onChange={(e) => setLine(i, 'unitPrice', e.target.value)}
                      aria-invalid={Boolean(errors[`items.${i}.unitPrice`])}
                    />
                    <Input
                      aria-label={t('discount')}
                      className="h-9"
                      inputMode="decimal"
                      value={l.discountPct}
                      onChange={(e) => setLine(i, 'discountPct', e.target.value)}
                    />
                    <span className="text-right text-sm font-medium">{money(total, currency)}</span>
                    <Button
                      type="button"
                      variant="ghost"
                      size="icon-sm"
                      aria-label="Удалить позицию"
                      disabled={lines.length === 1}
                      onClick={() => setLines((ls) => ls.filter((_, idx) => idx !== i))}
                    >
                      <Trash2 />
                    </Button>
                  </div>
                );
              })}
            </div>
            {errors.items ? <p className="mt-1 text-xs text-danger">{errors.items}</p> : null}
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="mt-2"
              onClick={() => setLines((ls) => [...ls, emptyLine()])}
            >
              <Plus /> {t('addItem')}
            </Button>
          </div>

          <div className="ml-auto grid w-full max-w-xs gap-1 text-sm">
            <div className="flex justify-between">
              <span className="text-muted-foreground">{t('subtotal')}</span>
              <span>{money(totals.sub, currency)}</span>
            </div>
            {totals.disc ? (
              <div className="flex justify-between">
                <span className="text-muted-foreground">{t('discountTotal')}</span>
                <span>− {money(totals.disc, currency)}</span>
              </div>
            ) : null}
            <div className="flex justify-between border-t pt-1 text-base font-semibold">
              <span>{t('total')}</span>
              <span>{money(totals.sub - totals.disc, currency)}</span>
            </div>
          </div>

          <div className="grid gap-4 sm:grid-cols-3">
            <Field label={t('implementationTerm')} htmlFor="kp-term">
              <Input
                id="kp-term"
                value={term}
                onChange={(e) => setTerm(e.target.value)}
                placeholder="напр. 3 месяца"
              />
            </Field>
            <Field label={t('paymentTerms')} htmlFor="kp-pay">
              <Input
                id="kp-pay"
                value={payTerms}
                onChange={(e) => setPayTerms(e.target.value)}
                placeholder="напр. 50% предоплата"
              />
            </Field>
            <Field label={t('validUntil')} htmlFor="kp-valid">
              <Input
                id="kp-valid"
                type="date"
                value={validUntil}
                onChange={(e) => setValidUntil(e.target.value)}
              />
            </Field>
          </div>
          <Field label={t('description')} htmlFor="kp-desc">
            <Textarea
              id="kp-desc"
              value={description}
              onChange={(e) => setDescription(e.target.value)}
            />
          </Field>
          {proposal ? (
            <Field label={t('versionComment')} htmlFor="kp-vc">
              <Input
                id="kp-vc"
                value={versionComment}
                onChange={(e) => setVersionComment(e.target.value)}
              />
            </Field>
          ) : null}
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
              Отмена
            </Button>
            <Button type="submit" loading={save.isPending} loadingText="Сохранение...">
              Сохранить
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

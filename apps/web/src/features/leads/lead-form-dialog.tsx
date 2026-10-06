'use client';

import { COMPANY_SIZES, CURRENCIES, PRIORITIES, type LeadDto } from '@fluggi/contracts';
import { ChevronDown, ChevronUp } from 'lucide-react';
import { useTranslations } from 'next-intl';
import { useRouter } from 'next/navigation';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter } from '@/components/ui/dialog';
import { Input, NativeSelect } from '@/components/ui/input';
import { Field } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useCrmMutation, useReferences } from '@/features/crm/api';
import { OwnerSelect } from '@/features/crm/owner-select';
import { ApiError, api, errorMessage } from '@/lib/api-client';
import { fromLocalInput, newIdempotencyKey, toLocalInput } from '@/lib/format';
import { useCan, useMe } from '@/lib/me-context';

const FIELDS = [
  'title',
  'contactName',
  'companyName',
  'phone',
  'telegram',
  'whatsapp',
  'instagram',
  'email',
  'website',
  'city',
  'country',
  'sourceId',
  'serviceId',
  'budget',
  'currency',
  'desiredDate',
  'priority',
  'companySize',
  'interest',
  'nextContactAt',
  'comment',
  'ownerId',
] as const;
type Values = Record<(typeof FIELDS)[number], string>;

function initial(lead: LeadDto | null | undefined, ownerId: string): Values {
  return {
    title: lead?.title ?? '',
    contactName: lead?.contactName ?? '',
    companyName: lead?.companyName ?? '',
    phone: lead?.phone ?? '',
    telegram: lead?.telegram ?? '',
    whatsapp: lead?.whatsapp ?? '',
    instagram: lead?.instagram ?? '',
    email: lead?.email ?? '',
    website: lead?.website ?? '',
    city: lead?.city ?? '',
    country: lead?.country ?? '',
    sourceId: lead?.source.id ?? '',
    serviceId: lead?.service?.id ?? '',
    budget: lead?.budget ? String(Number(lead.budget)) : '',
    currency: lead?.currency ?? 'UZS',
    desiredDate: lead?.desiredDate ?? '',
    priority: lead?.priority ?? 'MEDIUM',
    companySize: lead?.companySize ?? '',
    interest: lead?.interest ? String(lead.interest) : '',
    nextContactAt: toLocalInput(lead?.nextContactAt),
    comment: lead?.comment ?? '',
    ownerId,
  };
}

/**
 * Лид: при создании — только основное (ТЗ §78, progressive disclosure),
 * остальные поля раскрываются по кнопке «Ещё поля».
 */
export function LeadFormDialog({
  open,
  onOpenChange,
  lead,
}: {
  open: boolean;
  onOpenChange: (o: boolean) => void;
  lead?: LeadDto | null;
}) {
  const t = useTranslations('crm');
  const tl = useTranslations('leads');
  const me = useMe();
  const can = useCan();
  const router = useRouter();
  const refs = useReferences();
  const [v, setV] = useState<Values>(() => initial(lead, me.id));
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [more, setMore] = useState(Boolean(lead));
  const key = useMemo(() => (open ? newIdempotencyKey() : ''), [open]);

  useEffect(() => {
    if (open) {
      setV(initial(lead, me.id));
      setErrors({});
      setMore(Boolean(lead));
    }
  }, [open, lead, me.id]);

  const set =
    (k: keyof Values) =>
    (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>) =>
      setV((s) => ({ ...s, [k]: e.target.value }));

  const save = useCrmMutation(async () => {
    const nz = (s: string) => (s.trim() === '' ? undefined : s.trim());
    if (lead) {
      const nul = (s: string) => (s.trim() === '' ? null : s.trim());
      return api<LeadDto>(`/leads/${lead.id}`, {
        method: 'PATCH',
        body: {
          title: nz(v.title),
          contactName: nul(v.contactName),
          companyName: nul(v.companyName),
          phone: nul(v.phone),
          telegram: nul(v.telegram),
          whatsapp: nul(v.whatsapp),
          instagram: nul(v.instagram),
          email: nul(v.email),
          website: nul(v.website),
          city: nul(v.city),
          country: nul(v.country),
          sourceId: v.sourceId,
          serviceId: nul(v.serviceId),
          budget: nul(v.budget),
          currency: v.currency,
          desiredDate: nul(v.desiredDate),
          priority: v.priority,
          companySize: nul(v.companySize),
          interest: v.interest ? Number(v.interest) : null,
          nextContactAt: v.nextContactAt ? fromLocalInput(v.nextContactAt) : null,
          comment: nul(v.comment),
        },
      });
    }
    return api<LeadDto>('/leads', {
      method: 'POST',
      idempotencyKey: key,
      body: {
        title: nz(v.title),
        contactName: nz(v.contactName),
        companyName: nz(v.companyName),
        phone: nz(v.phone),
        telegram: nz(v.telegram),
        whatsapp: nz(v.whatsapp),
        instagram: nz(v.instagram),
        email: nz(v.email),
        website: nz(v.website),
        city: nz(v.city),
        country: nz(v.country),
        sourceId: v.sourceId,
        serviceId: v.serviceId,
        budget: nz(v.budget),
        currency: v.currency,
        desiredDate: nz(v.desiredDate),
        priority: v.priority,
        companySize: nz(v.companySize),
        interest: v.interest ? Number(v.interest) : undefined,
        nextContactAt: v.nextContactAt ? fromLocalInput(v.nextContactAt) : undefined,
        comment: nz(v.comment),
        ownerId: v.ownerId !== me.id ? v.ownerId : undefined,
      },
    });
  });

  const err = (k: string) => errors[k];
  const input = (k: keyof Values, props: React.InputHTMLAttributes<HTMLInputElement> = {}) => (
    <Field label={t(`fields.${k.replace(/Id$/, '')}`)} htmlFor={`lead-${k}`} error={err(k)}>
      <Input
        id={`lead-${k}`}
        value={v[k]}
        onChange={set(k)}
        aria-invalid={Boolean(err(k))}
        {...props}
      />
    </Field>
  );

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        title={lead ? tl('editTitle') : tl('createTitle')}
        description={lead ? undefined : tl('createHint')}
        className="sm:max-w-2xl"
      >
        <form
          className="grid gap-4"
          noValidate
          onSubmit={async (e) => {
            e.preventDefault();
            setErrors({});
            try {
              const saved = await save.mutateAsync(undefined);
              toast.success(lead ? t('common.saved') : t('common.created'));
              onOpenChange(false);
              if (!lead) router.push(`/sales/leads/${saved.id}`);
            } catch (e2) {
              if (e2 instanceof ApiError) setErrors(e2.fieldErrors());
              toast.error(errorMessage(e2));
            }
          }}
        >
          <div className="grid gap-4 sm:grid-cols-2">
            {input('contactName', { autoFocus: !lead })}
            {input('companyName')}
            {input('phone', { type: 'tel', placeholder: '+998' })}
            {input('telegram', { placeholder: '@username' })}
            <Field label={t('fields.source')} htmlFor="lead-sourceId" error={err('sourceId')}>
              <NativeSelect
                id="lead-sourceId"
                value={v.sourceId}
                onChange={set('sourceId')}
                aria-invalid={Boolean(err('sourceId'))}
              >
                <option value="">{t('common.select')}</option>
                {refs.data?.sources
                  .filter((s) => s.isActive || s.id === v.sourceId)
                  .map((s) => (
                    <option key={s.id} value={s.id}>
                      {s.name}
                    </option>
                  ))}
              </NativeSelect>
            </Field>
            <Field label={t('fields.service')} htmlFor="lead-serviceId" error={err('serviceId')}>
              <NativeSelect
                id="lead-serviceId"
                value={v.serviceId}
                onChange={set('serviceId')}
                aria-invalid={Boolean(err('serviceId'))}
              >
                <option value="">{t('common.select')}</option>
                {refs.data?.services
                  .filter((s) => s.isActive || s.id === v.serviceId)
                  .map((s) => (
                    <option key={s.id} value={s.id}>
                      {s.name}
                    </option>
                  ))}
              </NativeSelect>
            </Field>
            {!lead && can('employee.read') && can('lead.create', 'TEAM') ? (
              <Field label={t('fields.owner')} htmlFor="lead-ownerId">
                <OwnerSelect id="lead-ownerId" value={v.ownerId} onChange={set('ownerId')} />
              </Field>
            ) : null}
            <div className="grid grid-cols-[1fr_6rem] gap-2">
              {input('budget', { inputMode: 'decimal', placeholder: '0' })}
              <Field label={t('fields.currency')} htmlFor="lead-currency">
                <NativeSelect id="lead-currency" value={v.currency} onChange={set('currency')}>
                  {CURRENCIES.map((c) => (
                    <option key={c} value={c}>
                      {c}
                    </option>
                  ))}
                </NativeSelect>
              </Field>
            </div>
          </div>

          <button
            type="button"
            className="flex items-center gap-1 text-sm text-accent hover:underline"
            onClick={() => setMore((m) => !m)}
            aria-expanded={more}
          >
            {more ? <ChevronUp className="size-4" /> : <ChevronDown className="size-4" />}
            {more ? t('common.less') : t('common.more')}
          </button>

          {more ? (
            <div className="grid gap-4 sm:grid-cols-2">
              {input('title')}
              <Field label={t('fields.priority')} htmlFor="lead-priority">
                <NativeSelect id="lead-priority" value={v.priority} onChange={set('priority')}>
                  {PRIORITIES.map((p) => (
                    <option key={p} value={p}>
                      {t(`priority.${p}`)}
                    </option>
                  ))}
                </NativeSelect>
              </Field>
              {input('whatsapp', { type: 'tel' })}
              {input('instagram')}
              {input('email', { type: 'email' })}
              {input('website')}
              {input('city')}
              {input('country')}
              {input('desiredDate', { type: 'date' })}
              {input('nextContactAt', { type: 'datetime-local' })}
              <Field label={t('fields.companySize')} htmlFor="lead-companySize">
                <NativeSelect
                  id="lead-companySize"
                  value={v.companySize}
                  onChange={set('companySize')}
                >
                  <option value="">{t('common.none')}</option>
                  {COMPANY_SIZES.map((s) => (
                    <option key={s} value={s}>
                      {t(`companySize.${s}`)}
                    </option>
                  ))}
                </NativeSelect>
              </Field>
              <Field label={t('fields.interest')} htmlFor="lead-interest">
                <NativeSelect id="lead-interest" value={v.interest} onChange={set('interest')}>
                  <option value="">{t('common.none')}</option>
                  {[1, 2, 3, 4, 5].map((n) => (
                    <option key={n} value={n}>
                      {n}
                    </option>
                  ))}
                </NativeSelect>
              </Field>
              <Field label={t('fields.comment')} htmlFor="lead-comment" className="sm:col-span-2">
                <Textarea id="lead-comment" value={v.comment} onChange={set('comment')} />
              </Field>
            </div>
          ) : null}

          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
              Отмена
            </Button>
            <Button
              type="submit"
              loading={save.isPending}
              loadingText={lead ? 'Сохранение...' : 'Создание...'}
            >
              {lead ? 'Сохранить' : 'Создать лид'}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

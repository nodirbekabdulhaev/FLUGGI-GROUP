'use client';

import {
  CURRENCIES,
  PRICING_TYPES,
  type ReferenceItemDto,
  type DirectionDto,
  type ServiceDto,
  type StageDto,
} from '@fluggi/contracts';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Pencil, Plus } from 'lucide-react';
import { useTranslations } from 'next-intl';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { ErrorState, TableSkeleton } from '@/components/shared/states';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogFooter } from '@/components/ui/dialog';
import { Input, NativeSelect } from '@/components/ui/input';
import { MoneyInput } from '@/components/ui/money-input';
import { Field } from '@/components/ui/label';
import { crmKeys, useReferences } from '@/features/crm/api';
import { ApiError, api, errorMessage } from '@/lib/api-client';
import { date, money } from '@/lib/format';
import { useCan } from '@/lib/me-context';

type Kind = 'services' | 'sources' | 'loss-reasons';
type Editing = { kind: Kind; item: ServiceDto | ReferenceItemDto | null } | null;

function useSave() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({
      path,
      method,
      body,
    }: {
      path: string;
      method: 'POST' | 'PUT' | 'PATCH';
      body: unknown;
    }) => api(path, { method, body }),
    onSuccess: () => qc.invalidateQueries({ queryKey: crmKeys.refs }),
  });
}

function ItemDialog({ editing, onClose }: { editing: Editing; onClose: () => void }) {
  const t = useTranslations('references');
  const save = useSave();
  const refs = useReferences();
  const [v, setV] = useState<Record<string, string | boolean>>({});
  const [errors, setErrors] = useState<Record<string, string>>({});
  const item = editing?.item;
  const isService = editing?.kind === 'services';

  useEffect(() => {
    if (!editing) return;
    setErrors({});
    const s = item as ServiceDto | null;
    setV({
      code: item?.code ?? '',
      name: item?.name ?? '',
      isActive: item?.isActive ?? true,
      sort: String(item?.sort ?? 0),
      requiresComment: (item as ReferenceItemDto | null)?.requiresComment ?? false,
      basePrice: s?.basePrice ? String(Number(s.basePrice)) : '',
      minPrice: s?.minPrice ? String(Number(s.minPrice)) : '',
      currency: s?.currency ?? 'UZS',
      pricingType: s?.pricingType ?? 'FIXED',
      description: s?.description ?? '',
      directionId: s?.directionId ?? '',
    });
  }, [editing, item]);

  if (!editing) return null;
  const set = (k: string) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) =>
    setV((s) => ({
      ...s,
      [k]: e.target.type === 'checkbox' ? (e.target as HTMLInputElement).checked : e.target.value,
    }));

  return (
    <Dialog open onOpenChange={(o) => !o && onClose()}>
      <DialogContent title={item ? String(item.name) : t('add')}>
        <form
          className="grid gap-4"
          noValidate
          onSubmit={async (e) => {
            e.preventDefault();
            const body: Record<string, unknown> = {
              code: v.code,
              name: v.name,
              isActive: v.isActive,
              sort: Number(v.sort) || 0,
            };
            if (editing.kind === 'loss-reasons') body.requiresComment = v.requiresComment;
            if (isService)
              Object.assign(body, {
                basePrice: v.basePrice || undefined,
                minPrice: v.minPrice || undefined,
                currency: v.currency,
                pricingType: v.pricingType,
                description: v.description || null,
                directionId: v.directionId || null,
              });
            const base = isService ? '/references/services' : `/references/${editing.kind}`;
            try {
              await save.mutateAsync({
                path: item ? `${base}/${item.id}` : base,
                method: item ? 'PUT' : 'POST',
                body,
              });
              toast.success('Сохранено');
              onClose();
            } catch (err) {
              if (err instanceof ApiError) setErrors(err.fieldErrors());
              toast.error(errorMessage(err));
            }
          }}
        >
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label={t('name')} htmlFor="r-name" error={errors.name}>
              <Input id="r-name" value={String(v.name ?? '')} onChange={set('name')} autoFocus />
            </Field>
            <Field label={t('code')} htmlFor="r-code" error={errors.code} hint="A–Z, 0–9, _">
              <Input
                id="r-code"
                value={String(v.code ?? '')}
                onChange={(e) => setV((s) => ({ ...s, code: e.target.value.toUpperCase() }))}
              />
            </Field>
            {isService ? (
              <>
                <Field label={t('basePrice')} htmlFor="r-bp" error={errors.basePrice}>
                  <MoneyInput
                    id="r-bp"
                    value={String(v.basePrice ?? '')}
                    onChange={set('basePrice')}
                  />
                </Field>
                <Field label={t('minPrice')} htmlFor="r-mp" error={errors.minPrice}>
                  <MoneyInput
                    id="r-mp"
                    value={String(v.minPrice ?? '')}
                    onChange={set('minPrice')}
                  />
                </Field>
                <Field label="Валюта" htmlFor="r-cur">
                  <NativeSelect id="r-cur" value={String(v.currency)} onChange={set('currency')}>
                    {CURRENCIES.map((c) => (
                      <option key={c}>{c}</option>
                    ))}
                  </NativeSelect>
                </Field>
                <Field label={t('direction')} htmlFor="r-dir" hint={t('directionHint')}>
                  <NativeSelect
                    id="r-dir"
                    value={String(v.directionId ?? '')}
                    onChange={set('directionId')}
                  >
                    <option value="">{t('noDirection')}</option>
                    {refs.data?.directions.map((d) => (
                      <option key={d.id} value={d.id}>
                        {d.name}
                      </option>
                    ))}
                  </NativeSelect>
                </Field>
                <Field label={t('pricingType')} htmlFor="r-pt">
                  <NativeSelect
                    id="r-pt"
                    value={String(v.pricingType)}
                    onChange={set('pricingType')}
                  >
                    {PRICING_TYPES.map((p) => (
                      <option key={p} value={p}>
                        {t(`pricing.${p}`)}
                      </option>
                    ))}
                  </NativeSelect>
                </Field>
              </>
            ) : null}
            <Field label="Порядок" htmlFor="r-sort">
              <Input id="r-sort" type="number" value={String(v.sort ?? 0)} onChange={set('sort')} />
            </Field>
          </div>
          <div className="flex flex-wrap gap-4 text-sm">
            <label className="flex items-center gap-2">
              <input
                type="checkbox"
                className="size-4"
                checked={Boolean(v.isActive)}
                onChange={set('isActive')}
              />{' '}
              {t('active')}
            </label>
            {editing.kind === 'loss-reasons' ? (
              <label className="flex items-center gap-2">
                <input
                  type="checkbox"
                  className="size-4"
                  checked={Boolean(v.requiresComment)}
                  onChange={set('requiresComment')}
                />{' '}
                {t('requiresComment')}
              </label>
            ) : null}
          </div>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={onClose}>
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

function StageRow({ stage, canEdit }: { stage: StageDto; canEdit: boolean }) {
  const save = useSave();
  const [name, setName] = useState(stage.name);
  const [prob, setProb] = useState(String(stage.probability));
  const dirty = name !== stage.name || prob !== String(stage.probability);
  return (
    <li className="flex flex-wrap items-center gap-3 px-5 py-2.5 text-sm">
      <span
        className="size-2.5 shrink-0 rounded-full"
        style={{ backgroundColor: stage.color }}
        aria-hidden
      />
      <Badge>{stage.entity === 'LEAD' ? 'Лид' : 'Сделка'}</Badge>
      <Input
        className="h-8 min-w-40 flex-1"
        value={name}
        onChange={(e) => setName(e.target.value)}
        disabled={!canEdit}
        aria-label="Название этапа"
      />
      <div className="flex items-center gap-1">
        <Input
          className="h-8 w-20"
          type="number"
          min={0}
          max={100}
          value={prob}
          onChange={(e) => setProb(e.target.value)}
          disabled={!canEdit}
          aria-label="Вероятность, %"
        />
        <span className="text-muted-foreground">%</span>
      </div>
      {canEdit ? (
        <Button
          size="sm"
          variant="outline"
          disabled={!dirty}
          loading={save.isPending}
          onClick={async () => {
            try {
              await save.mutateAsync({
                path: `/references/stages/${stage.id}`,
                method: 'PATCH',
                body: { name, probability: Number(prob) },
              });
              toast.success('Этап сохранён');
            } catch (err) {
              toast.error(errorMessage(err));
            }
          }}
        >
          Сохранить
        </Button>
      ) : null}
    </li>
  );
}

function RateCard() {
  const t = useTranslations('references');
  const can = useCan();
  const refs = useReferences();
  const save = useSave();
  const [rate, setRate] = useState('');
  const current = refs.data?.usdRate;
  return (
    <Card>
      <CardHeader className="border-b pb-5">
        <CardTitle>{t('usdRate')}</CardTitle>
        <CardDescription>{t('rateHint')}</CardDescription>
      </CardHeader>
      <div className="flex flex-wrap items-end gap-3 p-5">
        <div className="min-w-48">
          {current ? (
            <>
              <p className="text-2xl font-semibold">{money(current.rateToUzs, 'UZS')}</p>
              <p className="text-xs text-muted-foreground">
                {t('lastSetBy', { date: date(current.date), by: current.setBy?.name ?? 'none' })}
              </p>
            </>
          ) : (
            <p className="text-sm text-danger">{t('rateMissing')}</p>
          )}
        </div>
        {can('finance.company.read', 'ALL') ? (
          <form
            className="flex items-end gap-2"
            onSubmit={async (e) => {
              e.preventDefault();
              try {
                await save.mutateAsync({
                  path: '/references/exchange-rates',
                  method: 'PUT',
                  body: { currency: 'USD', rateToUzs: Number(rate) },
                });
                setRate('');
                toast.success(t('rateSet'));
              } catch (err) {
                toast.error(errorMessage(err));
              }
            }}
          >
            <Field label={t('rate')} htmlFor="rate">
              <Input
                id="rate"
                inputMode="decimal"
                className="w-40"
                value={rate}
                onChange={(e) => setRate(e.target.value)}
              />
            </Field>
            <Button type="submit" disabled={!rate || Number(rate) <= 0} loading={save.isPending}>
              Сохранить
            </Button>
          </form>
        ) : null}
      </div>
    </Card>
  );
}

/** Направления бизнеса (IT, Медиа, Маркетинг): услуга → направление → проекты проект-менеджера. */
function DirectionsCard({ directions, canEdit }: { directions: DirectionDto[]; canEdit: boolean }) {
  const t = useTranslations('references');
  const save = useSave();
  const [edit, setEdit] = useState<{ item: DirectionDto | null; name: string } | null>(null);
  return (
    <Card>
      <CardHeader className="flex-row items-center justify-between gap-4 border-b pb-4">
        <div>
          <CardTitle>{t('directions')}</CardTitle>
          <CardDescription>{t('directionsText')}</CardDescription>
        </div>
        {canEdit ? (
          <Button size="sm" variant="outline" onClick={() => setEdit({ item: null, name: '' })}>
            <Plus /> {t('add')}
          </Button>
        ) : null}
      </CardHeader>
      <ul className="divide-y">
        {directions.map((d) => (
          <li key={d.id} className="flex items-center gap-3 px-5 py-2.5 text-sm">
            <span className="flex-1">{d.name}</span>
            {!d.isActive ? <Badge tone="warning">{t('inactive')}</Badge> : null}
            {canEdit ? (
              <Button
                variant="ghost"
                size="icon-sm"
                aria-label={`Изменить: ${d.name}`}
                onClick={() => setEdit({ item: d, name: d.name })}
              >
                <Pencil />
              </Button>
            ) : null}
          </li>
        ))}
      </ul>
      <Dialog open={Boolean(edit)} onOpenChange={(o) => !o && setEdit(null)}>
        <DialogContent title={edit?.item ? edit.item.name : t('newDirection')}>
          <form
            className="grid gap-4"
            onSubmit={async (e) => {
              e.preventDefault();
              if (!edit) return;
              try {
                await save.mutateAsync({
                  path: edit.item ? `/directions/${edit.item.id}` : '/directions',
                  method: edit.item ? 'PUT' : 'POST',
                  body: {
                    name: edit.name,
                    sort: edit.item?.sort ?? 100,
                    isActive: edit.item?.isActive ?? true,
                  },
                });
                toast.success('Сохранено');
                setEdit(null);
              } catch (err) {
                toast.error(errorMessage(err));
              }
            }}
          >
            <Field label={t('name')} htmlFor="dir-name">
              <Input
                id="dir-name"
                required
                autoFocus
                value={edit?.name ?? ''}
                onChange={(e) => setEdit((s) => (s ? { ...s, name: e.target.value } : s))}
              />
            </Field>
            <DialogFooter>
              <Button type="submit" loading={save.isPending}>
                Сохранить
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </Card>
  );
}

export function ReferencesSettings() {
  const t = useTranslations('references');
  const can = useCan();
  const refs = useReferences();
  const [editing, setEditing] = useState<Editing>(null);
  if (refs.isPending)
    return (
      <Card>
        <TableSkeleton rows={6} cols={2} />
      </Card>
    );
  if (refs.isError)
    return (
      <Card>
        <ErrorState error={refs.error} onRetry={() => refs.refetch()} />
      </Card>
    );
  const canEdit = can('reference.manage', 'ALL');

  const list = (kind: Kind, title: string, items: (ServiceDto | ReferenceItemDto)[]) => (
    <Card>
      <CardHeader className="flex-row items-center justify-between gap-4 border-b pb-4">
        <CardTitle>{title}</CardTitle>
        {canEdit ? (
          <Button size="sm" variant="outline" onClick={() => setEditing({ kind, item: null })}>
            <Plus /> {t('add')}
          </Button>
        ) : null}
      </CardHeader>
      <ul className="divide-y">
        {items.map((i) => (
          <li key={i.id} className="flex items-center gap-3 px-5 py-2.5 text-sm">
            <span className="flex-1">{i.name}</span>
            {'directionId' in i && i.directionId ? (
              <Badge tone="accent">
                {refs.data.directions.find((d) => d.id === i.directionId)?.name ?? ''}
              </Badge>
            ) : null}
            {'basePrice' in i && i.basePrice ? (
              <span className="text-muted-foreground">{money(i.basePrice, i.currency)}</span>
            ) : null}
            {'requiresComment' in i && i.requiresComment ? (
              <Badge>{t('requiresComment')}</Badge>
            ) : null}
            {!i.isActive ? <Badge tone="warning">{t('inactive')}</Badge> : null}
            {canEdit ? (
              <Button
                variant="ghost"
                size="icon-sm"
                aria-label="Изменить"
                onClick={() => setEditing({ kind, item: i })}
              >
                <Pencil />
              </Button>
            ) : null}
          </li>
        ))}
      </ul>
    </Card>
  );

  return (
    <div className="grid gap-6">
      <RateCard />
      <div className="grid gap-6 lg:grid-cols-2">
        {list('services', t('services'), refs.data.services)}
        <div className="grid content-start gap-6">
          <DirectionsCard directions={refs.data.directions} canEdit={canEdit} />
          {list('sources', t('sources'), refs.data.sources)}
          {list('loss-reasons', t('lossReasons'), refs.data.lossReasons)}
        </div>
      </div>
      <Card>
        <CardHeader className="border-b pb-4">
          <CardTitle>{t('stages')}</CardTitle>
          <CardDescription>
            Вероятность используется во взвешенном прогнозе: сумма × вероятность.
          </CardDescription>
        </CardHeader>
        <ul className="divide-y">
          {refs.data.stages.map((s) => (
            <StageRow key={s.id} stage={s} canEdit={canEdit} />
          ))}
        </ul>
      </Card>
      <ItemDialog editing={editing} onClose={() => setEditing(null)} />
    </div>
  );
}

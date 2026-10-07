'use client';

import {
  CURRENCIES,
  EXECUTOR_SPECIALTIES,
  type Currency,
  type ExecutorSpecialty,
  type TariffDto,
  type TariffItemKind,
} from '@fluggi/contracts';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { useTranslations } from 'next-intl';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import { EmptyState, ErrorState, TableSkeleton } from '@/components/shared/states';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogFooter } from '@/components/ui/dialog';
import { Input, NativeSelect } from '@/components/ui/input';
import { Field } from '@/components/ui/label';
import { MoneyInput } from '@/components/ui/money-input';
import { Textarea } from '@/components/ui/textarea';
import { useReferences } from '@/features/crm/api';
import { ApiError, api, errorMessage } from '@/lib/api-client';
import { money } from '@/lib/format';
import { useCatalogMutation, useTariffs, useWorkItems } from './tariffs-api';

interface ItemRow {
  kind: TariffItemKind;
  workItemId: string;
  quantity: string;
  specialty: ExecutorSpecialty | '';
  amount: string;
  currency: Currency;
  label: string;
}

const emptyItem = (kind: TariffItemKind): ItemRow => ({
  kind,
  workItemId: '',
  quantity: '1',
  specialty: '',
  amount: '',
  currency: 'UZS',
  label: '',
});

function TariffDialog({
  tariff,
  serviceId,
  open,
  onOpenChange,
}: {
  tariff: TariffDto | null;
  serviceId: string;
  open: boolean;
  onOpenChange: (o: boolean) => void;
}) {
  const t = useTranslations('tariffs');
  const ts = useTranslations('specialties');
  const refs = useReferences();
  const work = useWorkItems(open);
  const [v, setV] = useState({
    serviceId: '',
    name: '',
    description: '',
    price: '',
    currency: 'USD' as Currency,
    isActive: true,
    sort: '0',
  });
  const [items, setItems] = useState<ItemRow[]>([]);
  const [errors, setErrors] = useState<Record<string, string>>({});
  useEffect(() => {
    if (!open) return;
    setErrors({});
    setV({
      serviceId: tariff?.service.id ?? serviceId,
      name: tariff?.name ?? '',
      description: tariff?.description ?? '',
      price: tariff ? String(Number(tariff.price)) : '',
      currency: tariff?.currency ?? 'USD',
      isActive: tariff?.isActive ?? true,
      sort: String(tariff?.sort ?? 0),
    });
    setItems(
      (tariff?.items ?? []).map((i) => ({
        kind: i.kind,
        workItemId: i.workItem?.id ?? '',
        quantity: String(Number(i.quantity)),
        specialty: i.specialty ?? '',
        amount: i.amount ? String(Number(i.amount)) : '',
        currency: i.currency,
        label: i.label ?? '',
      })),
    );
  }, [open, tariff, serviceId]);

  const save = useCatalogMutation(() => {
    const body = {
      ...v,
      description: v.description || null,
      sort: Number(v.sort),
      items: items.map((i) =>
        i.kind === 'PIECE'
          ? { kind: i.kind, workItemId: i.workItemId, quantity: Number(i.quantity), label: i.label || null }
          : {
              kind: i.kind,
              specialty: i.specialty || null,
              amount: i.amount,
              currency: i.currency,
              quantity: 1,
              label: i.label || null,
            },
      ),
    };
    return tariff ? api(`/tariffs/${tariff.id}`, { method: 'PUT', body }) : api('/tariffs', { method: 'POST', body });
  });
  const patch = (idx: number, p: Partial<ItemRow>) => setItems((s) => s.map((x, j) => (j === idx ? { ...x, ...p } : x)));

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent title={tariff ? t('edit') : t('new')} className="sm:max-w-3xl">
        <form
          className="grid gap-4"
          onSubmit={async (e) => {
            e.preventDefault();
            try {
              await save.mutateAsync(undefined);
              toast.success(t('saved'));
              onOpenChange(false);
            } catch (err) {
              if (err instanceof ApiError) setErrors(err.fieldErrors());
              toast.error(errorMessage(err));
            }
          }}
        >
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label={t('service')} htmlFor="tf-svc" error={errors.serviceId}>
              <NativeSelect id="tf-svc" required value={v.serviceId} onChange={(e) => setV((s) => ({ ...s, serviceId: e.target.value }))}>
                <option value="">—</option>
                {refs.data?.services.map((s) => (
                  <option key={s.id} value={s.id}>
                    {s.name}
                  </option>
                ))}
              </NativeSelect>
            </Field>
            <Field label={t('name')} htmlFor="tf-name" error={errors.name} hint={t('nameHint')}>
              <Input id="tf-name" required value={v.name} onChange={(e) => setV((s) => ({ ...s, name: e.target.value }))} />
            </Field>
            <Field label={t('price')} htmlFor="tf-price" error={errors.price}>
              <MoneyInput id="tf-price" required value={v.price} onChange={(e) => setV((s) => ({ ...s, price: e.target.value }))} />
            </Field>
            <Field label={t('currency')} htmlFor="tf-cur">
              <NativeSelect id="tf-cur" value={v.currency} onChange={(e) => setV((s) => ({ ...s, currency: e.target.value as Currency }))}>
                {CURRENCIES.map((c) => (
                  <option key={c}>{c}</option>
                ))}
              </NativeSelect>
            </Field>
          </div>
          <Field label={t('description')} htmlFor="tf-desc" hint={t('descriptionHint')}>
            <Textarea id="tf-desc" rows={2} value={v.description} onChange={(e) => setV((s) => ({ ...s, description: e.target.value }))} />
          </Field>

          <div className="grid gap-2">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <span className="text-sm font-medium">{t('items')}</span>
              <div className="flex gap-2">
                <Button type="button" size="sm" variant="outline" onClick={() => setItems((s) => [...s, emptyItem('PIECE')])}>
                  <Plus className="size-4" /> {t('addPiece')}
                </Button>
                <Button type="button" size="sm" variant="outline" onClick={() => setItems((s) => [...s, emptyItem('FIXED')])}>
                  <Plus className="size-4" /> {t('addFixed')}
                </Button>
              </div>
            </div>
            <p className="text-xs text-muted-foreground">{t('itemsText')}</p>
            {items.length === 0 ? <p className="text-sm text-muted-foreground">{t('noItems')}</p> : null}
            {items.map((i, idx) => (
              <div key={idx} className="grid items-end gap-2 rounded-md border p-2 sm:grid-cols-[7rem_1fr_6rem_2rem]">
                <Badge tone={i.kind === 'PIECE' ? 'accent' : 'warning'} className="justify-self-start">
                  {t(i.kind === 'PIECE' ? 'kindPiece' : 'kindFixed')}
                </Badge>
                {i.kind === 'PIECE' ? (
                  <>
                    <NativeSelect
                      aria-label={t('workItem')}
                      required
                      value={i.workItemId}
                      onChange={(e) => patch(idx, { workItemId: e.target.value })}
                    >
                      <option value="">{t('chooseWork')}</option>
                      {work.data
                        ?.filter((w) => w.isActive || w.id === i.workItemId)
                        .map((w) => (
                          <option key={w.id} value={w.id}>
                            {w.name} — {money(w.defaultRate, w.currency)} / {w.unit}
                          </option>
                        ))}
                    </NativeSelect>
                    <Input
                      aria-label={t('quantity')}
                      type="number"
                      min={0.01}
                      step="any"
                      required
                      value={i.quantity}
                      onChange={(e) => patch(idx, { quantity: e.target.value })}
                    />
                  </>
                ) : (
                  <>
                    <div className="grid gap-2 sm:grid-cols-[1fr_8rem_5rem]">
                      <NativeSelect
                        aria-label={t('specialty')}
                        required
                        value={i.specialty}
                        onChange={(e) => patch(idx, { specialty: e.target.value as ExecutorSpecialty | '' })}
                      >
                        <option value="">{t('chooseSpecialty')}</option>
                        {EXECUTOR_SPECIALTIES.map((sp) => (
                          <option key={sp} value={sp}>
                            {ts(sp)}
                          </option>
                        ))}
                      </NativeSelect>
                      <MoneyInput aria-label={t('amount')} required value={i.amount} onChange={(e) => patch(idx, { amount: e.target.value })} />
                      <NativeSelect aria-label={t('currency')} value={i.currency} onChange={(e) => patch(idx, { currency: e.target.value as Currency })}>
                        {CURRENCIES.map((c) => (
                          <option key={c}>{c}</option>
                        ))}
                      </NativeSelect>
                    </div>
                    <Input aria-label={t('label')} placeholder={t('label')} value={i.label} onChange={(e) => patch(idx, { label: e.target.value })} />
                  </>
                )}
                <Button
                  type="button"
                  size="sm"
                  variant="ghost"
                  aria-label={t('removeItem')}
                  onClick={() => setItems((s) => s.filter((_, j) => j !== idx))}
                >
                  <Trash2 className="size-4" />
                </Button>
              </div>
            ))}
          </div>

          <label className="flex items-center gap-2 text-sm">
            <input type="checkbox" checked={v.isActive} onChange={(e) => setV((s) => ({ ...s, isActive: e.target.checked }))} />
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

function TariffCard({ tariff, onEdit }: { tariff: TariffDto; onEdit: () => void }) {
  const t = useTranslations('tariffs');
  const ts = useTranslations('specialties');
  const e = tariff.economics;
  return (
    <div className={`grid content-start gap-3 rounded-lg border p-4 ${tariff.isActive ? '' : 'opacity-60'}`}>
      <div className="flex items-start justify-between gap-2">
        <div>
          <div className="font-semibold">
            {tariff.name} {!tariff.isActive ? <Badge>{t('off')}</Badge> : null}
          </div>
          <div className="text-lg font-semibold tabular-nums">{money(tariff.price, tariff.currency)}</div>
        </div>
        <Button size="sm" variant="ghost" aria-label={`${t('edit')}: ${tariff.service.name} ${tariff.name}`} onClick={onEdit}>
          <Pencil className="size-4" />
        </Button>
      </div>
      {tariff.description ? <p className="whitespace-pre-line text-sm text-muted-foreground">{tariff.description}</p> : null}
      <ul className="grid gap-1 text-sm">
        {tariff.items.map((i) => (
          <li key={i.id} className="flex justify-between gap-2">
            <span>
              {i.kind === 'PIECE'
                ? `${i.workItem?.name ?? '—'} × ${Number(i.quantity)}`
                : `${i.label || (i.specialty ? ts(i.specialty) : '—')} (${t('kindFixed').toLowerCase()})`}
            </span>
            <span className="whitespace-nowrap tabular-nums text-muted-foreground">{money(i.costUzs, 'UZS')}</span>
          </li>
        ))}
        {tariff.items.length === 0 ? <li className="text-muted-foreground">{t('noItems')}</li> : null}
      </ul>
      {e ? (
        <dl className="grid grid-cols-2 gap-x-3 gap-y-1 border-t pt-2 text-xs">
          <dt className="text-muted-foreground">{t('priceUzs')}</dt>
          <dd className="text-right tabular-nums">{money(e.priceUzs, 'UZS')}</dd>
          <dt className="text-muted-foreground">{t('executorsUzs')}</dt>
          <dd className="text-right tabular-nums">−{money(e.executorsUzs, 'UZS')}</dd>
          <dt className="text-muted-foreground">{t('overheadUzs')}</dt>
          <dd className="text-right tabular-nums">−{money(e.overheadUzs, 'UZS')}</dd>
          <dt className="font-medium">{t('marginUzs')}</dt>
          <dd className={`text-right font-medium tabular-nums ${Number(e.marginUzs) < 0 ? 'text-danger' : ''}`}>
            {money(e.marginUzs, 'UZS')}
            {e.marginPct !== null ? ` · ${Number(e.marginPct).toFixed(1)}%` : ''}
          </dd>
        </dl>
      ) : null}
    </div>
  );
}

/** Настройки → «Тарифы»: по каждой услуге свои тарифы (Эконом/Стандарт/Премиум), цена продажи и состав работ исполнителей. */
export function TariffsSettingsPage() {
  const t = useTranslations('tariffs');
  const refs = useReferences();
  const list = useTariffs(true, true);
  const [dialog, setDialog] = useState<{ open: boolean; tariff: TariffDto | null; serviceId: string }>({
    open: false,
    tariff: null,
    serviceId: '',
  });
  const byService = useMemo(() => {
    const m = new Map<string, TariffDto[]>();
    for (const tf of list.data ?? []) m.set(tf.service.id, [...(m.get(tf.service.id) ?? []), tf]);
    return m;
  }, [list.data]);

  if (list.isPending || refs.isPending) return <TableSkeleton rows={6} cols={3} />;
  if (list.isError) return <ErrorState error={list.error} onRetry={() => list.refetch()} />;
  const services = refs.data?.services ?? [];

  return (
    <div className="grid gap-6">
      <p className="text-sm text-muted-foreground">{t('intro')}</p>
      {services.length === 0 ? <EmptyState title={t('noServices')} /> : null}
      {services.map((s) => {
        const tariffs = byService.get(s.id) ?? [];
        return (
          <Card key={s.id}>
            <CardHeader className="flex flex-row flex-wrap items-start justify-between gap-3">
              <div>
                <CardTitle>{s.name}</CardTitle>
                <CardDescription>{tariffs.length ? t('count', { count: tariffs.length }) : t('noTariffs')}</CardDescription>
              </div>
              <Button size="sm" variant="outline" onClick={() => setDialog({ open: true, tariff: null, serviceId: s.id })}>
                <Plus className="size-4" /> {t('add')}
              </Button>
            </CardHeader>
            {tariffs.length ? (
              <CardContent className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                {tariffs.map((tf) => (
                  <TariffCard key={tf.id} tariff={tf} onEdit={() => setDialog({ open: true, tariff: tf, serviceId: s.id })} />
                ))}
              </CardContent>
            ) : null}
          </Card>
        );
      })}
      <TariffDialog
        tariff={dialog.tariff}
        serviceId={dialog.serviceId}
        open={dialog.open}
        onOpenChange={(o) => setDialog((s) => ({ ...s, open: o }))}
      />
    </div>
  );
}

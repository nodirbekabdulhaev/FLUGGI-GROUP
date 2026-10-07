'use client';

import { CURRENCIES, type Currency } from '@fluggi/contracts';
import { useTranslations } from 'next-intl';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { ErrorState, TableSkeleton } from '@/components/shared/states';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter } from '@/components/ui/dialog';
import { NativeSelect } from '@/components/ui/input';
import { MoneyInput } from '@/components/ui/money-input';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { useCatalogMutation, useEmployeeRates } from '@/features/finance/tariffs-api';
import { api, errorMessage } from '@/lib/api-client';
import { money } from '@/lib/format';

/**
 * Личные сдельные ставки сотрудника («договор сдельный»): рилс, обложка, сторис…
 * Пустое поле — действует базовая ставка из Настройки → Финансы. Изменять может только CEO (payroll.manage).
 */
export function RatesDialog({
  user,
  editable,
  onClose,
}: {
  user: { id: string; fullName: string } | null;
  editable: boolean;
  onClose: () => void;
}) {
  const t = useTranslations('rates');
  const ts = useTranslations('specialties');
  const rates = useEmployeeRates(user?.id ?? null);
  const [v, setV] = useState<Record<string, { rate: string; currency: Currency }>>({});
  useEffect(() => {
    if (!rates.data) return;
    setV(
      Object.fromEntries(
        rates.data.map((r) => [
          r.workItem.id,
          { rate: r.rate ? String(Number(r.rate)) : '', currency: r.currency ?? r.workItem.currency },
        ]),
      ),
    );
  }, [rates.data]);
  const save = useCatalogMutation(() =>
    api(`/users/${user?.id}/rates`, {
      method: 'PUT',
      body: {
        rates: Object.entries(v).map(([workItemId, r]) => ({
          workItemId,
          rate: r.rate.trim() ? r.rate : null,
          currency: r.currency,
        })),
      },
    }),
  );

  return (
    <Dialog open={Boolean(user)} onOpenChange={(o) => !o && onClose()}>
      <DialogContent title={t('title', { name: user?.fullName ?? '' })} className="sm:max-w-2xl">
        <p className="text-sm text-muted-foreground">{t(editable ? 'text' : 'textReadonly')}</p>
        {rates.isPending ? (
          <TableSkeleton rows={4} cols={3} />
        ) : rates.isError ? (
          <ErrorState error={rates.error} onRetry={() => rates.refetch()} />
        ) : (
          <form
            className="grid gap-4"
            onSubmit={async (e) => {
              e.preventDefault();
              try {
                await save.mutateAsync(undefined);
                toast.success(t('saved'));
                onClose();
              } catch (err) {
                toast.error(errorMessage(err));
              }
            }}
          >
            <Table>
              <THead>
                <TR>
                  <TH>{t('work')}</TH>
                  <TH className="text-right">{t('base')}</TH>
                  <TH className="w-56">{t('personal')}</TH>
                </TR>
              </THead>
              <TBody>
                {rates.data.map((r) => {
                  const cur = v[r.workItem.id] ?? { rate: '', currency: r.workItem.currency };
                  return (
                    <TR key={r.workItem.id}>
                      <TD>
                        <div className="font-medium">{r.workItem.name}</div>
                        <div className="text-xs text-muted-foreground">
                          {r.workItem.specialty ? ts(r.workItem.specialty) : '—'} · {r.workItem.unit}
                        </div>
                      </TD>
                      <TD className="whitespace-nowrap text-right tabular-nums text-muted-foreground">
                        {money(r.workItem.defaultRate, r.workItem.currency)}
                      </TD>
                      <TD>
                        {editable ? (
                          <div className="flex gap-1">
                            <MoneyInput
                              aria-label={`${t('personal')}: ${r.workItem.name}`}
                              placeholder={t('asBase')}
                              value={cur.rate}
                              onChange={(e) =>
                                setV((s) => ({ ...s, [r.workItem.id]: { ...cur, rate: e.target.value } }))
                              }
                            />
                            <NativeSelect
                              aria-label={t('currency')}
                              className="w-20"
                              value={cur.currency}
                              onChange={(e) =>
                                setV((s) => ({
                                  ...s,
                                  [r.workItem.id]: { ...cur, currency: e.target.value as Currency },
                                }))
                              }
                            >
                              {CURRENCIES.map((c) => (
                                <option key={c}>{c}</option>
                              ))}
                            </NativeSelect>
                          </div>
                        ) : r.rate ? (
                          <span className="font-medium tabular-nums">{money(r.rate, r.currency ?? 'UZS')}</span>
                        ) : (
                          <span className="text-muted-foreground">{t('asBase')}</span>
                        )}
                      </TD>
                    </TR>
                  );
                })}
              </TBody>
            </Table>
            {editable ? (
              <DialogFooter>
                <Button type="button" variant="outline" onClick={onClose}>
                  {t('cancel')}
                </Button>
                <Button type="submit" loading={save.isPending}>
                  {t('save')}
                </Button>
              </DialogFooter>
            ) : null}
          </form>
        )}
      </DialogContent>
    </Dialog>
  );
}

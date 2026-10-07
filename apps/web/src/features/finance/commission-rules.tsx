'use client';

import {
  COMMISSION_CALC_TYPES,
  type CommissionCalcType,
  type CommissionRuleDto,
} from '@fluggi/contracts';
import { Pencil, Percent, Plus, Trash2 } from 'lucide-react';
import { useTranslations } from 'next-intl';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { EmptyState, ErrorState, TableSkeleton } from '@/components/shared/states';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Dialog, DialogContent, DialogFooter } from '@/components/ui/dialog';
import { Input, NativeSelect } from '@/components/ui/input';
import { Field } from '@/components/ui/label';
import { MoneyInput } from '@/components/ui/money-input';
import { useCrmMutation } from '@/features/crm/api';
import { ApiError, api, errorMessage } from '@/lib/api-client';
import { money } from '@/lib/format';
import { useCommissionRules } from './api';

const METRICS = [
  'avg_check_usd',
  'avg_check_uzs',
  'orders_count',
  'revenue_uzs',
  'revenue_usd',
] as const;
const OPS = ['>', '>=', '<', '<=', '='] as const;
type Leaf = { metric: (typeof METRICS)[number]; op: (typeof OPS)[number]; value: string };

/** Условия правила в редакторе — плоский список «все / любое» (DSL ТЗ §34). */
function readConditions(c: CommissionRuleDto['conditions']): {
  mode: 'all' | 'any';
  leaves: Leaf[];
} {
  if (!c) return { mode: 'all', leaves: [] };
  if ('metric' in c) return { mode: 'all', leaves: [{ ...c, value: String(c.value) } as Leaf] };
  const mode = c.any ? 'any' : 'all';
  const list = (c.any ?? c.all ?? []).filter((x): x is Leaf & { value: number } => 'metric' in x);
  return { mode, leaves: list.map((x) => ({ ...x, value: String(x.value) })) };
}

function describeConditions(
  rule: CommissionRuleDto,
  t: ReturnType<typeof useTranslations>,
): string {
  const { mode, leaves } = readConditions(rule.conditions);
  if (leaves.length === 0) return t('noConditions');
  return leaves
    .map((l) => `${t(`metric.${l.metric}`)} ${l.op} ${Number(l.value).toLocaleString('ru-RU')}`)
    .join(mode === 'any' ? ' или ' : ' и ');
}

function RuleDialog({
  rule,
  open,
  onOpenChange,
}: {
  rule: CommissionRuleDto | null;
  open: boolean;
  onOpenChange: (o: boolean) => void;
}) {
  const t = useTranslations('commissionRules');
  const [v, setV] = useState({
    name: '',
    appliesTo: 'MANAGER' as 'MANAGER' | 'ROP',
    calcType: 'PERCENT_OF_PAYMENT' as CommissionCalcType,
    value: '',
    priority: '0',
    isActive: true,
  });
  const [mode, setMode] = useState<'all' | 'any'>('all');
  const [leaves, setLeaves] = useState<Leaf[]>([]);
  const [errors, setErrors] = useState<Record<string, string>>({});
  useEffect(() => {
    if (!open) return;
    setErrors({});
    setV({
      name: rule?.name ?? '',
      appliesTo: rule?.appliesTo ?? 'MANAGER',
      calcType: rule?.calcType ?? 'PERCENT_OF_PAYMENT',
      value: rule ? String(Number(rule.value)) : '',
      priority: String(rule?.priority ?? 0),
      isActive: rule?.isActive ?? true,
    });
    const c = readConditions(rule?.conditions ?? null);
    setMode(c.mode);
    setLeaves(c.leaves);
  }, [open, rule]);
  const fixed = v.calcType === 'FIXED_PER_DEAL';
  const save = useCrmMutation(() => {
    const body = {
      name: v.name,
      appliesTo: v.appliesTo,
      calcType: v.calcType,
      value: Number(v.value),
      priority: Number(v.priority),
      isActive: v.isActive,
      conditions:
        leaves.length === 0
          ? null
          : { [mode]: leaves.map((l) => ({ metric: l.metric, op: l.op, value: Number(l.value) })) },
    };
    return rule
      ? api(`/commission-rules/${rule.id}`, { method: 'PUT', body })
      : api('/commission-rules', { method: 'POST', body });
  });
  const setLeaf = (i: number, k: keyof Leaf, value: string) =>
    setLeaves((ls) => ls.map((l, j) => (j === i ? { ...l, [k]: value } : l)));

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent title={rule ? t('edit') : t('new')} className="sm:max-w-2xl">
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
          <Field label={t('name')} htmlFor="cr-name" error={errors.name}>
            <Input
              id="cr-name"
              required
              value={v.name}
              onChange={(e) => setV((s) => ({ ...s, name: e.target.value }))}
            />
          </Field>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label={t('appliesTo')} htmlFor="cr-role">
              <NativeSelect
                id="cr-role"
                value={v.appliesTo}
                onChange={(e) => setV((s) => ({ ...s, appliesTo: e.target.value as never }))}
              >
                <option value="MANAGER">{t('role.MANAGER')}</option>
                <option value="ROP">{t('role.ROP')}</option>
              </NativeSelect>
            </Field>
            <Field label={t('calcType')} htmlFor="cr-calc" hint={t(`calcHint.${v.calcType}`)}>
              <NativeSelect
                id="cr-calc"
                value={v.calcType}
                onChange={(e) => setV((s) => ({ ...s, calcType: e.target.value as never }))}
              >
                {COMMISSION_CALC_TYPES.map((c) => (
                  <option key={c} value={c}>
                    {t(`calc.${c}`)}
                  </option>
                ))}
              </NativeSelect>
            </Field>
            <Field
              label={fixed ? t('valueFixed') : t('valuePct')}
              htmlFor="cr-value"
              error={errors.value}
            >
              {fixed ? (
                <MoneyInput
                  id="cr-value"
                  required
                  value={v.value}
                  onChange={(e) => setV((s) => ({ ...s, value: e.target.value }))}
                />
              ) : (
                <Input
                  id="cr-value"
                  type="number"
                  required
                  min={0}
                  max={100}
                  step="0.01"
                  value={v.value}
                  onChange={(e) => setV((s) => ({ ...s, value: e.target.value }))}
                />
              )}
            </Field>
            <Field label={t('priority')} htmlFor="cr-prio" error={errors.priority}>
              <Input
                id="cr-prio"
                type="number"
                min={0}
                max={1000}
                value={v.priority}
                onChange={(e) => setV((s) => ({ ...s, priority: e.target.value }))}
              />
            </Field>
          </div>

          <fieldset className="grid gap-2 rounded-md border p-3">
            <legend className="px-1 text-sm font-medium">{t('conditions')}</legend>
            {leaves.length === 0 ? (
              <p className="text-sm text-muted-foreground">{t('noConditions')}</p>
            ) : (
              <label className="flex flex-wrap items-center gap-2 text-sm">
                {t('match')}
                <NativeSelect
                  aria-label={t('match')}
                  className="h-9 w-auto"
                  value={mode}
                  onChange={(e) => setMode(e.target.value as 'all' | 'any')}
                >
                  <option value="all">{t('all')}</option>
                  <option value="any">{t('any')}</option>
                </NativeSelect>
              </label>
            )}
            {leaves.map((l, i) => (
              <div key={i} className="grid gap-2 sm:grid-cols-[1fr_5rem_9rem_auto]">
                <NativeSelect
                  aria-label="Показатель"
                  value={l.metric}
                  onChange={(e) => setLeaf(i, 'metric', e.target.value)}
                >
                  {METRICS.map((m) => (
                    <option key={m} value={m}>
                      {t(`metric.${m}`)}
                    </option>
                  ))}
                </NativeSelect>
                <NativeSelect
                  aria-label="Сравнение"
                  value={l.op}
                  onChange={(e) => setLeaf(i, 'op', e.target.value)}
                >
                  {OPS.map((o) => (
                    <option key={o}>{o}</option>
                  ))}
                </NativeSelect>
                <Input
                  aria-label="Значение"
                  type="number"
                  required
                  value={l.value}
                  onChange={(e) => setLeaf(i, 'value', e.target.value)}
                />
                <Button
                  type="button"
                  size="icon"
                  variant="ghost"
                  aria-label="Удалить условие"
                  onClick={() => setLeaves((ls) => ls.filter((_, j) => j !== i))}
                >
                  <Trash2 />
                </Button>
              </div>
            ))}
            <Button
              type="button"
              size="sm"
              variant="outline"
              className="justify-self-start"
              onClick={() =>
                setLeaves((ls) => [...ls, { metric: 'avg_check_usd', op: '>', value: '' }])
              }
            >
              <Plus /> {t('addCondition')}
            </Button>
          </fieldset>

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
              Отмена
            </Button>
            <Button type="submit" loading={save.isPending}>
              Сохранить
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

/** Настройки → Правила комиссий (ТЗ §33–34): правила — данные, а не код. */
export function CommissionRulesSettings() {
  const t = useTranslations('commissionRules');
  const list = useCommissionRules();
  const [editing, setEditing] = useState<CommissionRuleDto | null | 'new'>(null);
  return (
    <div className="grid gap-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <h2 className="font-semibold">{t('title')}</h2>
          <p className="max-w-2xl text-sm text-muted-foreground">{t('subtitle')}</p>
        </div>
        <Button size="sm" onClick={() => setEditing('new')}>
          <Plus /> {t('new')}
        </Button>
      </div>
      <Card>
        {list.isPending ? (
          <TableSkeleton rows={3} cols={3} />
        ) : list.isError ? (
          <ErrorState error={list.error} onRetry={() => list.refetch()} />
        ) : list.data.length === 0 ? (
          <EmptyState icon={Percent} title={t('empty')} />
        ) : (
          <ul className="divide-y">
            {list.data.map((r) => (
              <li key={r.id} className="flex flex-wrap items-center gap-3 px-5 py-3">
                <div className="min-w-0 flex-1">
                  <p className="font-medium">{r.name}</p>
                  <p className="text-sm text-muted-foreground">
                    {t(`role.${r.appliesTo}`)} · {t(`calc.${r.calcType}`)}{' '}
                    {r.calcType === 'FIXED_PER_DEAL' ? money(r.value) : `${Number(r.value)}%`} ·{' '}
                    {t('priority')} {r.priority}
                  </p>
                  <p className="text-xs text-muted-foreground">{describeConditions(r, t)}</p>
                </div>
                <Badge tone={r.isActive ? 'success' : 'neutral'}>
                  {r.isActive ? t('active') : t('inactive')}
                </Badge>
                <Button
                  size="icon-sm"
                  variant="ghost"
                  aria-label={t('edit')}
                  onClick={() => setEditing(r)}
                >
                  <Pencil />
                </Button>
              </li>
            ))}
          </ul>
        )}
      </Card>
      <RuleDialog
        rule={editing === 'new' ? null : editing}
        open={editing !== null}
        onOpenChange={(o) => (!o ? setEditing(null) : undefined)}
      />
    </div>
  );
}

'use client';

import {
  EXPENSE_CATEGORIES,
  type ExpenseCategory,
  type ExpenseDto,
  type ExpenseScope,
} from '@fluggi/contracts';
import { useTranslations } from 'next-intl';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter } from '@/components/ui/dialog';
import { Input, NativeSelect } from '@/components/ui/input';
import { Field } from '@/components/ui/label';
import { MoneyInput } from '@/components/ui/money-input';
import { Textarea } from '@/components/ui/textarea';
import { useCrmMutation } from '@/features/crm/api';
import { useProject, useProjects } from '@/features/projects/api';
import { useUsers } from '@/features/team/api';
import { ApiError, api, errorMessage } from '@/lib/api-client';
import { newIdempotencyKey } from '@/lib/format';
import { useCan } from '@/lib/me-context';

const today = () => new Date(Date.now() + 5 * 3600_000).toISOString().slice(0, 10);

/**
 * Новый расход или исправление (ТЗ §26). В карточке проекта проект фиксирован;
 * расход компании доступен только CEO (право finance.company.read).
 */
export function ExpenseDialog({
  open,
  onOpenChange,
  expense,
  projectId,
}: {
  open: boolean;
  onOpenChange: (o: boolean) => void;
  expense?: ExpenseDto | null;
  /** Проект зафиксирован (диалог открыт из карточки проекта). */
  projectId?: string;
}) {
  const t = useTranslations('finance');
  const can = useCan();
  const company = can('finance.company.read', 'ALL');
  const [v, setV] = useState({
    scope: 'PROJECT' as ExpenseScope,
    projectId: '',
    category: 'EXECUTOR' as ExpenseCategory,
    amount: '',
    currency: 'UZS',
    expenseDate: today(),
    payeeUserId: '',
    description: '',
  });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const key = useMemo(() => (open ? newIdempotencyKey() : ''), [open]);
  const projects = useProjects({ view: 'all', pageSize: 100 });
  // Получатель: CEO выбирает из всех сотрудников, РОП — из команды проекта.
  const allPeople = can('employee.read', 'ALL');
  const users = useUsers({ pageSize: 100, status: 'ACTIVE' }, open && allPeople);
  const pid = expense?.project?.id ?? projectId ?? v.projectId;
  const project = useProject(pid, open && !allPeople && Boolean(pid));
  const payees = allPeople
    ? (users.data?.items.map((u) => ({ id: u.id, name: u.fullName })) ?? [])
    : project.data
      ? [project.data.rop, project.data.manager, ...project.data.members.map((m) => m.user)].filter(
          (x, i, a) => a.findIndex((y) => y.id === x.id) === i,
        )
      : [];
  if (expense?.payee && !payees.some((p) => p.id === expense.payee!.id)) payees.push(expense.payee);
  useEffect(() => {
    if (!open) return;
    setErrors({});
    setV(
      expense
        ? {
            scope: expense.scope,
            projectId: expense.project?.id ?? '',
            category: expense.category,
            amount: String(Number(expense.amount)),
            currency: expense.currency,
            expenseDate: expense.expenseDate,
            payeeUserId: expense.payee?.id ?? '',
            description: expense.description ?? '',
          }
        : {
            scope: 'PROJECT',
            projectId: projectId ?? '',
            category: 'EXECUTOR',
            amount: '',
            currency: 'UZS',
            expenseDate: today(),
            payeeUserId: '',
            description: '',
          },
    );
  }, [open, expense, projectId]);
  const set =
    (k: keyof typeof v) =>
    (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>) =>
      setV((s) => ({ ...s, [k]: e.target.value }));
  const save = useCrmMutation(() => {
    const body = {
      category: v.category,
      amount: v.amount,
      currency: v.currency,
      expenseDate: v.expenseDate,
      payeeUserId: v.payeeUserId || null,
      description: v.description || null,
    };
    return expense
      ? api(`/expenses/${expense.id}`, { method: 'PATCH', body })
      : api('/expenses', {
          method: 'POST',
          idempotencyKey: key,
          body: {
            ...body,
            scope: v.scope,
            projectId: v.scope === 'PROJECT' ? v.projectId || null : null,
          },
        });
  });

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        title={expense ? `${t('expenses.edit')} ${expense.number}` : t('expenses.new')}
      >
        <form
          className="grid gap-4"
          onSubmit={async (e) => {
            e.preventDefault();
            try {
              await save.mutateAsync(undefined);
              toast.success(expense ? t('expenses.saved') : t('expenses.created'));
              onOpenChange(false);
            } catch (err) {
              if (err instanceof ApiError) setErrors(err.fieldErrors());
              toast.error(errorMessage(err));
            }
          }}
        >
          {!expense && !projectId ? (
            <div className="grid gap-4 sm:grid-cols-[10rem_1fr]">
              <Field label={t('expenses.scope')} htmlFor="ex-scope">
                <NativeSelect id="ex-scope" value={v.scope} onChange={set('scope')}>
                  <option value="PROJECT">{t('scope.PROJECT')}</option>
                  {company ? <option value="COMPANY">{t('scope.COMPANY')}</option> : null}
                </NativeSelect>
              </Field>
              {v.scope === 'PROJECT' ? (
                <Field label={t('expenses.project')} htmlFor="ex-project" error={errors.projectId}>
                  <NativeSelect
                    id="ex-project"
                    required
                    value={v.projectId}
                    onChange={set('projectId')}
                  >
                    <option value="">—</option>
                    {projects.data?.items.map((p) => (
                      <option key={p.id} value={p.id}>
                        {p.number} · {p.name}
                      </option>
                    ))}
                  </NativeSelect>
                </Field>
              ) : null}
            </div>
          ) : null}
          <div className="grid gap-4 sm:grid-cols-[1fr_6rem]">
            <Field label={t('expenses.amount')} htmlFor="ex-amount" error={errors.amount}>
              <MoneyInput
                id="ex-amount"
                required
                value={v.amount}
                onChange={set('amount')}
                autoFocus
              />
            </Field>
            <Field label="Валюта" htmlFor="ex-cur">
              <NativeSelect id="ex-cur" value={v.currency} onChange={set('currency')}>
                <option>UZS</option>
                <option>USD</option>
              </NativeSelect>
            </Field>
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label={t('expenses.category')} htmlFor="ex-cat">
              <NativeSelect id="ex-cat" value={v.category} onChange={set('category')}>
                {EXPENSE_CATEGORIES.map((c) => (
                  <option key={c} value={c}>
                    {t(`category.${c}`)}
                  </option>
                ))}
              </NativeSelect>
            </Field>
            <Field label={t('expenses.date')} htmlFor="ex-date" error={errors.expenseDate}>
              <Input
                id="ex-date"
                type="date"
                required
                value={v.expenseDate}
                onChange={set('expenseDate')}
              />
            </Field>
            <Field label={t('expenses.payee')} htmlFor="ex-payee" className="sm:col-span-2">
              <NativeSelect id="ex-payee" value={v.payeeUserId} onChange={set('payeeUserId')}>
                <option value="">{t('expenses.noPayee')}</option>
                {payees.map((u) => (
                  <option key={u.id} value={u.id}>
                    {u.name}
                  </option>
                ))}
              </NativeSelect>
            </Field>
          </div>
          <Field label={t('expenses.description')} htmlFor="ex-desc">
            <Textarea id="ex-desc" rows={2} value={v.description} onChange={set('description')} />
          </Field>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
              Отмена
            </Button>
            <Button type="submit" loading={save.isPending} disabled={!v.amount}>
              {expense ? 'Сохранить' : t('expenses.new')}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

'use client';

import {
  PAYMENT_METHODS,
  PAYMENT_TYPES,
  type ConfirmPaymentResult,
  type ContractDto,
  type DealDto,
  type PaymentDto,
  type PaymentMethod,
  type PaymentType,
  type ProposalDto,
} from '@fluggi/contracts';
import {
  Download,
  FileSignature,
  FileText,
  History,
  Paperclip,
  Pencil,
  Plus,
  Upload,
} from 'lucide-react';
import { useTranslations } from 'next-intl';
import { useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
import { EmptyState, ErrorState, TableSkeleton } from '@/components/shared/states';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter } from '@/components/ui/dialog';
import { Input, NativeSelect } from '@/components/ui/input';
import { MoneyInput } from '@/components/ui/money-input';
import { Field } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useCrmMutation } from '@/features/crm/api';
import { DocumentDialog } from '@/features/documents/document-dialog';
import {
  useContracts,
  useDealFiles,
  usePayments,
  useProposals,
  useProposalVersions,
} from '@/features/crm/sales-api';
import { ApiError, api, errorMessage } from '@/lib/api-client';
import { date, dateTime, money, newIdempotencyKey } from '@/lib/format';
import { useCan } from '@/lib/me-context';
import { ProposalEditor } from './proposal-editor';
import { SalesBadge } from './status';

const run = async (p: Promise<unknown>, ok: string) => {
  try {
    await p;
    toast.success(ok);
  } catch (err) {
    toast.error(errorMessage(err));
  }
};

// ─────────────────────────── КП ───────────────────────────

function Versions({ id }: { id: string }) {
  const v = useProposalVersions(id);
  if (!v.data) return null;
  return (
    <ul className="mt-2 grid gap-1 border-l pl-3 text-xs text-muted-foreground">
      {v.data.map((x) => (
        <li key={x.version}>
          v{x.version} — {money(x.total, x.currency)} · {x.author.name} · {dateTime(x.createdAt)}
          {x.comment ? ` · ${x.comment}` : ''}
        </li>
      ))}
    </ul>
  );
}

export function ProposalsPanel({ deal }: { deal: DealDto }) {
  const t = useTranslations('sales.proposals');
  const can = useCan();
  const list = useProposals({ dealId: deal.id, pageSize: 50 });
  const [editing, setEditing] = useState<{ open: boolean; p: ProposalDto | null }>({
    open: false,
    p: null,
  });
  const [showVersions, setShowVersions] = useState<string | null>(null);
  const [doc, setDoc] = useState<ProposalDto | null>(null);
  const act = useCrmMutation(({ id, action }: { id: string; action: string }) =>
    api(`/proposals/${id}/${action}`, { method: 'POST' }),
  );
  const open = deal.status === 'OPEN';

  return (
    <div className="grid gap-4">
      {open && can('proposal.create') ? (
        <div>
          <Button size="sm" onClick={() => setEditing({ open: true, p: null })}>
            <Plus /> {t('new')}
          </Button>
        </div>
      ) : null}
      {list.isPending ? (
        <TableSkeleton rows={2} cols={2} />
      ) : list.isError ? (
        <ErrorState error={list.error} onRetry={() => list.refetch()} />
      ) : list.data.items.length === 0 ? (
        <EmptyState icon={FileText} title={t('empty')} />
      ) : (
        <ul className="divide-y rounded-lg border">
          {list.data.items.map((p) => {
            const actions: {
              a: string;
              label: string;
              perm: Parameters<typeof can>[0];
              variant?: 'outline' | 'default';
            }[] = [];
            if (p.status === 'DRAFT') {
              if (!p.approvedBy)
                actions.push({
                  a: 'submit-approval',
                  label: t('submitApproval'),
                  perm: 'proposal.update',
                  variant: 'outline',
                });
              actions.push({ a: 'send', label: t('send'), perm: 'proposal.send' });
            }
            if (
              p.status === 'IN_APPROVAL' ||
              (p.status === 'DRAFT' && !p.approvedBy && can('proposal.approve'))
            )
              actions.push({
                a: 'approve',
                label: t('approve'),
                perm: 'proposal.approve',
                variant: 'outline',
              });
            if (p.status === 'SENT')
              actions.push({
                a: 'mark-viewed',
                label: t('viewed'),
                perm: 'proposal.update',
                variant: 'outline',
              });
            if (p.status === 'SENT' || p.status === 'VIEWED') {
              actions.push({ a: 'accept', label: t('accept'), perm: 'proposal.update' });
              actions.push({
                a: 'reject',
                label: t('reject'),
                perm: 'proposal.update',
                variant: 'outline',
              });
            }
            if (p.status === 'REJECTED')
              actions.push({
                a: 'send',
                label: t('send'),
                perm: 'proposal.send',
                variant: 'outline',
              });
            return (
              <li key={p.id} className="grid gap-2 px-4 py-3">
                <div className="flex flex-wrap items-center gap-2">
                  <span className="font-medium">
                    {p.number} · {p.title}
                  </span>
                  <SalesBadge kind="proposalStatus" status={p.status} />
                  <span className="text-xs text-muted-foreground">v{p.currentVersion}</span>
                  {p.approvedBy ? (
                    <span className="text-xs text-success">
                      {t('approvedBy', { name: p.approvedBy.name })}
                    </span>
                  ) : null}
                  <span className="ml-auto text-base font-semibold">
                    {money(p.total, p.currency)}
                  </span>
                </div>
                <p className="text-sm text-muted-foreground">
                  {p.items.map((i) => i.description).join(' · ')}
                </p>
                <div className="flex flex-wrap gap-2">
                  {open
                    ? actions
                        .filter((x) => can(x.perm))
                        .map((x) => (
                          <Button
                            key={x.a}
                            size="sm"
                            variant={x.variant ?? 'default'}
                            disabled={act.isPending}
                            onClick={() =>
                              run(act.mutateAsync({ id: p.id, action: x.a }), t('statusChanged'))
                            }
                          >
                            {x.label}
                          </Button>
                        ))
                    : null}
                  {open && can('proposal.update') && p.status !== 'ACCEPTED' ? (
                    <Button size="sm" variant="ghost" onClick={() => setEditing({ open: true, p })}>
                      <Pencil /> Изменить
                    </Button>
                  ) : null}
                  <Button size="sm" variant="ghost" onClick={() => setDoc(p)}>
                    <FileText /> {t('document')}
                  </Button>
                  <Button
                    size="sm"
                    variant="ghost"
                    onClick={() => setShowVersions(showVersions === p.id ? null : p.id)}
                  >
                    <History /> {t('versions')}
                  </Button>
                </div>
                {showVersions === p.id ? <Versions id={p.id} /> : null}
              </li>
            );
          })}
        </ul>
      )}
      <DocumentDialog
        kind="proposals"
        id={doc?.id ?? null}
        title={doc ? `${doc.number} · ${doc.title}` : ''}
        onClose={() => setDoc(null)}
      />
      <ProposalEditor
        open={editing.open}
        onOpenChange={(o) => setEditing((s) => ({ ...s, open: o }))}
        dealId={deal.id}
        proposal={editing.p}
      />
    </div>
  );
}

// ─────────────────────────── Файлы ───────────────────────────

export function useUpload(dealId: string) {
  const t = useTranslations('sales.files');
  const upload = useCrmMutation(
    ({ file, contractId, category }: { file: File; contractId?: string; category: string }) => {
      const form = new FormData();
      form.append('dealId', dealId);
      if (contractId) form.append('contractId', contractId);
      form.append('category', category);
      form.append('file', file);
      return api('/files', { method: 'POST', body: form });
    },
  );
  const pick = (opts: { contractId?: string; category: string }) => {
    const input = document.createElement('input');
    input.type = 'file';
    input.accept = '.pdf,.png,.jpg,.jpeg,.webp,.docx,.xlsx,.zip';
    input.onchange = () => {
      const file = input.files?.[0];
      if (file) void run(upload.mutateAsync({ file, ...opts }), t('uploaded'));
    };
    input.click();
  };
  return { pick, pending: upload.isPending };
}

export function FilesPanel({ deal }: { deal: DealDto }) {
  const t = useTranslations('sales.files');
  const can = useCan();
  const files = useDealFiles(deal.id);
  const { pick, pending } = useUpload(deal.id);
  return (
    <div className="grid gap-4">
      {can('deal.update') ? (
        <div className="flex items-center gap-3">
          <Button
            size="sm"
            onClick={() => pick({ category: 'DOCUMENT' })}
            loading={pending}
            loadingText={t('uploading')}
          >
            <Upload /> {t('upload')}
          </Button>
          <span className="text-xs text-muted-foreground">{t('hint')}</span>
        </div>
      ) : null}
      {files.isPending ? (
        <TableSkeleton rows={2} cols={1} />
      ) : files.isError ? (
        <ErrorState error={files.error} />
      ) : files.data.length === 0 ? (
        <EmptyState icon={Paperclip} title={t('empty')} />
      ) : (
        <ul className="divide-y rounded-lg border">
          {files.data.map((f) => (
            <li key={f.id} className="flex items-center gap-3 px-4 py-2.5 text-sm">
              <Paperclip className="size-4 text-muted-foreground" />
              <span className="min-w-0 flex-1 truncate">{f.originalName}</span>
              <span className="text-xs text-muted-foreground">
                {(f.sizeBytes / 1024).toFixed(0)} КБ · {f.uploadedBy.name} · {date(f.createdAt)}
              </span>
              <Button asChild size="icon-sm" variant="ghost" aria-label={t('download')}>
                <a href={`/api/v1/files/${f.id}/download`}>
                  <Download />
                </a>
              </Button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

// ─────────────────────────── Договоры ───────────────────────────

function ContractDialog({
  deal,
  open,
  onOpenChange,
}: {
  deal: DealDto;
  open: boolean;
  onOpenChange: (o: boolean) => void;
}) {
  const t = useTranslations('sales.contracts');
  const accepted = useProposals({ dealId: deal.id, status: 'ACCEPTED', pageSize: 10 }, open);
  const [proposalId, setProposalId] = useState('');
  const [amount, setAmount] = useState('');
  const [currency, setCurrency] = useState(deal.currency);
  const [contractDate, setContractDate] = useState('');
  const [comment, setComment] = useState('');
  const key = useMemo(() => (open ? newIdempotencyKey() : ''), [open]);
  useEffect(() => {
    if (!open) return;
    setProposalId('');
    setAmount(String(Number(deal.amount)));
    setCurrency(deal.currency);
    setContractDate(new Date(Date.now() + 5 * 3600_000).toISOString().slice(0, 10));
    setComment('');
  }, [open, deal]);
  useEffect(() => {
    const p = accepted.data?.items.find((x) => x.id === proposalId);
    if (p) {
      setAmount(String(Number(p.total)));
      setCurrency(p.currency);
    }
  }, [proposalId, accepted.data]);
  const create = useCrmMutation(() =>
    api<ContractDto>('/contracts', {
      method: 'POST',
      idempotencyKey: key,
      body: {
        dealId: deal.id,
        proposalId: proposalId || undefined,
        amount,
        currency,
        contractDate,
        comment: comment || undefined,
      },
    }),
  );
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent title={t('new')}>
        <div className="grid gap-4">
          <Field label={t('fromProposal')} htmlFor="ct-kp">
            <NativeSelect
              id="ct-kp"
              value={proposalId}
              onChange={(e) => setProposalId(e.target.value)}
            >
              <option value="">—</option>
              {accepted.data?.items.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.number} · {p.title} · {money(p.total, p.currency)}
                </option>
              ))}
            </NativeSelect>
          </Field>
          <div className="grid gap-4 sm:grid-cols-[1fr_6rem_10rem]">
            <Field label={t('amount')} htmlFor="ct-amount">
              <MoneyInput
                id="ct-amount"
                value={amount}
                onChange={(e) => setAmount(e.target.value)}
                disabled={Boolean(proposalId)}
              />
            </Field>
            <Field label="Валюта" htmlFor="ct-cur">
              <NativeSelect
                id="ct-cur"
                value={currency}
                onChange={(e) => setCurrency(e.target.value as typeof currency)}
                disabled={Boolean(proposalId)}
              >
                <option>UZS</option>
                <option>USD</option>
              </NativeSelect>
            </Field>
            <Field label={t('date')} htmlFor="ct-date">
              <Input
                id="ct-date"
                type="date"
                value={contractDate}
                onChange={(e) => setContractDate(e.target.value)}
              />
            </Field>
          </div>
          <Field label="Комментарий" htmlFor="ct-comment">
            <Textarea
              id="ct-comment"
              value={comment}
              onChange={(e) => setComment(e.target.value)}
            />
          </Field>
        </div>
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            Отмена
          </Button>
          <Button
            disabled={!amount || !contractDate}
            loading={create.isPending}
            loadingText="Создание..."
            onClick={async () => {
              try {
                await create.mutateAsync(undefined);
                toast.success(t('created'));
                onOpenChange(false);
              } catch (err) {
                toast.error(errorMessage(err));
              }
            }}
          >
            {t('new')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

export function ContractsPanel({ deal }: { deal: DealDto }) {
  const t = useTranslations('sales.contracts');
  const can = useCan();
  const list = useContracts({ dealId: deal.id, pageSize: 50 });
  const [open, setOpen] = useState(false);
  const [doc, setDoc] = useState<ContractDto | null>(null);
  const act = useCrmMutation(({ id, action }: { id: string; action: string }) =>
    api(`/contracts/${id}/${action}`, { method: 'POST' }),
  );
  const { pick, pending } = useUpload(deal.id);
  const editable = deal.status === 'OPEN' || deal.status === 'WON';
  return (
    <div className="grid gap-4">
      {deal.status === 'OPEN' && can('contract.create') ? (
        <div>
          <Button size="sm" onClick={() => setOpen(true)}>
            <Plus /> {t('new')}
          </Button>
        </div>
      ) : null}
      {list.isPending ? (
        <TableSkeleton rows={2} cols={2} />
      ) : list.isError ? (
        <ErrorState error={list.error} />
      ) : list.data.items.length === 0 ? (
        <EmptyState icon={FileText} title={t('empty')} />
      ) : (
        <ul className="divide-y rounded-lg border">
          {list.data.items.map((c) => (
            <li key={c.id} className="grid gap-2 px-4 py-3">
              <div className="flex flex-wrap items-center gap-2">
                <span className="font-medium">
                  {c.number} от {date(c.contractDate)}
                </span>
                <SalesBadge kind="contractStatus" status={c.status} />
                {c.proposal ? (
                  <span className="text-xs text-muted-foreground">по {c.proposal.number}</span>
                ) : null}
                <span className="ml-auto text-right">
                  <span className="block font-semibold">{money(c.amount, c.currency)}</span>
                  <span className="block text-xs text-muted-foreground">
                    {t('paid')}: {money(c.paidUzs, 'UZS')}
                  </span>
                </span>
              </div>
              <div>
                <Button size="sm" variant="outline" onClick={() => setDoc(c)}>
                  <FileSignature /> {t('document')}
                </Button>
              </div>
              {c.files.length ? (
                <ul className="flex flex-wrap gap-2 text-xs">
                  {c.files.map((f) => (
                    <li key={f.id}>
                      <a
                        className="inline-flex items-center gap-1 rounded border px-2 py-1 hover:bg-muted"
                        href={`/api/v1/files/${f.id}/download`}
                      >
                        <Paperclip className="size-3" />
                        {f.originalName}
                      </a>
                    </li>
                  ))}
                </ul>
              ) : null}
              {editable && can('contract.update') ? (
                <div className="flex flex-wrap gap-2">
                  {c.status === 'DRAFT' ? (
                    <Button
                      size="sm"
                      variant="outline"
                      onClick={() =>
                        run(act.mutateAsync({ id: c.id, action: 'send' }), t('statusChanged'))
                      }
                    >
                      {t('send')}
                    </Button>
                  ) : null}
                  {['DRAFT', 'SENT', 'IN_APPROVAL'].includes(c.status) ? (
                    <Button
                      size="sm"
                      onClick={() =>
                        run(act.mutateAsync({ id: c.id, action: 'sign' }), t('statusChanged'))
                      }
                    >
                      {t('sign')}
                    </Button>
                  ) : null}
                  <Button
                    size="sm"
                    variant="ghost"
                    loading={pending}
                    onClick={() => pick({ contractId: c.id, category: 'CONTRACT' })}
                  >
                    <Upload /> {t('upload')}
                  </Button>
                  {c.status !== 'CANCELLED' ? (
                    <Button
                      size="sm"
                      variant="ghost"
                      onClick={() =>
                        window.confirm(t('cancelConfirm')) &&
                        run(act.mutateAsync({ id: c.id, action: 'cancel' }), t('statusChanged'))
                      }
                    >
                      {t('cancel')}
                    </Button>
                  ) : null}
                </div>
              ) : null}
            </li>
          ))}
        </ul>
      )}
      <ContractDialog deal={deal} open={open} onOpenChange={setOpen} />
      <DocumentDialog
        kind="contracts"
        id={doc?.id ?? null}
        title={doc ? `${t('documentTitle')} ${doc.number}` : ''}
        onClose={() => setDoc(null)}
      />
    </div>
  );
}

// ─────────────────────────── Оплаты ───────────────────────────

function PaymentDialog({
  deal,
  payment,
  open,
  onOpenChange,
}: {
  deal: DealDto;
  /** Есть — исправление неподтверждённой оплаты. */
  payment?: PaymentDto | null;
  open: boolean;
  onOpenChange: (o: boolean) => void;
}) {
  const t = useTranslations('sales');
  const contracts = useContracts({ dealId: deal.id, pageSize: 20 }, open);
  const [v, setV] = useState({
    amount: '',
    currency: deal.currency as string,
    type: 'PREPAYMENT' as PaymentType,
    method: 'BANK' as PaymentMethod,
    contractId: '',
    dueDate: '',
    comment: '',
  });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const key = useMemo(() => (open ? newIdempotencyKey() : ''), [open]);
  useEffect(() => {
    if (open) {
      setErrors({});
      setV(
        payment
          ? {
              amount: String(Number(payment.amount)),
              currency: payment.currency,
              type: payment.type,
              method: payment.method,
              contractId: payment.contract?.id ?? '',
              dueDate: payment.dueDate ?? '',
              comment: payment.comment ?? '',
            }
          : {
              amount: '',
              currency: deal.currency,
              type: 'PREPAYMENT',
              method: 'BANK',
              contractId: '',
              dueDate: '',
              comment: '',
            },
      );
    }
  }, [open, deal, payment]);
  useEffect(() => {
    if (payment) return;
    const signed = contracts.data?.items.find((c) => c.status === 'SIGNED');
    if (signed && !v.contractId)
      setV((s) => ({ ...s, contractId: signed.id, currency: signed.currency }));
  }, [contracts.data, v.contractId, payment]);
  const set =
    (k: keyof typeof v) =>
    (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>) =>
      setV((s) => ({ ...s, [k]: e.target.value }));
  const create = useCrmMutation(() =>
    payment
      ? api<PaymentDto>(`/payments/${payment.id}`, {
          method: 'PATCH',
          body: {
            amount: v.amount,
            currency: v.currency,
            type: v.type,
            method: v.method,
            contractId: v.contractId || null,
            dueDate: v.dueDate || null,
            comment: v.comment || null,
          },
        })
      : api<PaymentDto>('/payments', {
          method: 'POST',
          idempotencyKey: key,
          body: {
            dealId: deal.id,
            amount: v.amount,
            currency: v.currency,
            type: v.type,
            method: v.method,
            contractId: v.contractId || undefined,
            dueDate: v.dueDate || undefined,
            comment: v.comment || undefined,
          },
        }),
  );
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        title={payment ? `${t('payments.edit')} ${payment.number}` : t('payments.new')}
        description={payment ? t('payments.editHint') : undefined}
      >
        <div className="grid gap-4">
          <div className="grid gap-4 sm:grid-cols-[1fr_6rem]">
            <Field label={t('payments.amount')} htmlFor="pay-amount" error={errors.amount}>
              <MoneyInput id="pay-amount" value={v.amount} onChange={set('amount')} autoFocus />
            </Field>
            <Field label="Валюта" htmlFor="pay-cur">
              <NativeSelect id="pay-cur" value={v.currency} onChange={set('currency')}>
                <option>UZS</option>
                <option>USD</option>
              </NativeSelect>
            </Field>
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label={t('payments.type')} htmlFor="pay-type">
              <NativeSelect id="pay-type" value={v.type} onChange={set('type')}>
                {PAYMENT_TYPES.filter((x) => x !== 'REFUND').map((x) => (
                  <option key={x} value={x}>
                    {t(`paymentType.${x}`)}
                  </option>
                ))}
              </NativeSelect>
            </Field>
            <Field label={t('payments.method')} htmlFor="pay-method">
              <NativeSelect id="pay-method" value={v.method} onChange={set('method')}>
                {PAYMENT_METHODS.map((x) => (
                  <option key={x} value={x}>
                    {t(`paymentMethod.${x}`)}
                  </option>
                ))}
              </NativeSelect>
            </Field>
            <Field label={t('payments.contract')} htmlFor="pay-contract">
              <NativeSelect id="pay-contract" value={v.contractId} onChange={set('contractId')}>
                <option value="">—</option>
                {contracts.data?.items
                  .filter((c) => c.status !== 'CANCELLED')
                  .map((c) => (
                    <option key={c.id} value={c.id}>
                      {c.number} · {money(c.amount, c.currency)}
                    </option>
                  ))}
              </NativeSelect>
            </Field>
            <Field label={t('payments.dueDate')} htmlFor="pay-due">
              <Input id="pay-due" type="date" value={v.dueDate} onChange={set('dueDate')} />
            </Field>
          </div>
          <Field label="Комментарий" htmlFor="pay-comment">
            <Textarea id="pay-comment" value={v.comment} onChange={set('comment')} />
          </Field>
        </div>
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            Отмена
          </Button>
          <Button
            disabled={!v.amount}
            loading={create.isPending}
            loadingText={payment ? 'Сохранение...' : 'Создание...'}
            onClick={async () => {
              try {
                await create.mutateAsync(undefined);
                toast.success(payment ? t('payments.updated') : t('payments.created'));
                onOpenChange(false);
              } catch (err) {
                if (err instanceof ApiError) setErrors(err.fieldErrors());
                toast.error(errorMessage(err));
              }
            }}
          >
            {payment ? 'Сохранить' : t('payments.new')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

function RefundDialog({ payment, onClose }: { payment: PaymentDto | null; onClose: () => void }) {
  const t = useTranslations('sales');
  const [amount, setAmount] = useState('');
  const [method, setMethod] = useState<PaymentMethod>('BANK');
  const [comment, setComment] = useState('');
  useEffect(() => {
    if (payment) {
      setAmount('');
      setComment('');
      setMethod(payment.method);
    }
  }, [payment]);
  const refund = useCrmMutation(() =>
    api(`/payments/${payment!.id}/refund`, { method: 'POST', body: { amount, method, comment } }),
  );
  return (
    <Dialog open={payment !== null} onOpenChange={(o) => !o && onClose()}>
      {payment ? (
        <DialogContent
          title={`${t('payments.refundTitle')} · ${payment.number}`}
          description={t('payments.refundHint', { amount: money(payment.refundableUzs, 'UZS') })}
        >
          <div className="grid gap-4">
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label={`${t('payments.amount')}, ${payment.currency}`} htmlFor="rf-amount">
                <MoneyInput
                  id="rf-amount"
                  value={amount}
                  onChange={(e) => setAmount(e.target.value)}
                  autoFocus
                />
              </Field>
              <Field label={t('payments.method')} htmlFor="rf-method">
                <NativeSelect
                  id="rf-method"
                  value={method}
                  onChange={(e) => setMethod(e.target.value as PaymentMethod)}
                >
                  {PAYMENT_METHODS.map((x) => (
                    <option key={x} value={x}>
                      {t(`paymentMethod.${x}`)}
                    </option>
                  ))}
                </NativeSelect>
              </Field>
            </div>
            <Field label={t('payments.refundReason')} htmlFor="rf-comment">
              <Textarea
                id="rf-comment"
                value={comment}
                onChange={(e) => setComment(e.target.value)}
              />
            </Field>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={onClose}>
              Отмена
            </Button>
            <Button
              variant="destructive"
              disabled={!amount || !comment.trim()}
              loading={refund.isPending}
              onClick={async () => {
                try {
                  await refund.mutateAsync(undefined);
                  toast.success(t('payments.refunded'));
                  onClose();
                } catch (err) {
                  toast.error(errorMessage(err));
                }
              }}
            >
              {t('payments.refund')}
            </Button>
          </DialogFooter>
        </DialogContent>
      ) : null}
    </Dialog>
  );
}

export function PaymentsPanel({ deal }: { deal: DealDto }) {
  const t = useTranslations('sales');
  const can = useCan();
  const list = usePayments({ dealId: deal.id, pageSize: 50 });
  const [open, setOpen] = useState(false);
  const [refunding, setRefunding] = useState<PaymentDto | null>(null);
  const [editing, setEditing] = useState<PaymentDto | null>(null);
  const confirm = useCrmMutation((id: string) =>
    api<ConfirmPaymentResult>(`/payments/${id}/confirm`, { method: 'POST', body: {} }),
  );
  const cancel = useCrmMutation((id: string) => api(`/payments/${id}/cancel`, { method: 'POST' }));
  const confirming = useRef(false);

  async function onConfirm(p: PaymentDto) {
    if (
      confirming.current ||
      !window.confirm(
        `${t('payments.confirm')} ${money(p.amount, p.currency)}?\n\n${t('payments.confirmHint')}`,
      )
    )
      return;
    confirming.current = true;
    try {
      const res = await confirm.mutateAsync(p.id);
      const lines = [t('payments.confirmed')];
      if (res.projectCreated && res.project)
        lines.push(t('payments.projectCreated', { number: res.project.number }));
      if (res.commissions.length)
        lines.push(
          t('payments.commissions', {
            list: res.commissions
              .map((c) => `${c.user.name} ${money(c.amountUzs, 'UZS')}`)
              .join(', '),
          }),
        );
      toast.success(lines.join('. '), { duration: 8000 });
    } catch (err) {
      toast.error(errorMessage(err));
    } finally {
      confirming.current = false;
    }
  }

  return (
    <div className="grid gap-4">
      {['OPEN', 'WON'].includes(deal.status) && can('payment.create') ? (
        <div>
          <Button size="sm" onClick={() => setOpen(true)}>
            <Plus /> {t('payments.new')}
          </Button>
        </div>
      ) : null}
      {list.isPending ? (
        <TableSkeleton rows={2} cols={2} />
      ) : list.isError ? (
        <ErrorState error={list.error} />
      ) : list.data.items.length === 0 ? (
        <EmptyState title={t('payments.empty')} />
      ) : (
        <ul className="divide-y rounded-lg border">
          {list.data.items.map((p) => (
            <li key={p.id} className="flex flex-wrap items-center gap-3 px-4 py-3 text-sm">
              <div className="min-w-0 flex-1">
                <p className="font-medium">
                  {p.number} · {t(`paymentType.${p.type}`)} · {t(`paymentMethod.${p.method}`)}
                  {p.refundOf ? (
                    <span className="text-muted-foreground"> (к {p.refundOf.number})</span>
                  ) : null}
                </p>
                <p className="text-xs text-muted-foreground">
                  {p.paidAt
                    ? `${t('payments.paidAt')}: ${dateTime(p.paidAt)}`
                    : p.dueDate
                      ? `${t('payments.dueDate')}: ${date(p.dueDate)}`
                      : dateTime(p.createdAt)}
                  {p.confirmedBy ? ` · ${t('payments.confirmedBy')}: ${p.confirmedBy.name}` : ''}
                  {p.contract ? ` · ${p.contract.number}` : ''}
                  {p.comment ? ` · ${p.comment}` : ''}
                </p>
              </div>
              <SalesBadge kind="paymentStatus" status={p.status} />
              <span
                className={`w-40 text-right font-semibold ${p.type === 'REFUND' ? 'text-danger' : ''}`}
              >
                {p.type === 'REFUND' ? '− ' : ''}
                {money(p.amount, p.currency)}
              </span>
              <div className="flex gap-2">
                {p.status === 'PENDING' && can('payment.confirm') ? (
                  <Button size="sm" onClick={() => onConfirm(p)} loading={confirm.isPending}>
                    {t('payments.confirm')}
                  </Button>
                ) : null}
                {p.status === 'PENDING' && can('payment.create') ? (
                  <>
                    <Button size="sm" variant="outline" onClick={() => setEditing(p)}>
                      <Pencil /> {t('payments.edit')}
                    </Button>
                    <Button
                      size="sm"
                      variant="ghost"
                      onClick={() => run(cancel.mutateAsync(p.id), t('payments.cancelled'))}
                    >
                      {t('payments.cancel')}
                    </Button>
                  </>
                ) : null}
                {p.status === 'PAID' &&
                p.type !== 'REFUND' &&
                Number(p.refundableUzs) > 0 &&
                can('payment.refund') ? (
                  <Button size="sm" variant="ghost" onClick={() => setRefunding(p)}>
                    {t('payments.refund')}
                  </Button>
                ) : null}
              </div>
            </li>
          ))}
        </ul>
      )}
      <PaymentDialog deal={deal} open={open} onOpenChange={setOpen} />
      <PaymentDialog
        deal={deal}
        payment={editing}
        open={Boolean(editing)}
        onOpenChange={(o) => (!o ? setEditing(null) : undefined)}
      />
      <RefundDialog payment={refunding} onClose={() => setRefunding(null)} />
    </div>
  );
}

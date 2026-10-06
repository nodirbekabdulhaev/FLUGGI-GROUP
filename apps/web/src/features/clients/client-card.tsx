'use client';

import type { ContactDto } from '@fluggi/contracts';
import { ArrowLeft, Pencil, Plus, Star, Trash2 } from 'lucide-react';
import { useTranslations } from 'next-intl';
import Link from 'next/link';
import { useState } from 'react';
import { toast } from 'sonner';
import { StageBadge, StatusBadge } from '@/components/crm/badges';
import { DetailList } from '@/components/shared/detail-list';
import { EmptyState, ErrorState, TableSkeleton } from '@/components/shared/states';
import { Tabs } from '@/components/shared/tabs';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { useClient, useCrmMutation } from '@/features/crm/api';
import { CommentsPanel, TimelinePanel } from '@/features/crm/panels';
import { DealFormDialog } from '@/features/deals/deal-form-dialog';
import { api, errorMessage } from '@/lib/api-client';
import { dateTime, money } from '@/lib/format';
import { useCan } from '@/lib/me-context';
import { ClientFormDialog } from './client-form-dialog';
import { ContactDialog } from './contact-dialog';

export function ClientCard({ id }: { id: string }) {
  const t = useTranslations();
  const can = useCan();
  const client = useClient(id);
  const [tab, setTab] = useState('deals');
  const [edit, setEdit] = useState(false);
  const [newDeal, setNewDeal] = useState(false);
  const [contact, setContact] = useState<{ open: boolean; value: ContactDto | null }>({
    open: false,
    value: null,
  });
  const removeContact = useCrmMutation((cid: string) =>
    api(`/contacts/${cid}`, { method: 'DELETE' }),
  );

  if (client.isPending)
    return (
      <Card>
        <TableSkeleton rows={6} cols={2} />
      </Card>
    );
  if (client.isError)
    return (
      <Card>
        <ErrorState error={client.error} onRetry={() => client.refetch()} />
      </Card>
    );
  const c = client.data;
  const canEdit = can('client.update');

  return (
    <>
      <Link
        href="/clients"
        className="mb-4 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
      >
        <ArrowLeft className="size-4" /> {t('clients.title')}
      </Link>
      <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <p className="text-xs text-muted-foreground">
            {c.number} · {t(`crm.clientType.${c.type}`)}
          </p>
          <h1 className="text-xl font-semibold tracking-tight sm:text-2xl">{c.name}</h1>
          <p className="mt-1 text-sm text-muted-foreground">
            {t('clients.dealsCount', { count: c.dealsCount, open: c.openDealsCount })} ·{' '}
            {c.owner.name}
          </p>
        </div>
        <div className="flex gap-2">
          {can('deal.create') ? (
            <Button size="sm" onClick={() => setNewDeal(true)}>
              <Plus /> {t('clients.newDeal')}
            </Button>
          ) : null}
          {canEdit ? (
            <Button size="sm" variant="outline" onClick={() => setEdit(true)}>
              <Pencil /> {t('crm.common.edit')}
            </Button>
          ) : null}
        </div>
      </div>

      <Card className="mb-6">
        <CardContent className="pt-5">
          <DetailList
            rows={[
              [t('crm.fields.phone'), c.phone],
              [t('crm.fields.email'), c.email],
              [t('crm.fields.telegram'), c.telegram],
              [t('crm.fields.website'), c.website],
              [t('crm.fields.industry'), c.industry],
              [t('crm.fields.city'), [c.city, c.country].filter(Boolean).join(', ')],
              [t('crm.fields.source'), c.source?.name],
              [t('crm.fields.createdAt'), dateTime(c.createdAt)],
            ]}
          />
        </CardContent>
      </Card>

      <Tabs
        value={tab}
        onChange={setTab}
        items={[
          { key: 'deals', label: t('clients.deals'), count: c.deals.length },
          { key: 'contacts', label: t('clients.contacts'), count: c.contacts.length },
          { key: 'activity', label: t('leads.tabs.activity') },
          { key: 'comments', label: t('leads.tabs.comments') },
          { key: 'projects', label: t('leads.tabs.projects'), plannedPhase: 4 },
        ]}
      />
      <Card>
        {tab === 'deals' ? (
          c.deals.length === 0 ? (
            <EmptyState title={t('clients.noDeals')} />
          ) : (
            <ul className="divide-y">
              {c.deals.map((d) => (
                <li key={d.id}>
                  <Link
                    href={`/sales/deals/${d.id}`}
                    className="flex flex-wrap items-center gap-3 px-5 py-3 hover:bg-muted/40"
                  >
                    <div className="min-w-0 flex-1">
                      <p className="font-medium">{d.title}</p>
                      <p className="text-xs text-muted-foreground">
                        {d.number} · {d.owner.name}
                      </p>
                    </div>
                    {d.status === 'OPEN' ? (
                      <StageBadge stage={d.stage} />
                    ) : (
                      <StatusBadge status={d.status} kind="deal" />
                    )}
                    <span className="w-36 text-right font-medium">
                      {money(d.amount, d.currency)}
                    </span>
                  </Link>
                </li>
              ))}
            </ul>
          )
        ) : tab === 'contacts' ? (
          <div>
            {canEdit ? (
              <div className="border-b p-4">
                <Button
                  size="sm"
                  variant="outline"
                  onClick={() => setContact({ open: true, value: null })}
                >
                  <Plus /> {t('clients.addContact')}
                </Button>
              </div>
            ) : null}
            {c.contacts.length === 0 ? (
              <EmptyState title={t('clients.noContacts')} />
            ) : (
              <ul className="divide-y">
                {c.contacts.map((ct) => (
                  <li key={ct.id} className="flex items-center gap-3 px-5 py-3 text-sm">
                    <div className="min-w-0 flex-1">
                      <p className="flex items-center gap-1.5 font-medium">
                        {ct.fullName}
                        {ct.isPrimary ? (
                          <Star
                            className="size-3.5 fill-warning text-warning"
                            aria-label={t('clients.primary')}
                          />
                        ) : null}
                      </p>
                      <p className="text-muted-foreground">
                        {[ct.position, ct.phone, ct.telegram, ct.email].filter(Boolean).join(' · ')}
                      </p>
                    </div>
                    {canEdit ? (
                      <>
                        <Button
                          variant="ghost"
                          size="icon-sm"
                          aria-label={t('crm.common.edit')}
                          onClick={() => setContact({ open: true, value: ct })}
                        >
                          <Pencil />
                        </Button>
                        <Button
                          variant="ghost"
                          size="icon-sm"
                          aria-label={t('crm.common.delete')}
                          onClick={async () => {
                            if (
                              !window.confirm(t('crm.common.deleteConfirm', { name: ct.fullName }))
                            )
                              return;
                            try {
                              await removeContact.mutateAsync(ct.id);
                            } catch (err) {
                              toast.error(errorMessage(err));
                            }
                          }}
                        >
                          <Trash2 />
                        </Button>
                      </>
                    ) : null}
                  </li>
                ))}
              </ul>
            )}
          </div>
        ) : (
          <CardContent className="pt-5">
            {tab === 'activity' ? (
              <TimelinePanel target={{ clientId: id }} />
            ) : (
              <CommentsPanel target={{ clientId: id }} />
            )}
          </CardContent>
        )}
      </Card>
      <ClientFormDialog open={edit} onOpenChange={setEdit} client={c} />
      <DealFormDialog open={newDeal} onOpenChange={setNewDeal} clientId={id} />
      <ContactDialog
        clientId={id}
        contact={contact.value}
        open={contact.open}
        onOpenChange={(o) => setContact((s) => ({ ...s, open: o }))}
      />
    </>
  );
}

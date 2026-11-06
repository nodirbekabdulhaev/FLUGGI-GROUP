'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { createTeamSchema, type TeamDto, type UserDto, type Paginated } from '@fluggi/contracts';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { useTranslations } from 'next-intl';
import { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';
import { toast } from 'sonner';
import { EmptyState, ErrorState, TableSkeleton } from '@/components/shared/states';
import { Button } from '@/components/ui/button';
import { Card, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogFooter } from '@/components/ui/dialog';
import { Input, NativeSelect } from '@/components/ui/input';
import { Field } from '@/components/ui/label';
import { api, errorMessage } from '@/lib/api-client';
import { useTeams } from '@/features/team/api';

type FormValues = { name: string; headId: string };

function TeamDialog({
  team,
  open,
  onOpenChange,
}: {
  team: TeamDto | null;
  open: boolean;
  onOpenChange: (o: boolean) => void;
}) {
  const t = useTranslations();
  const qc = useQueryClient();
  const rops = useQuery({
    queryKey: ['users', { roleCode: 'ROP', status: 'ACTIVE', pageSize: 100 }],
    queryFn: () =>
      api<Paginated<UserDto>>('/users', {
        query: { roleCode: 'ROP', status: 'ACTIVE', pageSize: 100 },
      }),
    enabled: open,
  });
  const form = useForm<FormValues>({
    resolver: zodResolver(createTeamSchema.pick({ name: true }).loose()) as never,
  });
  useEffect(() => {
    if (open) form.reset({ name: team?.name ?? '', headId: team?.head?.id ?? '' });
  }, [open, team, form]);

  const save = useMutation({
    mutationFn: (body: { name: string; headId: string | null }) =>
      team
        ? api(`/teams/${team.id}`, { method: 'PATCH', body })
        : api('/teams', { method: 'POST', body }),
    onSuccess: () =>
      Promise.all([
        qc.invalidateQueries({ queryKey: ['teams'] }),
        qc.invalidateQueries({ queryKey: ['users'] }),
      ]),
  });

  const onSubmit = form.handleSubmit(async (v) => {
    try {
      await save.mutateAsync({ name: v.name, headId: v.headId || null });
      toast.success(t(team ? 'settings.teamUpdated' : 'settings.teamCreated'));
      onOpenChange(false);
    } catch (err) {
      toast.error(errorMessage(err));
    }
  });

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent title={team ? team.name : t('settings.addTeam')}>
        <form onSubmit={onSubmit} className="grid gap-4" noValidate>
          <Field
            label={t('settings.teamName')}
            htmlFor="team-name"
            error={form.formState.errors.name?.message}
          >
            <Input id="team-name" autoFocus {...form.register('name')} />
          </Field>
          <Field label={t('settings.teamHead')} htmlFor="team-head">
            <NativeSelect id="team-head" {...form.register('headId')}>
              <option value="">{t('settings.noHead')}</option>
              {rops.data?.items.map((u) => (
                <option key={u.id} value={u.id}>
                  {u.fullName}
                </option>
              ))}
            </NativeSelect>
          </Field>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
              {t('common.cancel')}
            </Button>
            <Button
              type="submit"
              loading={form.formState.isSubmitting}
              loadingText={t('common.saving')}
            >
              {team ? t('common.save') : t('common.create')}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

export function TeamsSettings() {
  const t = useTranslations();
  const qc = useQueryClient();
  const teams = useTeams();
  const [dialog, setDialog] = useState<{ open: boolean; team: TeamDto | null }>({
    open: false,
    team: null,
  });
  const remove = useMutation({
    mutationFn: (id: string) => api(`/teams/${id}`, { method: 'DELETE' }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['teams'] }),
  });

  async function onDelete(team: TeamDto) {
    if (!window.confirm(t('settings.teamDeleteConfirm', { name: team.name }))) return;
    try {
      await remove.mutateAsync(team.id);
      toast.success(t('settings.teamDeleted'));
    } catch (err) {
      toast.error(errorMessage(err));
    }
  }

  const add = (
    <Button onClick={() => setDialog({ open: true, team: null })}>
      <Plus /> {t('settings.addTeam')}
    </Button>
  );

  return (
    <Card>
      <CardHeader className="flex-row items-start justify-between gap-4 border-b pb-5">
        <div className="grid gap-1">
          <CardTitle>{t('settings.teamsTitle')}</CardTitle>
          <CardDescription>{t('settings.teamsText')}</CardDescription>
        </div>
        <div className="hidden sm:block">{add}</div>
      </CardHeader>
      {teams.isPending ? (
        <TableSkeleton rows={3} cols={2} />
      ) : teams.isError ? (
        <ErrorState error={teams.error} onRetry={() => teams.refetch()} />
      ) : teams.data.length === 0 ? (
        <EmptyState
          title={t('settings.teamsEmpty')}
          text={t('settings.teamsEmptyText')}
          action={add}
        />
      ) : (
        <>
          <ul className="divide-y">
            {teams.data.map((team) => (
              <li key={team.id} className="flex items-center gap-4 px-5 py-4">
                <div className="min-w-0 flex-1">
                  <p className="font-medium">{team.name}</p>
                  <p className="text-sm text-muted-foreground">
                    {team.head?.fullName ?? t('dashboard.noHead')} ·{' '}
                    {t('dashboard.members', { count: team.membersCount })}
                  </p>
                </div>
                <Button
                  variant="ghost"
                  size="icon-sm"
                  aria-label={t('common.edit')}
                  onClick={() => setDialog({ open: true, team })}
                >
                  <Pencil />
                </Button>
                <Button
                  variant="ghost"
                  size="icon-sm"
                  aria-label={t('common.delete')}
                  onClick={() => onDelete(team)}
                  disabled={remove.isPending}
                >
                  <Trash2 />
                </Button>
              </li>
            ))}
          </ul>
          <div className="border-t p-4 sm:hidden">{add}</div>
        </>
      )}
      <TeamDialog
        open={dialog.open}
        team={dialog.team}
        onOpenChange={(open) => setDialog((d) => ({ ...d, open }))}
      />
    </Card>
  );
}

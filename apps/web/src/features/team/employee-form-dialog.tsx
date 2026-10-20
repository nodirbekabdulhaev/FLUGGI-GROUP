'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import {
  EXECUTOR_SPECIALTIES,
  ROLE_CODES,
  createUserSchema,
  type CreateUserInput,
  type UserDto,
} from '@fluggi/contracts';
import { useTranslations } from 'next-intl';
import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter } from '@/components/ui/dialog';
import { Input, NativeSelect } from '@/components/ui/input';
import { Field } from '@/components/ui/label';
import { ApiError, errorMessage } from '@/lib/api-client';
import { useReferences } from '@/features/crm/api';
import { useMe } from '@/lib/me-context';
import { useCreateUser, useTeams, useUpdateUser } from './api';

type FormValues = {
  email: string;
  fullName: string;
  phone: string;
  roleCode: CreateUserInput['roleCode'] | '';
  teamId: string;
  position: string;
  specialty: string;
  /** Направления (проект-менеджер): чекбоксы с одним именем дают массив id */
  directionIds: string[] | string | false;
};

const SALES_ROLES = new Set(['ROP', 'MANAGER']);

const formSchema = createUserSchema.pick({ email: true, fullName: true, roleCode: true }).loose();

export function EmployeeFormDialog({
  open,
  onOpenChange,
  user,
  defaultRole,
  onCreated,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** Если передан — режим редактирования. */
  user?: UserDto | null;
  defaultRole?: CreateUserInput['roleCode'];
  onCreated?: (name: string, temporaryPassword?: string) => void;
}) {
  const t = useTranslations();
  const me = useMe();
  const teams = useTeams();
  const refs = useReferences();
  const create = useCreateUser();
  const update = useUpdateUser();

  const form = useForm<FormValues>({ resolver: zodResolver(formSchema) as never });
  const role = form.watch('roleCode');

  useEffect(() => {
    if (!open) return;
    form.reset({
      email: user?.email ?? '',
      fullName: user?.fullName ?? '',
      phone: user?.phone ?? '',
      roleCode: user?.role.code ?? defaultRole ?? '',
      teamId: user?.team?.id ?? '',
      position: user?.position ?? '',
      specialty: user?.specialty ?? '',
      directionIds: user?.directions.map((d) => d.id) ?? [],
    });
  }, [open, user, defaultRole, form]);

  const onSubmit = form.handleSubmit(async (v) => {
    const roleCode = v.roleCode as CreateUserInput['roleCode'];
    const body = {
      email: v.email,
      fullName: v.fullName,
      phone: v.phone,
      roleCode,
      teamId: SALES_ROLES.has(roleCode) && v.teamId ? v.teamId : null,
      position: v.position,
      specialty:
        roleCode === 'EXECUTOR' && v.specialty
          ? (v.specialty as CreateUserInput['specialty'])
          : null,
      directionIds:
        roleCode === 'PROJECT_MANAGER'
          ? ([] as string[]).concat(v.directionIds || []).filter(Boolean)
          : [],
    };
    try {
      if (user) {
        await update.mutateAsync({ id: user.id, body });
        toast.success(t('employees.updated'));
      } else {
        const res = await create.mutateAsync(body);
        toast.success(t('employees.created'));
        onCreated?.(res.user.fullName, res.temporaryPassword);
      }
      onOpenChange(false);
    } catch (err) {
      if (err instanceof ApiError && err.details.length > 0) {
        for (const [path, message] of Object.entries(err.fieldErrors())) {
          form.setError(path as keyof FormValues, { message });
        }
      }
      toast.error(errorMessage(err));
    }
  });

  const { errors, isSubmitting } = form.formState;
  const assignableRoles = ROLE_CODES.filter((r) => r !== 'CEO' || me.role.code === 'CEO');

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        title={user ? t('employees.editTitle') : t('employees.createTitle')}
        description={user ? undefined : t('employees.createDescription')}
      >
        <form onSubmit={onSubmit} className="grid gap-4" noValidate>
          <Field
            label={t('employees.fullName')}
            htmlFor="fullName"
            error={errors.fullName?.message}
          >
            <Input
              id="fullName"
              autoFocus
              aria-invalid={!!errors.fullName}
              {...form.register('fullName')}
            />
          </Field>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label={t('employees.email')} htmlFor="email" error={errors.email?.message}>
              <Input
                id="email"
                type="email"
                aria-invalid={!!errors.email}
                {...form.register('email')}
              />
            </Field>
            <Field label={t('employees.phone')} htmlFor="phone" error={errors.phone?.message}>
              <Input id="phone" type="tel" placeholder="+998" {...form.register('phone')} />
            </Field>
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label={t('employees.role')} htmlFor="roleCode" error={errors.roleCode?.message}>
              <NativeSelect
                id="roleCode"
                aria-invalid={!!errors.roleCode}
                {...form.register('roleCode')}
              >
                <option value="">{t('employees.selectRole')}</option>
                {assignableRoles.map((r) => (
                  <option key={r} value={r}>
                    {t(`roles.${r}`)}
                  </option>
                ))}
              </NativeSelect>
            </Field>
            {role && SALES_ROLES.has(role) ? (
              <Field label={t('employees.team')} htmlFor="teamId" error={errors.teamId?.message}>
                <NativeSelect id="teamId" {...form.register('teamId')}>
                  <option value="">{t('employees.noTeam')}</option>
                  {teams.data?.map((team) => (
                    <option key={team.id} value={team.id}>
                      {team.name}
                    </option>
                  ))}
                </NativeSelect>
              </Field>
            ) : null}
            {role === 'EXECUTOR' ? (
              <Field label={t('employees.specialty')} htmlFor="specialty">
                <NativeSelect id="specialty" {...form.register('specialty')}>
                  <option value="">{t('common.notSet')}</option>
                  {EXECUTOR_SPECIALTIES.map((s) => (
                    <option key={s} value={s}>
                      {t(`specialties.${s}`)}
                    </option>
                  ))}
                </NativeSelect>
              </Field>
            ) : null}
          </div>
          {role === 'PROJECT_MANAGER' ? (
            <fieldset className="grid gap-2 rounded-md border p-3">
              <legend className="px-1 text-sm font-medium">{t('employees.directions')}</legend>
              <p className="text-xs text-muted-foreground">{t('employees.directionsHint')}</p>
              {refs.data?.directions
                .filter((d) => d.isActive)
                .map((d) => (
                  <label key={d.id} className="flex items-center gap-2 text-sm">
                    <input type="checkbox" value={d.id} {...form.register('directionIds')} />
                    {d.name}
                  </label>
                ))}
            </fieldset>
          ) : null}
          <Field
            label={t('employees.position')}
            htmlFor="position"
            error={errors.position?.message}
          >
            <Input id="position" {...form.register('position')} />
          </Field>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
              {t('common.cancel')}
            </Button>
            <Button
              type="submit"
              loading={isSubmitting}
              loadingText={user ? t('common.saving') : t('common.creating')}
            >
              {user ? t('common.save') : t('common.create')}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

import { hasPermission, type RoleCode } from '@fluggi/contracts';
import { notFound } from 'next/navigation';
import { Forbidden } from '@/components/shared/states';
import { EmployeesPage } from '@/features/team/employees-page';
import { getMe } from '@/lib/server-api';

const ROLE_BY_SLUG: Record<string, RoleCode | undefined> = {
  employees: undefined,
  managers: 'MANAGER',
  rop: 'ROP',
  executors: 'EXECUTOR',
};

export default async function TeamPage({
  params,
  searchParams,
}: {
  params: Promise<{ role: string }>;
  searchParams: Promise<{ q?: string }>;
}) {
  const { role } = await params;
  const { q } = await searchParams;
  if (!(role in ROLE_BY_SLUG)) notFound();
  const me = (await getMe())!;
  if (!hasPermission(me.permissions, 'employee.read')) return <Forbidden />;
  return (
    <EmployeesPage key={`${role}:${q ?? ''}`} fixedRole={ROLE_BY_SLUG[role]} initialQuery={q} />
  );
}

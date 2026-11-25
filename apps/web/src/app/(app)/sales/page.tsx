import { hasPermission } from '@fluggi/contracts';
import { redirect } from 'next/navigation';
import { Forbidden } from '@/components/shared/states';
import { getMe } from '@/lib/server-api';

export default async function SalesIndex() {
  const me = (await getMe())!;
  if (hasPermission(me.permissions, 'lead.read')) redirect('/sales/leads');
  if (hasPermission(me.permissions, 'deal.read')) redirect('/sales/deals');
  return <Forbidden />;
}

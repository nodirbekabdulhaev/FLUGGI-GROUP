import type { Metadata } from 'next';
import { DealCard } from '@/features/deals/deal-card';
import { RequirePermission } from '@/lib/guard';

export const metadata: Metadata = { title: 'Сделка' };

export default async function Page({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  return (
    <RequirePermission code="deal.read">
      <DealCard id={id} />
    </RequirePermission>
  );
}

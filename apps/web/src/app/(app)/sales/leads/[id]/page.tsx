import type { Metadata } from 'next';
import { LeadCard } from '@/features/leads/lead-card';
import { RequirePermission } from '@/lib/guard';

export const metadata: Metadata = { title: 'Лид' };

export default async function Page({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  return (
    <RequirePermission code="lead.read">
      <LeadCard id={id} />
    </RequirePermission>
  );
}

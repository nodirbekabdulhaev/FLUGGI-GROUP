import type { Metadata } from 'next';
import { ProposalsPage } from '@/features/sales/lists';
import { RequirePermission } from '@/lib/guard';

export const metadata: Metadata = { title: 'КП' };

export default function Page() {
  return (
    <RequirePermission code="proposal.read">
      <ProposalsPage />
    </RequirePermission>
  );
}

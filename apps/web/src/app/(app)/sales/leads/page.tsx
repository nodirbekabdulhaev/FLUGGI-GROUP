import type { Metadata } from 'next';
import { LeadsPage } from '@/features/leads/leads-page';
import { RequirePermission } from '@/lib/guard';

export const metadata: Metadata = { title: 'Лиды' };

export default function Page() {
  return (
    <RequirePermission code="lead.read">
      <LeadsPage />
    </RequirePermission>
  );
}

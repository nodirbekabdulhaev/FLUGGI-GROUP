import type { Metadata } from 'next';
import { DealsPage } from '@/features/deals/deals-page';
import { RequirePermission } from '@/lib/guard';

export const metadata: Metadata = { title: 'Сделки' };

export default function Page() {
  return (
    <RequirePermission code="deal.read">
      <DealsPage />
    </RequirePermission>
  );
}

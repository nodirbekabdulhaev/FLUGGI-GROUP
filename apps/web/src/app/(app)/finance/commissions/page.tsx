import type { Metadata } from 'next';
import { CommissionsPage } from '@/features/sales/lists';
import { RequirePermission } from '@/lib/guard';

export const metadata: Metadata = { title: 'Комиссии' };

export default function Page() {
  return (
    <RequirePermission code="commission.read">
      <CommissionsPage />
    </RequirePermission>
  );
}

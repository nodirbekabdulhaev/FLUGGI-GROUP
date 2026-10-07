import type { Metadata } from 'next';
import { PaymentsPage } from '@/features/sales/lists';
import { RequirePermission } from '@/lib/guard';

export const metadata: Metadata = { title: 'Оплаты' };

export default function Page() {
  return (
    <RequirePermission code="payment.read">
      <PaymentsPage />
    </RequirePermission>
  );
}

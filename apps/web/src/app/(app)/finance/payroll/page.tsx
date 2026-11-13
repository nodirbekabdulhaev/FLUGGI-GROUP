import type { Metadata } from 'next';
import { PayrollPage } from '@/features/people/payroll-page';
import { RequirePermission } from '@/lib/guard';

export const metadata: Metadata = { title: 'Зарплата' };

export default function Page() {
  return (
    <RequirePermission code="payroll.read">
      <PayrollPage />
    </RequirePermission>
  );
}

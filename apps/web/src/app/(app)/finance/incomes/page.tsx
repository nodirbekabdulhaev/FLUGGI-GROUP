import type { Metadata } from 'next';
import { IncomesPage } from '@/features/finance/incomes-page';
import { RequirePermission } from '@/lib/guard';

export const metadata: Metadata = { title: 'Прочие поступления' };

export default function Page() {
  return (
    <RequirePermission code="finance.company.read" minScope="ALL">
      <IncomesPage />
    </RequirePermission>
  );
}

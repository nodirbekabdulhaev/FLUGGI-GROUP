import type { Metadata } from 'next';
import { ProfitPage } from '@/features/finance/profit-page';
import { RequirePermission } from '@/lib/guard';

export const metadata: Metadata = { title: 'Прибыль по проектам' };

export default function Page() {
  return (
    <RequirePermission code="finance.read">
      <ProfitPage />
    </RequirePermission>
  );
}

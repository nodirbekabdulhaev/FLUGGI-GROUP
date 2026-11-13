import type { Metadata } from 'next';
import { ExpensesPage } from '@/features/finance/expenses-page';
import { RequirePermission } from '@/lib/guard';

export const metadata: Metadata = { title: 'Расходы' };

export default function Page() {
  return (
    <RequirePermission code="finance.read">
      <ExpensesPage />
    </RequirePermission>
  );
}

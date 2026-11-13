import type { Metadata } from 'next';
import { ContractsPage } from '@/features/sales/lists';
import { RequirePermission } from '@/lib/guard';

export const metadata: Metadata = { title: 'Договоры' };

export default function Page() {
  return (
    <RequirePermission code="contract.read">
      <ContractsPage />
    </RequirePermission>
  );
}

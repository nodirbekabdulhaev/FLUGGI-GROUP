import type { Metadata } from 'next';
import { Suspense } from 'react';
import { FinanceDashboardPage } from '@/features/finance/dashboard-page';
import { RequirePermission } from '@/lib/guard';

export const metadata: Metadata = { title: 'Финансовый обзор' };

export default function Page() {
  return (
    <RequirePermission code="finance.read">
      <Suspense>
        <FinanceDashboardPage />
      </Suspense>
    </RequirePermission>
  );
}

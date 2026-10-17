import type { Metadata } from 'next';
import { KpiPage } from '@/features/people/kpi-page';
import { RequirePermission } from '@/lib/guard';

export const metadata: Metadata = { title: 'KPI' };

export default function Page() {
  return (
    <RequirePermission code="kpi.read">
      <KpiPage />
    </RequirePermission>
  );
}

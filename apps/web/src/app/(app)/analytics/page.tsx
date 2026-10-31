import type { Metadata } from 'next';
import { Suspense } from 'react';
import { AnalyticsPage } from '@/features/analytics/analytics-page';
import { RequirePermission } from '@/lib/guard';

export const metadata: Metadata = { title: 'Аналитика' };

export default function Page() {
  return (
    <RequirePermission code="analytics.read">
      <Suspense>
        <AnalyticsPage />
      </Suspense>
    </RequirePermission>
  );
}

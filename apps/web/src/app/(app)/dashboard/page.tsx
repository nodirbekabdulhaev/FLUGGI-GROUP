import type { Metadata } from 'next';
import { Suspense } from 'react';
import { DashboardPage } from '@/features/dashboard/dashboard-page';

export const metadata: Metadata = { title: 'Dashboard' };

export default function Page() {
  return (
    <Suspense>
      <DashboardPage />
    </Suspense>
  );
}

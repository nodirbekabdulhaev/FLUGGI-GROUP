import type { Metadata } from 'next';
import { Suspense } from 'react';
import { InboxPage } from '@/features/integrations/inbox-page';
import { RequirePermission } from '@/lib/guard';

export const metadata: Metadata = { title: 'Входящие' };

export default function Page() {
  return (
    <RequirePermission code="lead.read">
      <Suspense>
        <InboxPage />
      </Suspense>
    </RequirePermission>
  );
}

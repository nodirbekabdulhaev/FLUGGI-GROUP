import type { Metadata } from 'next';
import { FollowUpsPage } from '@/features/automation/follow-ups-page';
import { RequirePermission } from '@/lib/guard';

export const metadata: Metadata = { title: 'Повторные продажи' };

export default function Page() {
  return (
    <RequirePermission code="client.read">
      <FollowUpsPage />
    </RequirePermission>
  );
}

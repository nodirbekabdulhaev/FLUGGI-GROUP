import type { Metadata } from 'next';
import { MeetingsPage } from '@/features/meetings/meetings-page';
import { RequirePermission } from '@/lib/guard';

export const metadata: Metadata = { title: 'Встречи' };

export default function Page() {
  return (
    <RequirePermission code="meeting.read">
      <MeetingsPage />
    </RequirePermission>
  );
}

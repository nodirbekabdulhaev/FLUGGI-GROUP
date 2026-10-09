import type { Metadata } from 'next';
import { AttendancePage } from '@/features/people/attendance-page';
import { RequirePermission } from '@/lib/guard';

export const metadata: Metadata = { title: 'Посещаемость' };

export default function Page() {
  return (
    <RequirePermission code="attendance.read">
      <AttendancePage />
    </RequirePermission>
  );
}

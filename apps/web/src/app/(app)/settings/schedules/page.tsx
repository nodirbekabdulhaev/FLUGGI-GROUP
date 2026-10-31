import { SchedulesSettings } from '@/features/people/schedules-settings';
import { RequirePermission } from '@/lib/guard';

export default function Page() {
  return (
    <RequirePermission code="schedule.manage">
      <SchedulesSettings />
    </RequirePermission>
  );
}

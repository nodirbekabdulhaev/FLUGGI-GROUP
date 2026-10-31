import { TeamsSettings } from '@/features/settings/teams-settings';
import { RequirePermission } from '@/lib/guard';

export default function Page() {
  return (
    <RequirePermission code="employee.manage" minScope="ALL">
      <TeamsSettings />
    </RequirePermission>
  );
}

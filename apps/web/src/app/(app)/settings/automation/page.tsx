import { AutomationSettingsPage } from '@/features/automation/automation-settings';
import { RequirePermission } from '@/lib/guard';

export default function Page() {
  return (
    <RequirePermission code="settings.manage">
      <AutomationSettingsPage />
    </RequirePermission>
  );
}

import { IntegrationsSettingsPage } from '@/features/integrations/integrations-settings';
import { RequirePermission } from '@/lib/guard';

export default function Page() {
  return (
    <RequirePermission code="settings.manage" minScope="ALL">
      <IntegrationsSettingsPage />
    </RequirePermission>
  );
}

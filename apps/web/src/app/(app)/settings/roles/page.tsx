import { RolesSettings } from '@/features/settings/roles-settings';
import { RequirePermission } from '@/lib/guard';

export default function Page() {
  return (
    <RequirePermission code="role.manage" minScope="ALL">
      <RolesSettings />
    </RequirePermission>
  );
}

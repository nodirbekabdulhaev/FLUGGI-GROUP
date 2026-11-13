import { TariffsSettingsPage } from '@/features/finance/tariffs-settings';
import { RequirePermission } from '@/lib/guard';

export default function Page() {
  return (
    <RequirePermission code="reference.manage">
      <TariffsSettingsPage />
    </RequirePermission>
  );
}

import { FinanceSettingsPage } from '@/features/finance/finance-settings';
import { RequirePermission } from '@/lib/guard';

export default function Page() {
  return (
    <RequirePermission code="reference.manage">
      <FinanceSettingsPage />
    </RequirePermission>
  );
}

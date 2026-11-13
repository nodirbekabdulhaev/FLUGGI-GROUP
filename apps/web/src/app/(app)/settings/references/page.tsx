import { ReferencesSettings } from '@/features/settings/references-settings';
import { RequirePermission } from '@/lib/guard';

export default function Page() {
  return (
    <RequirePermission code="reference.manage">
      <ReferencesSettings />
    </RequirePermission>
  );
}

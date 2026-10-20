import { DocumentsSettingsPage } from '@/features/documents/documents-settings';
import { RequirePermission } from '@/lib/guard';

export default function Page() {
  return (
    <RequirePermission code="settings.manage" minScope="ALL">
      <DocumentsSettingsPage />
    </RequirePermission>
  );
}

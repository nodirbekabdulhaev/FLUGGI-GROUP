import { TemplatesSettings } from '@/features/projects/templates-settings';
import { RequirePermission } from '@/lib/guard';

export default function Page() {
  return (
    <RequirePermission code="reference.manage">
      <TemplatesSettings />
    </RequirePermission>
  );
}

import { AuditLog } from '@/features/settings/audit-log';
import { RequirePermission } from '@/lib/guard';

export default function Page() {
  return (
    <RequirePermission code="audit.read" minScope="ALL">
      <AuditLog />
    </RequirePermission>
  );
}

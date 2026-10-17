import { CommissionRulesSettings } from '@/features/finance/commission-rules';
import { RequirePermission } from '@/lib/guard';

export default function Page() {
  return (
    <RequirePermission code="commission_rule.manage">
      <CommissionRulesSettings />
    </RequirePermission>
  );
}

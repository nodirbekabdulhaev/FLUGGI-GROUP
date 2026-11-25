'use client';

import { useTranslations } from 'next-intl';
import { Badge } from '@/components/ui/badge';

type Tone = 'neutral' | 'accent' | 'success' | 'warning' | 'danger';
const TONES: Record<string, Tone> = {
  DRAFT: 'neutral',
  SENT: 'accent',
  VIEWED: 'accent',
  IN_APPROVAL: 'warning',
  ACCEPTED: 'success',
  REJECTED: 'danger',
  EXPIRED: 'danger',
  SIGNED: 'success',
  CANCELLED: 'danger',
  PENDING: 'warning',
  PAID: 'success',
  ACCRUED: 'accent',
  APPROVED: 'success',
  NEW: 'accent',
  IN_PROGRESS: 'accent',
  COMPLETED: 'success',
};

export function SalesBadge({
  kind,
  status,
}: {
  kind:
    'proposalStatus' | 'contractStatus' | 'paymentStatus' | 'projectStatus' | 'commissionStatus';
  status: string;
}) {
  const t = useTranslations('sales');
  return <Badge tone={TONES[status] ?? 'neutral'}>{t(`${kind}.${status}`)}</Badge>;
}

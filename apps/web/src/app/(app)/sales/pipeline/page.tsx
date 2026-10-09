import { hasPermission } from '@fluggi/contracts';
import type { Metadata } from 'next';
import { Forbidden } from '@/components/shared/states';
import { PipelineBoard } from '@/features/pipeline/pipeline-board';
import { getMe } from '@/lib/server-api';

export const metadata: Metadata = { title: 'Воронка' };

export default async function Page() {
  const me = (await getMe())!;
  if (!hasPermission(me.permissions, 'lead.read') && !hasPermission(me.permissions, 'deal.read'))
    return <Forbidden />;
  return <PipelineBoard />;
}

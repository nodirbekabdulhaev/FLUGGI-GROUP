import type { Metadata } from 'next';
import { PipelineBoard } from '@/features/pipeline/pipeline-board';

export const metadata: Metadata = { title: 'Воронка' };

export default function Page() {
  return <PipelineBoard />;
}

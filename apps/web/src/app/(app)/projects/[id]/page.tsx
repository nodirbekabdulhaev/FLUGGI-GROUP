import type { Metadata } from 'next';
import { Suspense } from 'react';
import { ProjectCard } from '@/features/projects/project-card';
import { RequirePermission } from '@/lib/guard';

export const metadata: Metadata = { title: 'Проект' };

export default async function Page({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  return (
    <RequirePermission code="project.read">
      <Suspense>
        <ProjectCard id={id} />
      </Suspense>
    </RequirePermission>
  );
}

import type { Metadata } from 'next';
import { ProjectsPage } from '@/features/projects/projects-page';
import { RequirePermission } from '@/lib/guard';

export const metadata: Metadata = { title: 'Проекты в работе' };

export default function Page() {
  return (
    <RequirePermission code="project.read">
      <ProjectsPage view="active" />
    </RequirePermission>
  );
}

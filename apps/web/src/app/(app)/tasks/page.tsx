import type { Metadata } from 'next';
import { TasksPage } from '@/features/projects/tasks-page';
import { RequirePermission } from '@/lib/guard';

export const metadata: Metadata = { title: 'Задачи' };

export default function Page() {
  return (
    <RequirePermission code="task.read">
      <TasksPage />
    </RequirePermission>
  );
}

import type { Metadata } from 'next';
import { Suspense } from 'react';
import { TodosPage } from '@/features/todos/todos-page';

export const metadata: Metadata = { title: 'Мои дела' };

export default function Page() {
  return (
    <Suspense>
      <TodosPage />
    </Suspense>
  );
}

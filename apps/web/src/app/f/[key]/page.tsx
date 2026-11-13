import type { Metadata } from 'next';
import { PublicForm } from '@/features/integrations/public-form';

export const metadata: Metadata = { title: 'Заявка' };

/** Публичная форма заявки для сайта (встраивается через /embed.js или открывается по ссылке). */
export default async function Page({ params }: { params: Promise<{ key: string }> }) {
  const { key } = await params;
  return <PublicForm formKey={key} />;
}

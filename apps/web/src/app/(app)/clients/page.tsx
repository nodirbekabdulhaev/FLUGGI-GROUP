import type { Metadata } from 'next';
import { ClientsPage } from '@/features/clients/clients-page';
import { RequirePermission } from '@/lib/guard';

export const metadata: Metadata = { title: 'Клиенты' };

export default function Page() {
  return (
    <RequirePermission code="client.read">
      <ClientsPage />
    </RequirePermission>
  );
}

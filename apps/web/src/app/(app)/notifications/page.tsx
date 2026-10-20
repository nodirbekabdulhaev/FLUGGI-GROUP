import type { Metadata } from 'next';
import { NotificationsPage } from '@/features/notifications/notifications-page';

export const metadata: Metadata = { title: 'Уведомления' };

export default function Page() {
  return <NotificationsPage />;
}

import { redirect } from 'next/navigation';
import { AppShell } from '@/components/layout/app-shell';
import { MeProvider } from '@/lib/me-context';
import { getMe } from '@/lib/server-api';

export default async function AppLayout({ children }: { children: React.ReactNode }) {
  const me = await getMe();
  if (!me) redirect('/login');
  return (
    <MeProvider me={me}>
      <AppShell>{children}</AppShell>
    </MeProvider>
  );
}

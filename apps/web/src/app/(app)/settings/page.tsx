import { hasPermission } from '@fluggi/contracts';
import { redirect } from 'next/navigation';
import { Forbidden } from '@/components/shared/states';
import { SETTINGS_TABS } from '@/features/settings/tabs';
import { getMe } from '@/lib/server-api';

export default async function SettingsIndex() {
  const me = (await getMe())!;
  const first = SETTINGS_TABS.find((tab) => hasPermission(me.permissions, tab.permission));
  if (!first) return <Forbidden />;
  redirect(first.href);
}

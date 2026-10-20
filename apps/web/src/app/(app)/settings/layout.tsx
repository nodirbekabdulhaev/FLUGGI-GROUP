import { getTranslations } from 'next-intl/server';
import { PageHeader } from '@/components/shared/page-header';
import { SettingsTabs } from '@/features/settings/settings-tabs';

export default async function SettingsLayout({ children }: { children: React.ReactNode }) {
  const t = await getTranslations('settings');
  return (
    <>
      <PageHeader title={t('title')} description={t('subtitle')} />
      <SettingsTabs />
      {children}
    </>
  );
}

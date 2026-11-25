import Link from 'next/link';
import { getTranslations } from 'next-intl/server';
import { Button } from '@/components/ui/button';

export default async function NotFound() {
  const t = await getTranslations('common');
  return (
    <div className="flex min-h-[60dvh] flex-col items-center justify-center gap-4 p-6 text-center">
      <p className="text-5xl font-semibold text-muted-foreground/40">404</p>
      <h1 className="text-lg font-semibold">{t('notFoundTitle')}</h1>
      <Button asChild variant="outline">
        <Link href="/dashboard">{t('toDashboard')}</Link>
      </Button>
    </div>
  );
}

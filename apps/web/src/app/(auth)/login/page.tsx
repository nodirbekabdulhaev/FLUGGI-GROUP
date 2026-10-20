import type { Metadata } from 'next';
import { getTranslations } from 'next-intl/server';
import { redirect } from 'next/navigation';
import { Suspense } from 'react';
import { LoginForm } from '@/features/auth/login-form';
import { getMe } from '@/lib/server-api';

export const metadata: Metadata = { title: 'Вход' };

export default async function LoginPage() {
  if (await getMe()) redirect('/dashboard');
  const t = await getTranslations();
  return (
    <main className="flex min-h-dvh items-center justify-center px-4 py-10">
      <div className="w-full max-w-sm">
        <div className="mb-8 flex items-center gap-2.5">
          <div className="flex size-9 items-center justify-center rounded-lg bg-primary text-sm font-bold text-primary-foreground">
            F
          </div>
          <div>
            <p className="font-semibold leading-tight">{t('app.name')}</p>
            <p className="text-xs text-muted-foreground">{t('app.tagline')}</p>
          </div>
        </div>
        <h1 className="text-xl font-semibold">{t('auth.title')}</h1>
        <p className="mt-1 text-sm text-muted-foreground">{t('auth.subtitle')}</p>
        <Suspense>
          <LoginForm />
        </Suspense>
        <p className="mt-6 text-xs text-muted-foreground">{t('auth.forgot')}</p>
      </div>
    </main>
  );
}

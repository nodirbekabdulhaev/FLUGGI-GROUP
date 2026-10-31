'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { loginSchema, type LoginInput } from '@fluggi/contracts';
import { useTranslations } from 'next-intl';
import { useRouter, useSearchParams } from 'next/navigation';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Field } from '@/components/ui/label';
import { api, errorMessage } from '@/lib/api-client';

/** Только относительные пути внутри приложения — защита от open redirect. */
function safeNext(value: string | null) {
  return value && value.startsWith('/') && !value.startsWith('//') ? value : '/dashboard';
}

export function LoginForm() {
  const t = useTranslations('auth');
  const router = useRouter();
  const params = useSearchParams();
  const [error, setError] = useState<string | null>(null);
  const form = useForm<LoginInput>({
    resolver: zodResolver(loginSchema),
    defaultValues: { email: '', password: '' },
  });

  const onSubmit = form.handleSubmit(async (values) => {
    setError(null);
    try {
      await api('/auth/login', { method: 'POST', body: values });
      router.replace(safeNext(params.get('next')));
      router.refresh();
    } catch (err) {
      setError(errorMessage(err));
    }
  });

  const { errors, isSubmitting } = form.formState;
  return (
    <form onSubmit={onSubmit} className="mt-6 grid gap-4" noValidate>
      {error ? (
        <div
          className="rounded-md border border-danger/20 bg-danger-soft px-3 py-2.5 text-sm text-danger"
          role="alert"
        >
          {error}
        </div>
      ) : null}
      <Field label={t('email')} htmlFor="email" error={errors.email?.message}>
        <Input
          id="email"
          type="email"
          autoComplete="username"
          autoFocus
          aria-invalid={!!errors.email}
          {...form.register('email')}
        />
      </Field>
      <Field label={t('password')} htmlFor="password" error={errors.password?.message}>
        <Input
          id="password"
          type="password"
          autoComplete="current-password"
          aria-invalid={!!errors.password}
          {...form.register('password')}
        />
      </Field>
      <Button
        type="submit"
        size="lg"
        loading={isSubmitting}
        loadingText={t('submitting')}
        className="mt-2"
      >
        {t('submit')}
      </Button>
    </form>
  );
}

'use client';

import { ServerCrash } from 'lucide-react';
import { Button } from '@/components/ui/button';

/**
 * Ошибка при отрисовке страницы. Тексты без next-intl: провайдер переводов
 * может быть недоступен, если упал корневой layout.
 */
export default function AppError({
  error,
  reset,
}: {
  error: Error & { digest?: string };
  reset: () => void;
}) {
  const apiDown = error.message.startsWith('API_UNAVAILABLE');
  return (
    <main className="flex min-h-dvh flex-col items-center justify-center gap-4 px-6 text-center">
      <div className="flex size-12 items-center justify-center rounded-full bg-danger-soft">
        <ServerCrash className="size-6 text-danger" />
      </div>
      <h1 className="text-lg font-semibold">
        {apiDown ? 'Сервер API недоступен' : 'Не удалось открыть страницу'}
      </h1>
      <p className="max-w-md text-sm text-muted-foreground">
        {apiDown
          ? 'Интерфейс работает, но не может связаться с API. Проверьте, что API запущен (pnpm --filter @fluggi/api dev) и что база данных работает (Docker Desktop → Engine running).'
          : 'Произошла ошибка. Попробуйте обновить страницу. Если ошибка повторяется — сообщите администратору.'}
      </p>
      {error.digest ? (
        <p className="text-xs text-muted-foreground/70">Код: {error.digest}</p>
      ) : null}
      <Button onClick={reset}>Повторить</Button>
    </main>
  );
}

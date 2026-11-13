'use client';

import type { ExportEntity } from '@fluggi/contracts';
import { Download } from 'lucide-react';
import { useTranslations } from 'next-intl';
import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useCan } from '@/lib/me-context';
import { exportUrl } from './api';

/**
 * Экспорт списка в Excel или CSV (ТЗ §44). Файл формирует сервер — строго в пределах прав,
 * выгрузка записывается в журнал аудита. Без права export.run кнопка не показывается.
 */
export function ExportMenu({ entity, period = 'all' }: { entity: ExportEntity; period?: string }) {
  const t = useTranslations('export');
  const can = useCan();
  if (!can('export.run')) return null;
  const go = (format: 'xlsx' | 'csv') => {
    window.location.href = exportUrl(entity, format, period);
  };
  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button variant="outline" size="sm">
          <Download className="size-4" /> {t('button')}
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end">
        <DropdownMenuLabel>{t('label')}</DropdownMenuLabel>
        <DropdownMenuItem onSelect={() => go('xlsx')}>{t('xlsx')}</DropdownMenuItem>
        <DropdownMenuItem onSelect={() => go('csv')}>{t('csv')}</DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  );
}

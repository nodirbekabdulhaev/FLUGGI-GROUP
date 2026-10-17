'use client';

import { Check, Copy } from 'lucide-react';
import { useTranslations } from 'next-intl';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter } from '@/components/ui/dialog';

export function TempPasswordDialog({
  value,
  onClose,
}: {
  value: { name: string; password: string } | null;
  onClose: () => void;
}) {
  const t = useTranslations();
  const [copied, setCopied] = useState(false);
  return (
    <Dialog open={value !== null} onOpenChange={(o) => !o && (setCopied(false), onClose())}>
      {value ? (
        <DialogContent
          title={t('employees.tempPasswordTitle')}
          description={t('employees.tempPasswordText', { name: value.name })}
        >
          <div className="flex items-center gap-2 rounded-md border bg-muted/50 p-3">
            <code className="flex-1 select-all break-all font-mono text-base">
              {value.password}
            </code>
            <Button
              variant="outline"
              size="sm"
              onClick={async () => {
                await navigator.clipboard.writeText(value.password);
                setCopied(true);
              }}
            >
              {copied ? <Check /> : <Copy />}
              {copied ? t('common.copied') : t('common.copy')}
            </Button>
          </div>
          <DialogFooter>
            <Button onClick={() => (setCopied(false), onClose())}>{t('common.close')}</Button>
          </DialogFooter>
        </DialogContent>
      ) : null}
    </Dialog>
  );
}

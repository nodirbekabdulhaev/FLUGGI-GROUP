import { Construction } from 'lucide-react';
import { getTranslations } from 'next-intl/server';
import { Card } from '@/components/ui/card';

/**
 * Явная пометка нереализованного раздела (ТЗ §85: не выдавать заглушку за работающую функцию).
 */
export async function PlannedSection({ sectionKey, phase }: { sectionKey: string; phase: number }) {
  const t = await getTranslations();
  const section = t(`nav.${sectionKey}`);
  return (
    <>
      <h1 className="mb-6 text-xl font-semibold tracking-tight sm:text-2xl">{section}</h1>
      <Card className="flex flex-col items-center gap-3 px-6 py-14 text-center">
        <div className="flex size-11 items-center justify-center rounded-full bg-warning-soft">
          <Construction className="size-5 text-warning" />
        </div>
        <p className="font-medium">{t('planned.title')}</p>
        <p className="max-w-md text-sm text-muted-foreground">
          {t('planned.text', { section, phase, phaseName: t(`planned.phases.${phase}`) })}
        </p>
      </Card>
    </>
  );
}

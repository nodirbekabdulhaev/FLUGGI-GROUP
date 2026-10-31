import { notFound, redirect } from 'next/navigation';
import { getTranslations } from 'next-intl/server';
import { PlannedSection } from '@/components/shared/planned-section';
import { Forbidden } from '@/components/shared/states';
import { canSee, findNavItem, NAVIGATION } from '@/lib/navigation';
import { getMe } from '@/lib/server-api';

/**
 * Разделы меню, которые ещё не реализованы. Реализованные разделы имеют
 * собственные страницы и сюда не попадают.
 */
export default async function SectionPage({ params }: { params: Promise<{ slug: string[] }> }) {
  const { slug } = await params;
  const pathname = `/${slug.join('/')}`;
  const me = (await getMe())!;

  // Родительский пункт с подразделами (например /sales) — ведём на первый доступный.
  const parent = NAVIGATION.find((s) => s.href === pathname && s.children);
  if (parent) {
    const child = parent.children!.find((c) => canSee(c, me.permissions));
    if (!child) return <Forbidden />;
    redirect(child.href);
  }

  const found = findNavItem(pathname);
  if (!found) notFound();

  if (!canSee(found.item, me.permissions)) return <Forbidden />;
  if (!found.item.plannedPhase) notFound();
  return <PlannedSection sectionKey={found.item.key} phase={found.item.plannedPhase} />;
}

export async function generateMetadata({ params }: { params: Promise<{ slug: string[] }> }) {
  const { slug } = await params;
  const found = findNavItem(`/${slug.join('/')}`);
  const t = await getTranslations('nav');
  return { title: found ? t(found.item.key) : undefined };
}

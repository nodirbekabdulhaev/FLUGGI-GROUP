'use client';

import { Menu } from 'lucide-react';
import { useTranslations } from 'next-intl';
import { Suspense, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Sheet, SheetContent, SheetTrigger } from '@/components/ui/sheet';
import { useCan } from '@/lib/me-context';
import { GlobalSearch } from '@/features/analytics/global-search';
import { WidgetBoundary } from '@/components/shared/widget-boundary';
import { ChatWidget } from '@/features/chat/chat-widget';
import { TodoDock } from '@/features/todos/todo-dock';
import { PeriodSelect } from './period-select';
import { Brand, SidebarNav } from './sidebar-nav';
import { UserMenu } from './user-menu';
import { NotificationBell } from '@/features/notifications/notifications-page';

/** Каркас приложения: sidebar на десктопе, drawer на мобильном (ТЗ §73). */
export function AppShell({ children }: { children: React.ReactNode }) {
  const t = useTranslations('nav');
  const can = useCan();
  const [open, setOpen] = useState(false);

  return (
    <div className="min-h-dvh lg:pl-64">
      <aside className="fixed inset-y-0 left-0 hidden w-64 flex-col border-r bg-surface lg:flex">
        <div className="flex h-16 items-center">
          <Brand />
        </div>
        <div className="flex-1 overflow-y-auto px-3 pb-6">
          <SidebarNav />
        </div>
      </aside>

      <header className="sticky top-0 z-40 flex h-16 items-center gap-3 border-b bg-surface/90 px-4 backdrop-blur sm:px-6">
        <Sheet open={open} onOpenChange={setOpen}>
          <SheetTrigger asChild>
            <Button variant="ghost" size="icon" className="lg:hidden" aria-label={t('openMenu')}>
              <Menu className="size-5" />
            </Button>
          </SheetTrigger>
          <SheetContent title={t('mainMenu')}>
            <div className="flex h-16 items-center border-b">
              <Brand />
            </div>
            <div className="flex-1 overflow-y-auto p-3">
              <SidebarNav onNavigate={() => setOpen(false)} />
            </div>
          </SheetContent>
        </Sheet>

        {can('dashboard.ceo') ? (
          <div className="min-w-0 flex-1 overflow-x-auto">
            <Suspense>
              <PeriodSelect />
            </Suspense>
          </div>
        ) : (
          <div className="flex-1" />
        )}
        <GlobalSearch />
        <NotificationBell />
        <UserMenu />
      </header>

      <main className="mx-auto w-full max-w-7xl px-4 pb-28 pt-6 sm:px-6 lg:pt-8">{children}</main>
      <WidgetBoundary name="todo-dock" label="Список дел" placement="bar">
        <TodoDock />
      </WidgetBoundary>
      <WidgetBoundary name="chat" label="Чат" placement="corner">
        <Suspense>
          <ChatWidget />
        </Suspense>
      </WidgetBoundary>
    </div>
  );
}

'use client';

import { LogOut, UserRound } from 'lucide-react';
import { useTranslations } from 'next-intl';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { toast } from 'sonner';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { api, errorMessage } from '@/lib/api-client';
import { useMe } from '@/lib/me-context';
import { initials } from '@/lib/utils';

export function UserMenu() {
  const me = useMe();
  const t = useTranslations();
  const router = useRouter();

  async function logout() {
    try {
      await api('/auth/logout', { method: 'POST' });
      toast.success(t('auth.loggedOut'));
      router.replace('/login');
      router.refresh();
    } catch (err) {
      toast.error(errorMessage(err));
    }
  }

  return (
    <DropdownMenu>
      <DropdownMenuTrigger
        className="flex items-center gap-2.5 rounded-md p-1 hover:bg-muted"
        aria-label={me.fullName}
      >
        <span className="flex size-8 items-center justify-center rounded-full bg-accent-soft text-xs font-semibold text-accent">
          {initials(me.fullName)}
        </span>
        <span className="hidden text-left md:block">
          <span className="block text-sm font-medium leading-tight">{me.fullName}</span>
          <span className="block text-xs text-muted-foreground">{t(`roles.${me.role.code}`)}</span>
        </span>
      </DropdownMenuTrigger>
      <DropdownMenuContent>
        <DropdownMenuLabel>{me.email}</DropdownMenuLabel>
        <DropdownMenuSeparator />
        <DropdownMenuItem asChild>
          <Link href="/profile">
            <UserRound /> {t('nav.profile')}
          </Link>
        </DropdownMenuItem>
        <DropdownMenuItem onSelect={logout} destructive>
          <LogOut /> {t('nav.logout')}
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  );
}

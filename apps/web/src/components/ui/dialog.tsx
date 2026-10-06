'use client';

import { X } from 'lucide-react';
import { Dialog as D } from 'radix-ui';
import * as React from 'react';
import { cn } from '@/lib/utils';

export const Dialog = D.Root;
export const DialogTrigger = D.Trigger;
export const DialogClose = D.Close;

export function DialogContent({
  className,
  children,
  title,
  description,
  ...props
}: React.ComponentProps<typeof D.Content> & { title: string; description?: string }) {
  return (
    <D.Portal>
      <D.Overlay className="fixed inset-0 z-50 bg-black/40" />
      <D.Content
        className={cn(
          'fixed z-50 flex max-h-[92dvh] w-full flex-col gap-4 overflow-y-auto bg-surface p-5 shadow-xl',
          'inset-x-0 bottom-0 rounded-t-xl sm:inset-auto sm:left-1/2 sm:top-1/2 sm:max-w-lg sm:-translate-x-1/2 sm:-translate-y-1/2 sm:rounded-lg',
          className,
        )}
        {...props}
      >
        <div className="flex items-start justify-between gap-4">
          <div className="grid gap-1">
            <D.Title className="text-base font-semibold">{title}</D.Title>
            {description ? (
              <D.Description className="text-sm text-muted-foreground">{description}</D.Description>
            ) : (
              <D.Description className="sr-only">{title}</D.Description>
            )}
          </div>
          <D.Close
            className="rounded-md p-1 text-muted-foreground hover:bg-muted"
            aria-label="Закрыть"
          >
            <X className="size-4" />
          </D.Close>
        </div>
        {children}
      </D.Content>
    </D.Portal>
  );
}

export function DialogFooter({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) {
  return (
    <div
      className={cn('flex flex-col-reverse gap-2 pt-2 sm:flex-row sm:justify-end', className)}
      {...props}
    />
  );
}

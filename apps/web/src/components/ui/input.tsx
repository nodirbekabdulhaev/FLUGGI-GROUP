import * as React from 'react';
import { cn } from '@/lib/utils';

export const inputClass =
  'flex h-10 w-full rounded-md border border-input bg-surface px-3 text-sm placeholder:text-muted-foreground/70 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent/30 focus-visible:border-accent disabled:opacity-50 aria-[invalid=true]:border-danger';

export const Input = React.forwardRef<
  HTMLInputElement,
  React.InputHTMLAttributes<HTMLInputElement>
>(({ className, ...props }, ref) => (
  <input ref={ref} className={cn(inputClass, className)} {...props} />
));
Input.displayName = 'Input';

export const NativeSelect = React.forwardRef<
  HTMLSelectElement,
  React.SelectHTMLAttributes<HTMLSelectElement>
>(({ className, ...props }, ref) => (
  <select ref={ref} className={cn(inputClass, 'pr-8', className)} {...props} />
));
NativeSelect.displayName = 'NativeSelect';

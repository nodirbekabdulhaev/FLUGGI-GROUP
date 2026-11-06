'use client';

import { AlertTriangle, RotateCcw } from 'lucide-react';
import { Component, type ReactNode } from 'react';
import { api } from '@/lib/api-client';

interface Props {
  name: string;
  /** Подпись кнопки перезапуска, например «Чат» */
  label: string;
  /** Где показать кнопку: чат — справа внизу, «Список дел» — полосой внизу */
  placement: 'corner' | 'bar';
  children: ReactNode;
}

/**
 * Защита для виджетов каркаса (чат, «Список дел»): ошибка в виджете не роняет страницу.
 * Вместо виджета — кнопка «перезапустить» с текстом ошибки; ошибка уходит в лог API
 * (POST /client-errors), чтобы причину было видно и без воспроизведения.
 */
export class WidgetBoundary extends Component<Props, { error: Error | null; resets: number }> {
  override state = { error: null as Error | null, resets: 0 };

  static getDerivedStateFromError(error: Error) {
    return { error };
  }

  override componentDidCatch(error: Error) {
    console.error(`[${this.props.name}]`, error);
    void api('/client-errors', {
      method: 'POST',
      body: {
        widget: this.props.name,
        message: String(error?.message ?? error).slice(0, 1000),
        stack: error?.stack?.slice(0, 4000),
        path: typeof window !== 'undefined' ? window.location.pathname : undefined,
        userAgent: typeof navigator !== 'undefined' ? navigator.userAgent.slice(0, 300) : undefined,
      },
    }).catch(() => undefined);
  }

  private reset = () => this.setState((s) => ({ error: null, resets: s.resets + 1 }));

  override render() {
    const { error, resets } = this.state;
    if (!error) return <div key={resets}>{this.props.children}</div>;
    const text = `${this.props.label}: ошибка — нажмите, чтобы перезапустить`;
    return this.props.placement === 'corner' ? (
      <button
        type="button"
        onClick={this.reset}
        aria-label={text}
        title={`${text}\n${error.message}`}
        className="fixed bottom-14 right-4 z-40 flex size-12 items-center justify-center rounded-full bg-danger text-white shadow-lg"
      >
        <RotateCcw className="size-5" />
      </button>
    ) : (
      <button
        type="button"
        onClick={this.reset}
        title={error.message}
        className="fixed inset-x-0 bottom-0 z-30 flex h-12 items-center gap-2 border-t bg-surface px-5 text-sm text-danger lg:left-64"
      >
        <AlertTriangle className="size-4" /> {text}
        <span className="truncate text-xs text-muted-foreground">({error.message})</span>
      </button>
    );
  }
}

'use client';

import { Component, type ReactNode } from 'react';

/**
 * Защита для виджетов каркаса (чат, «Список дел»): ошибка в виджете
 * не роняет всю страницу — виджет просто скрывается, причина пишется в консоль.
 */
export class WidgetBoundary extends Component<
  { name: string; children: ReactNode },
  { failed: boolean }
> {
  override state = { failed: false };

  static getDerivedStateFromError() {
    return { failed: true };
  }

  override componentDidCatch(error: Error) {
    console.error(`[${this.props.name}]`, error);
  }

  override render() {
    return this.state.failed ? null : this.props.children;
  }
}

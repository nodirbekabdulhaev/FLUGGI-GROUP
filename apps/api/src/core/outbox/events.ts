/**
 * Каталог доменных событий (ТЗ §54). Каждое событие пишется в outbox_events
 * в одной транзакции с изменением данных и доставляется подписчикам worker'ом.
 * Новые события добавляются по мере реализации модулей.
 */
export interface DomainEvents {
  'user.created': { userId: string; roleCode: string };
  'user.blocked': { userId: string };
  'user.role_changed': { userId: string; from: string; to: string };
}

export type DomainEventType = keyof DomainEvents;

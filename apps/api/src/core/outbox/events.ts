/**
 * Каталог доменных событий (ТЗ §54). Каждое событие пишется в outbox_events
 * в одной транзакции с изменением данных и доставляется подписчикам.
 */
export interface DomainEvents {
  'user.created': { userId: string; roleCode: string };
  'user.blocked': { userId: string };
  'user.role_changed': { userId: string; from: string; to: string };

  'lead.created': {
    leadId: string;
    ownerId: string;
    teamId: string | null;
    createdById: string;
    budgetUzs: string | null;
  };
  'lead.assigned': { leadId: string; ownerId: string; previousOwnerId: string };
  'lead.converted': { leadId: string; dealId: string; clientId: string };
  'lead.closed': { leadId: string; status: string };
  'deal.created': { dealId: string; ownerId: string; teamId: string | null; amountUzs: string };
  'deal.stage_changed': { dealId: string; from: string; to: string };
  'deal.lost': {
    dealId: string;
    ownerId: string;
    teamId: string | null;
    amountUzs: string;
    reason: string | null;
  };
  'meeting.created': {
    meetingId: string;
    managerId: string;
    ropId: string | null;
    startsAt: string;
  };
  'meeting.completed': { meetingId: string; managerId: string; ropId: string | null };
}

export type DomainEventType = keyof DomainEvents;

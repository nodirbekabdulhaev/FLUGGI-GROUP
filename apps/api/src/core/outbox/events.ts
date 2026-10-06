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

  'proposal.approval_requested': { proposalId: string; dealId: string; teamId: string | null };
  'proposal.sent': { proposalId: string; dealId: string };
  'proposal.accepted': { proposalId: string; dealId: string };
  'contract.signed': {
    contractId: string;
    dealId: string;
    managerId: string;
    teamId: string | null;
  };
  'payment.created': { paymentId: string; dealId: string };
  'payment.paid': {
    paymentId: string;
    dealId: string;
    amountUzs: string;
    managerId: string;
    teamId: string | null;
    projectId: string;
    projectCreated: boolean;
  };
  'payment.refunded': { paymentId: string; dealId: string; amountUzs: string };
  'deal.won': { dealId: string; ownerId: string; teamId: string | null; amountUzs: string };
  'project.created': { projectId: string; dealId: string; ropId: string; managerId: string };
}

export type DomainEventType = keyof DomainEvents;

<?php

namespace App\Services\Notifications;

use App\Models\Deal;
use App\Models\Lead;
use App\Models\Meeting;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Task;
use App\Models\User;
use App\Support\Format;
use App\Support\Outbox;
use App\Support\Settings;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Подписчики событий → уведомления (ТЗ §14, §54): in-app и Telegram через Notifier
 * с учётом личных настроек. Порог «крупного» — из настроек автоматизации.
 */
final class NotificationEvents
{
    public static function register(): void
    {
        Outbox::on('lead.created', [self::class, 'leadCreated']);
        Outbox::on('lead.assigned', [self::class, 'leadAssigned']);
        Outbox::on('deal.created', [self::class, 'dealCreated']);
        Outbox::on('deal.lost', [self::class, 'dealLost']);
        Outbox::on('proposal.approval_requested', [self::class, 'proposalApproval']);
        Outbox::on('contract.signed', [self::class, 'contractSigned']);
        Outbox::on('payment.paid', [self::class, 'paymentPaid']);
        Outbox::on('project.created', [self::class, 'projectCreated']);
        Outbox::on('project.member_added', [self::class, 'projectMemberAdded']);
        Outbox::on('project.status_changed', [self::class, 'projectStatusChanged']);
        Outbox::on('task.assigned', [self::class, 'taskAssigned']);
        Outbox::on('task.status_changed', [self::class, 'taskStatusChanged']);
        Outbox::on('task.deadline_changed', [self::class, 'taskDeadlineChanged']);
        Outbox::on('task.overdue', [self::class, 'taskOverdue']);
        Outbox::on('meeting.created', [self::class, 'meetingCreated']);
    }

    /** 9 000 000 UZS — округление до сума. */
    public static function uzs(mixed $amount): string
    {
        return Format::number(BigDecimal::of((string) ($amount ?? 0))->toScale(0, RoundingMode::HalfUp), 0).' UZS';
    }

    private static function large(): BigDecimal
    {
        return BigDecimal::of((string) Settings::automation()['largeAmountUzs']);
    }

    private static function isLarge(mixed $amount): bool
    {
        return $amount !== null && $amount !== '' && BigDecimal::of((string) $amount)->isGreaterThanOrEqualTo(self::large());
    }

    public static function leadCreated(array $e, array $meta): void
    {
        $lead = Lead::withTrashed()->find($e['leadId'] ?? null);
        if (! $lead) {
            return;
        }
        $link = '/sales/leads/'.$lead->id;
        Notifier::notify([$e['ownerId'] ?? null], 'lead.created', fn () => [t('notifications.events.leadCreated'), $lead->title], $link, $meta['actorId']);
        // CEO не ответственный за лиды, но узнаёт о каждом новом лиде и о том, кому он назначен
        $owner = User::withTrashed()->whereKey($e['ownerId'] ?? null)->value('full_name') ?? '';
        Notifier::notify(
            array_values(array_filter(Notifier::ceoIds(), fn ($id) => $id !== ($e['ownerId'] ?? null))),
            'lead.created',
            fn () => [t('notifications.events.leadCreated'), $lead->title.' → '.$owner],
            $link,
            $meta['actorId'],
        );
        if (! empty($e['budgetUzs']) && self::isLarge($e['budgetUzs'])) {
            Notifier::notify(
                [Notifier::teamHead($e['teamId'] ?? null), ...Notifier::ceoIds()],
                'lead.large',
                fn () => [t('notifications.events.leadLarge'), $lead->title.' — '.self::uzs($e['budgetUzs'])],
                $link,
                $meta['actorId'],
            );
        }
    }

    public static function leadAssigned(array $e, array $meta): void
    {
        $lead = Lead::withTrashed()->find($e['leadId'] ?? null);
        if (! $lead) {
            return;
        }
        Notifier::notify([$e['ownerId'] ?? null], 'lead.assigned', fn () => [t('notifications.events.leadAssigned'), $lead->title], '/sales/leads/'.$lead->id, $meta['actorId']);
    }

    public static function dealCreated(array $e, array $meta): void
    {
        $deal = Deal::withTrashed()->find($e['dealId'] ?? null);
        if (! $deal) {
            return;
        }
        $large = self::isLarge($e['amountUzs'] ?? 0);
        Notifier::notify(
            [Notifier::teamHead($e['teamId'] ?? null), ...($large ? Notifier::ceoIds() : [])],
            $large ? 'deal.large' : 'deal.created',
            fn () => [
                t($large ? 'notifications.events.dealLarge' : 'notifications.events.dealCreated'),
                Format::code('D', $deal->number).' '.$deal->title.' — '.self::uzs($e['amountUzs'] ?? 0),
            ],
            '/sales/deals/'.$deal->id,
            $meta['actorId'],
        );
    }

    public static function dealLost(array $e, array $meta): void
    {
        if (! self::isLarge($e['amountUzs'] ?? 0)) {
            return;
        }
        $deal = Deal::withTrashed()->with('client')->find($e['dealId'] ?? null);
        if (! $deal) {
            return;
        }
        $reason = ! empty($e['reason']) ? ' — '.$e['reason'] : '';
        Notifier::notify(
            [Notifier::teamHead($e['teamId'] ?? null), ...Notifier::ceoIds()],
            'deal.large_lost',
            fn () => [t('notifications.events.dealLargeLost'), ($deal->client?->name ?? '').': '.self::uzs($e['amountUzs']).$reason],
            '/sales/deals/'.$deal->id,
            $meta['actorId'],
        );
    }

    public static function proposalApproval(array $e, array $meta): void
    {
        $p = Proposal::find($e['proposalId'] ?? null);
        if (! $p) {
            return;
        }
        Notifier::notify(
            [Notifier::teamHead($e['teamId'] ?? null)],
            'proposal.approval',
            fn () => [t('notifications.events.proposalApproval'), $p->title.' — '.self::uzs($p->total_uzs)],
            '/sales/deals/'.$p->deal_id,
            $meta['actorId'],
        );
    }

    public static function contractSigned(array $e, array $meta): void
    {
        $deal = Deal::withTrashed()->with('client')->find($e['dealId'] ?? null);
        if (! $deal) {
            return;
        }
        Notifier::notify(
            [$e['managerId'] ?? null, Notifier::teamHead($e['teamId'] ?? null)],
            'contract.signed',
            fn () => [t('notifications.events.contractSigned'), ($deal->client?->name ?? '').' · '.$deal->title],
            '/sales/deals/'.$deal->id,
            $meta['actorId'],
        );
    }

    /** Оплата: менеджеру, РОП и CEO (ТЗ §14). */
    public static function paymentPaid(array $e, array $meta): void
    {
        $deal = Deal::withTrashed()->with('client')->find($e['dealId'] ?? null);
        if (! $deal) {
            return;
        }
        Notifier::notify(
            [$e['managerId'] ?? null, Notifier::teamHead($e['teamId'] ?? null), ...Notifier::ceoIds()],
            'payment.paid',
            fn () => [t('notifications.events.paymentPaid'), ($deal->client?->name ?? '').': '.self::uzs($e['amountUzs'] ?? 0)],
            '/sales/deals/'.$deal->id,
            $meta['actorId'],
        );
    }

    public static function projectCreated(array $e, array $meta): void
    {
        $p = Project::withTrashed()->find($e['projectId'] ?? null);
        if (! $p) {
            return;
        }
        Notifier::notify(
            [$e['ropId'] ?? null, $e['managerId'] ?? null],
            'project.created',
            fn () => [t('notifications.events.projectCreated'), t('notifications.events.projectCreatedBody', ['project' => Format::code('P', $p->number).' '.$p->name])],
            '/projects/'.$p->id,
            $meta['actorId'],
        );
    }

    public static function projectMemberAdded(array $e, array $meta): void
    {
        $p = Project::withTrashed()->find($e['projectId'] ?? null);
        if (! $p) {
            return;
        }
        Notifier::notify(
            [$e['userId'] ?? null],
            'project.member_added',
            fn () => [t('notifications.events.projectMemberAdded'), Format::code('P', $p->number).' '.$p->name],
            '/projects/'.$p->id,
            $meta['actorId'],
        );
    }

    public static function projectStatusChanged(array $e, array $meta): void
    {
        $to = $e['to'] ?? null;
        if ($to !== 'COMPLETED' && $to !== 'CANCELLED') {
            return;
        }
        $p = Project::withTrashed()->find($e['projectId'] ?? null);
        if (! $p) {
            return;
        }
        $done = $to === 'COMPLETED';
        Notifier::notify(
            [$p->manager_id, $p->rop_id, ...Notifier::ceoIds()],
            $done ? 'project.completed' : 'project.cancelled',
            fn () => [t($done ? 'notifications.events.projectCompleted' : 'notifications.events.projectCancelled'), Format::code('P', $p->number).' '.$p->name],
            '/projects/'.$p->id,
            $meta['actorId'],
        );
    }

    private static function task(?string $id): ?Task
    {
        return $id ? Task::withTrashed()->with(['project' => fn ($q) => $q->withTrashed()])->find($id) : null;
    }

    private static function taskLink(Task $task): string
    {
        return '/projects/'.$task->project_id.'?task='.$task->id;
    }

    public static function taskAssigned(array $e, array $meta): void
    {
        $task = self::task($e['taskId'] ?? null);
        if (! $task || $task->assignee_id !== ($e['assigneeId'] ?? null)) {
            return;
        }
        Notifier::notify(
            [$e['assigneeId']],
            'task.assigned',
            fn () => [t('notifications.events.taskAssigned'), $task->title.' · '.($task->project?->name ?? '')],
            self::taskLink($task),
            $meta['actorId'],
        );
    }

    /** На проверку — автору задачи и РОП проекта; принято/возвращено — исполнителю. */
    public static function taskStatusChanged(array $e, array $meta): void
    {
        $task = self::task($e['taskId'] ?? null);
        if (! $task) {
            return;
        }
        if (($e['to'] ?? null) === 'REVIEW') {
            Notifier::notify([$task->creator_id, $task->project?->rop_id], 'task.review', fn () => [t('notifications.events.taskReview'), $task->title], self::taskLink($task), $meta['actorId']);
        } elseif (($e['from'] ?? null) === 'REVIEW') {
            $accepted = ($e['to'] ?? null) === 'DONE';
            Notifier::notify(
                [$task->assignee_id],
                $accepted ? 'task.accepted' : 'task.returned',
                fn () => [t($accepted ? 'notifications.events.taskAccepted' : 'notifications.events.taskReturned'), $task->title],
                self::taskLink($task),
                $meta['actorId'],
            );
        }
    }

    /** Исполнителю: изменение дедлайна (ТЗ §14). */
    public static function taskDeadlineChanged(array $e, array $meta): void
    {
        $task = self::task($e['taskId'] ?? null);
        if (! $task) {
            return;
        }
        Notifier::notify(
            [$task->assignee_id],
            'task.deadline_changed',
            fn () => [t('notifications.events.taskDeadlineChanged'), $task->title.': '.($task->deadline ? dt($task->deadline) : t('notifications.events.noDeadline'))],
            self::taskLink($task),
            $meta['actorId'],
        );
    }

    public static function taskOverdue(array $e, array $meta): void
    {
        $task = self::task($e['taskId'] ?? null);
        if (! $task) {
            return;
        }
        $days = (int) ($e['overdueDays'] ?? 1);
        Notifier::notify(
            [$task->assignee_id, $task->project?->rop_id],
            'task.overdue',
            fn () => [t('notifications.events.taskOverdue', ['days' => $days]), $task->title.' · '.($task->project?->name ?? '')],
            self::taskLink($task),
            null,
        );
    }

    /** Шаг 6 сценария приёмки: РОП получает уведомление о новой встрече. */
    public static function meetingCreated(array $e, array $meta): void
    {
        $m = Meeting::with(['lead' => fn ($q) => $q->withTrashed(), 'deal' => fn ($q) => $q->withTrashed(), 'manager' => fn ($q) => $q->withTrashed()])
            ->find($e['meetingId'] ?? null);
        if (! $m) {
            return;
        }
        $subject = $m->lead?->title ?? $m->deal?->title ?? '';
        Notifier::notify(
            [$e['ropId'] ?? null, $e['managerId'] ?? null],
            'meeting.created',
            fn () => [t('notifications.events.meetingCreated'), dt($m->starts_at).' · '.$subject.' · '.($m->manager?->full_name ?? '')],
            $m->lead_id ? '/sales/leads/'.$m->lead_id : '/sales/deals/'.$m->deal_id,
            $meta['actorId'],
        );
    }
}

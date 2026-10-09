<?php

namespace App\Services\Notifications;

/**
 * Каталог уведомлений (ТЗ §14): типы и роли, которым они приходят. Используется в личных
 * настройках каналов. Подписи — lang/{ru,uz}/notifications.json → types.*.
 */
final class NotificationCatalog
{
    public const CHANNELS = ['IN_APP', 'TELEGRAM'];

    private const ALL_ROLES = ['CEO', 'ROP', 'MANAGER', 'EXECUTOR', 'HR_ADMIN'];

    /** Тип → роли (CEO видит все типы). */
    public const EVENTS = [
        'lead.created' => ['MANAGER', 'ROP', 'CEO'],
        'lead.assigned' => ['MANAGER', 'ROP'],
        'lead.large' => ['ROP', 'CEO'],
        'deal.created' => ['ROP', 'CEO'],
        'deal.large' => ['ROP', 'CEO'],
        'deal.large_lost' => ['ROP', 'CEO'],
        'meeting.created' => ['MANAGER', 'ROP'],
        'meeting.reminder' => ['MANAGER', 'ROP'],
        'client.no_contact' => ['MANAGER', 'ROP'],
        'proposal.approval' => ['ROP', 'CEO'],
        'proposal.reminder' => ['MANAGER'],
        'contract.reminder' => ['MANAGER', 'ROP'],
        'contract.signed' => ['MANAGER', 'ROP'],
        'payment.paid' => ['MANAGER', 'ROP', 'CEO'],
        'payment.overdue' => ['MANAGER', 'ROP'],
        'plan.achieved' => ['CEO', 'ROP'],
        'project.created' => ['MANAGER', 'ROP'],
        'project.member_added' => ['EXECUTOR', 'MANAGER', 'ROP'],
        'project.ending' => ['MANAGER', 'ROP'],
        'project.overdue' => ['ROP', 'CEO'],
        'project.completed' => ['MANAGER', 'ROP', 'CEO'],
        'task.assigned' => ['EXECUTOR', 'MANAGER', 'ROP'],
        'task.deadline_changed' => ['EXECUTOR', 'MANAGER'],
        'task.review' => ['ROP', 'MANAGER', 'CEO'],
        'task.returned' => ['EXECUTOR'],
        'task.overdue' => ['EXECUTOR', 'MANAGER', 'ROP'],
        'client.risk' => ['MANAGER', 'ROP', 'CEO'],
        'followup.due' => ['MANAGER', 'ROP'],
        'todo.assigned' => self::ALL_ROLES,
        'todo.due' => self::ALL_ROLES,
        'chat.message' => self::ALL_ROLES,
        'social.message' => ['CEO', 'ROP', 'MANAGER'],
        'lead.inbound_repeat' => ['CEO', 'ROP', 'MANAGER'],
        'report.daily' => ['CEO', 'ROP'],
        'report.weekly' => ['CEO'],
        'payroll.calculated' => ['CEO'],
    ];

    /** Близкие типы настраиваются одним переключателем. */
    public const TYPE_GROUP = [
        'task.accepted' => 'task.returned',
        'project.cancelled' => 'project.completed',
        'todo.recurring' => 'todo.due',
        'todo.overdue' => 'todo.due',
    ];

    public static function group(string $type): string
    {
        return self::TYPE_GROUP[$type] ?? $type;
    }

    /** Типы, доступные роли (CEO — все). */
    public static function forRole(string $roleCode): array
    {
        return array_keys(array_filter(self::EVENTS, fn (array $roles) => $roleCode === 'CEO' || in_array($roleCode, $roles, true)));
    }

    public static function label(string $type): string
    {
        return t('notifications.types.'.$type);
    }
}

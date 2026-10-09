<?php

namespace App\Support;

/**
 * Каталог прав и права ролей по умолчанию (раздел 3 ТЗ). Права хранятся в таблицах
 * permissions / role_permissions; CEO меняет их в «Настройки → Роли и права».
 * Область видимости: OWN — свои записи, TEAM — отдела (для проектов — своих направлений), ALL — все.
 */
final class Permissions
{
    public const SCOPES = ['OWN', 'TEAM', 'ALL'];

    private const RANK = ['OWN' => 1, 'TEAM' => 2, 'ALL' => 3];

    public const ALL = [
        'dashboard.ceo' => 'Дашборд CEO',
        'dashboard.team' => 'Дашборд отдела',
        'dashboard.own' => 'Личный дашборд',

        'lead.read' => 'Просмотр лидов',
        'lead.create' => 'Создание лидов',
        'lead.update' => 'Редактирование лидов',
        'lead.assign' => 'Распределение лидов',
        'lead.delete' => 'Удаление лидов',

        'client.read' => 'Просмотр клиентов',
        'client.create' => 'Создание клиентов',
        'client.update' => 'Редактирование клиентов',

        'deal.read' => 'Просмотр сделок',
        'deal.create' => 'Создание сделок',
        'deal.update' => 'Редактирование сделок',
        'deal.change_stage' => 'Смена этапа сделки',
        'deal.delete' => 'Удаление сделок',

        'meeting.read' => 'Просмотр встреч',
        'meeting.create' => 'Создание встреч',
        'meeting.update' => 'Редактирование встреч',

        'proposal.read' => 'Просмотр КП',
        'proposal.create' => 'Создание КП',
        'proposal.update' => 'Редактирование КП',
        'proposal.send' => 'Отправка КП',
        'proposal.approve' => 'Утверждение КП',

        'contract.read' => 'Просмотр договоров',
        'contract.create' => 'Создание договоров',
        'contract.update' => 'Редактирование договоров',

        'payment.read' => 'Просмотр оплат',
        'payment.create' => 'Создание оплат',
        'payment.confirm' => 'Подтверждение оплат',
        'payment.refund' => 'Возвраты',

        'project.read' => 'Просмотр проектов',
        'project.create' => 'Создание проектов',
        'project.update' => 'Редактирование проектов',
        'project.assign' => 'Назначение исполнителей',

        'task.read' => 'Просмотр задач',
        'task.create' => 'Создание задач',
        'task.update' => 'Редактирование задач',

        'finance.read' => 'Финансы проектов',
        'finance.company.read' => 'Финансы компании',
        'expense.create' => 'Создание расходов',
        'expense.update' => 'Редактирование расходов',

        'commission.read' => 'Просмотр комиссий',
        'commission.approve' => 'Утверждение и выплата комиссий',
        'commission_rule.manage' => 'Правила комиссий',

        'kpi.read' => 'Просмотр KPI',
        'kpi.target.manage' => 'Управление целями KPI',

        'attendance.read' => 'Просмотр посещаемости',
        'attendance.manage' => 'Управление посещаемостью',
        'schedule.manage' => 'Рабочие графики',

        'payroll.read' => 'Просмотр зарплат',
        'payroll.manage' => 'Расчёт зарплат',

        'employee.read' => 'Просмотр сотрудников',
        'employee.manage' => 'Управление сотрудниками',
        'role.manage' => 'Роли и права',

        'settings.manage' => 'Системные настройки',
        'reference.manage' => 'Справочники',

        'analytics.read' => 'Аналитика',
        'export.run' => 'Экспорт',
        'audit.read' => 'Журнал аудита',
    ];

    /** Права, которые нельзя снять с роли CEO (иначе можно потерять управление системой). */
    public const CEO_LOCKED = ['role.manage', 'employee.manage'];

    public const ROLE_NAMES = [
        'CEO' => 'CEO / Владелец',
        'ROP' => 'Руководитель отдела продаж',
        'MANAGER' => 'Менеджер',
        'EXECUTOR' => 'Исполнитель',
        'HR_ADMIN' => 'HR / Администратор',
        'PROJECT_MANAGER' => 'Проект-менеджер',
    ];

    public static function codes(): array
    {
        return array_keys(self::ALL);
    }

    /** Права ролей по умолчанию: код права → область. Используются при первой установке. */
    public static function defaults(): array
    {
        $all = fn (array $c) => array_fill_keys($c, 'ALL');
        $team = fn (array $c) => array_fill_keys($c, 'TEAM');
        $own = fn (array $c) => array_fill_keys($c, 'OWN');

        return [
            'CEO' => $all(self::codes()),

            // Финансы проектов (прибыль, расходы) — только CEO; РОП назначает исполнителей
            'ROP' => $team([
                'dashboard.team', 'lead.read', 'lead.create', 'lead.update', 'lead.assign', 'lead.delete',
                'client.read', 'client.create', 'client.update',
                'deal.read', 'deal.create', 'deal.update', 'deal.change_stage', 'deal.delete',
                'meeting.read', 'meeting.create', 'meeting.update',
                'proposal.read', 'proposal.create', 'proposal.update', 'proposal.send', 'proposal.approve',
                'contract.read', 'contract.create', 'contract.update',
                'payment.read', 'payment.create', 'payment.confirm', 'payment.refund',
                'project.read', 'project.create', 'project.update', 'project.assign',
                'task.read', 'task.create', 'task.update',
                'commission.read', 'kpi.read', 'kpi.target.manage', 'attendance.read',
                'employee.read', 'analytics.read', 'export.run',
            ]) + $own(['payroll.read']),

            'MANAGER' => $own([
                'dashboard.own', 'lead.read', 'lead.create', 'lead.update',
                'client.read', 'client.create', 'client.update',
                'deal.read', 'deal.create', 'deal.update', 'deal.change_stage',
                'meeting.read', 'meeting.create', 'meeting.update',
                'proposal.read', 'proposal.create', 'proposal.update', 'proposal.send',
                'contract.read', 'contract.create', 'contract.update',
                'payment.read', 'payment.create', 'project.read',
                'task.read', 'task.create', 'task.update',
                'commission.read', 'kpi.read', 'attendance.read', 'payroll.read', 'analytics.read',
            ]),

            'EXECUTOR' => $own(['dashboard.own', 'project.read', 'task.read', 'task.update', 'kpi.read', 'payroll.read']),

            'HR_ADMIN' => $all([
                'kpi.read', 'kpi.target.manage', 'attendance.read', 'attendance.manage', 'schedule.manage',
                'employee.read', 'employee.manage', 'settings.manage', 'reference.manage', 'export.run', 'audit.read',
            ]) + $own(['payroll.read']),

            // Проект-менеджер направления: «Отдел» для проектов = проекты его направлений
            'PROJECT_MANAGER' => $team(['project.read', 'project.update', 'project.assign', 'task.read', 'task.create', 'task.update'])
                + $all(['employee.read'])
                + $own(['dashboard.own', 'kpi.read', 'payroll.read']),
        ];
    }

    /** Есть ли право в карте прав, не ниже указанной области. */
    public static function has(array $map, string $code, string $minScope = 'OWN'): bool
    {
        $scope = $map[$code] ?? null;

        return $scope !== null && self::RANK[$scope] >= self::RANK[$minScope];
    }
}

<?php

/**
 * Левое меню (ТЗ §4). Подписи — lang/{uz,ru}/nav.json. any — пункт виден при любом из прав;
 * без any — любому вошедшему. Раздел с подпунктами виден, если виден хоть один подпункт.
 * Скрытие пункта — только удобство: доступ проверяется на сервере (perm:… у маршрутов).
 */
return [
    ['key' => 'dashboard', 'href' => '/dashboard', 'icon' => 'dashboard'],
    ['key' => 'sales', 'icon' => 'trending', 'children' => [
        ['key' => 'leads', 'href' => '/sales/leads', 'any' => ['lead.read']],
        ['key' => 'deals', 'href' => '/sales/deals', 'any' => ['deal.read']],
        ['key' => 'pipeline', 'href' => '/sales/pipeline', 'any' => ['lead.read', 'deal.read']],
        ['key' => 'meetings', 'href' => '/sales/meetings', 'any' => ['meeting.read']],
        ['key' => 'proposals', 'href' => '/sales/proposals', 'any' => ['proposal.read']],
        ['key' => 'contracts', 'href' => '/sales/contracts', 'any' => ['contract.read']],
        ['key' => 'followUps', 'href' => '/sales/follow-ups', 'any' => ['client.read']],
    ]],
    ['key' => 'clients', 'href' => '/clients', 'icon' => 'briefcase', 'any' => ['client.read']],
    ['key' => 'inbox', 'href' => '/inbox', 'icon' => 'at', 'any' => ['lead.read']],
    ['key' => 'projects', 'icon' => 'gauge', 'children' => [
        ['key' => 'projectsAll', 'href' => '/projects/all', 'any' => ['project.read']],
        ['key' => 'projectsActive', 'href' => '/projects/active', 'any' => ['project.read']],
        ['key' => 'projectsOverdue', 'href' => '/projects/overdue', 'any' => ['project.read']],
        ['key' => 'projectsCompleted', 'href' => '/projects/completed', 'any' => ['project.read']],
    ]],
    ['key' => 'tasks', 'href' => '/tasks', 'icon' => 'check', 'any' => ['task.read']],
    ['key' => 'todos', 'href' => '/todos', 'icon' => 'list'],
    ['key' => 'team', 'icon' => 'users', 'children' => [
        ['key' => 'employees', 'href' => '/team/employees', 'any' => ['employee.read']],
        ['key' => 'managers', 'href' => '/team/managers', 'any' => ['employee.read']],
        ['key' => 'rops', 'href' => '/team/rop', 'any' => ['employee.read']],
        ['key' => 'executors', 'href' => '/team/executors', 'any' => ['employee.read']],
    ]],
    ['key' => 'finance', 'icon' => 'wallet', 'children' => [
        ['key' => 'revenue', 'href' => '/finance/revenue', 'any' => ['finance.read']],
        ['key' => 'payments', 'href' => '/finance/payments', 'any' => ['payment.read']],
        ['key' => 'expenses', 'href' => '/finance/expenses', 'any' => ['finance.read']],
        ['key' => 'incomes', 'href' => '/finance/incomes', 'any' => ['finance.company.read']],
        ['key' => 'profit', 'href' => '/finance/profit', 'any' => ['finance.read']],
        ['key' => 'commissions', 'href' => '/finance/commissions', 'any' => ['commission.read']],
        ['key' => 'payroll', 'href' => '/finance/payroll', 'any' => ['payroll.read']],
    ]],
    ['key' => 'kpi', 'href' => '/kpi', 'icon' => 'target', 'any' => ['kpi.read']],
    ['key' => 'attendance', 'href' => '/attendance', 'icon' => 'calendar', 'any' => ['attendance.read']],
    ['key' => 'analytics', 'href' => '/analytics', 'icon' => 'chart', 'any' => ['analytics.read']],
    ['key' => 'notifications', 'href' => '/notifications', 'icon' => 'bell'],
    ['key' => 'settings', 'href' => '/settings', 'icon' => 'settings', 'any' => [
        'settings.manage', 'role.manage', 'employee.manage', 'audit.read', 'reference.manage',
        'commission_rule.manage', 'schedule.manage',
    ]],
];

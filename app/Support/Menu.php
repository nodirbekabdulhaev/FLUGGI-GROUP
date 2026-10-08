<?php

namespace App\Support;

use Illuminate\Support\Facades\Gate;

class Menu
{
    /** [group => [[label, icon, route-or-url, [any-of permissions], match-prefix]]] */
    public static function all(): array
    {
        return [
            '' => [
                ['Dashboard', 'home', '/dashboard', ['dashboard.view'], 'dashboard'],
            ],
            'CRM' => [
                ['Лиды', 'phone', '/leads', ['leads.view'], 'leads'],
                ['Воронка', 'funnel', '/leads/funnel', ['leads.view'], 'leads/funnel'],
                ['Источники', 'tag', '/reports/sources', ['reports.sales'], 'reports/sources'],
                ['Менеджеры', 'users', '/reports/managers', ['reports.sales'], 'reports/managers'],
            ],
            'Обучение' => [
                ['Ученики', 'academic', '/students', ['students.view'], 'students'],
                ['Родители', 'heart', '/parents', ['students.view'], 'parents'],
                ['Курсы', 'book', '/courses', ['courses.view'], 'courses'],
                ['Группы', 'group', '/groups', ['groups.view', 'groups.view_own'], 'groups'],
                ['Расписание', 'calendar', '/schedule', ['schedule.view'], 'schedule'],
                ['Занятия', 'clock', '/lessons', ['schedule.view', 'attendance.mark'], 'lessons'],
                ['Посещаемость', 'check', '/attendance', ['attendance.view'], 'attendance'],
            ],
            'Финансы' => [
                ['Оплаты', 'cash', '/finance/payments', ['payments.view'], 'finance/payments'],
                ['Долги', 'alert', '/finance/debts', ['debts.view'], 'finance/debts'],
                ['Расходы', 'receipt', '/finance/expenses', ['expenses.view'], 'finance/expenses'],
                ['Зарплаты', 'wallet', '/finance/salaries', ['salaries.view'], 'finance/salaries'],
            ],
            'Персонал' => [
                ['Преподаватели', 'user', '/teachers', ['teachers.view'], 'teachers'],
                ['Сотрудники', 'briefcase', '/employees', ['employees.view'], 'employees'],
            ],
            'Филиалы' => [
                ['Филиалы', 'building', '/branches', ['branches.view'], 'branches'],
                ['Аудитории', 'door', '/rooms', ['branches.view'], 'rooms'],
            ],
            'Аналитика' => [
                ['Продажи', 'chart', '/reports/sales', ['reports.sales'], 'reports/sales'],
                ['Ученики', 'chart', '/reports/students', ['reports.students'], 'reports/students'],
                ['Финансы', 'chart', '/reports/finance', ['reports.finance'], 'reports/finance'],
                ['Посещаемость', 'chart', '/reports/attendance', ['reports.attendance'], 'reports/attendance'],
                ['Преподаватели', 'chart', '/reports/teachers', ['reports.teachers'], 'reports/teachers'],
            ],
            'Уведомления' => [
                ['Telegram', 'send', '/notifications/telegram', ['telegram.manage'], 'notifications'],
            ],
            'Настройки' => [
                ['Пользователи', 'users', '/users', ['users.manage'], 'users'],
                ['Роли', 'shield', '/roles', ['roles.manage'], 'roles'],
                ['Справочники', 'list', '/settings/dictionaries', ['settings.manage'], 'settings/dictionaries'],
                ['История действий', 'history', '/audit', ['audit.view'], 'audit'],
                ['Система', 'cog', '/settings', ['settings.manage', 'backup.manage'], 'settings'],
            ],
        ];
    }

    public static function allowed(array $perms): bool
    {
        foreach ($perms as $p) {
            if (Gate::allows($p)) {
                return true;
            }
        }

        return false;
    }

    /** Menu filtered by the current user's permissions. */
    public static function visible(): array
    {
        $out = [];
        foreach (static::all() as $group => $items) {
            $items = array_values(array_filter($items, fn ($i) => static::allowed($i[3])));
            if ($items) {
                $out[$group] = $items;
            }
        }

        return $out;
    }

    public static function home(): string
    {
        foreach (static::visible() as $items) {
            return $items[0][2];
        }

        return '/profile';
    }
}

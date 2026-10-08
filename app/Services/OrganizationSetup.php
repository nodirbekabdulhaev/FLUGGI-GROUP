<?php

namespace App\Services;

use App\Models\ExpenseCategory;
use App\Models\ExpulsionReason;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Role;
use App\Support\Tenant;
use Illuminate\Support\Str;

/** Creates the default dictionaries (spec §64) and system roles. */
class OrganizationSetup
{
    public const ROLES = [
        'super_admin' => ['Super Admin', 'Полный доступ', ['*']],
        'director' => ['Директор', 'Dashboard, CRM, ученики, группы, преподаватели, финансы, отчёты', [
            'dashboard.view', 'leads.view', 'leads.view_all', 'leads.manage', 'leads.delete', 'students.view', 'students.manage', 'students.delete',
            'courses.view', 'courses.manage', 'groups.view', 'groups.manage', 'groups.delete', 'schedule.view', 'schedule.manage',
            'attendance.view', 'attendance.mark', 'teachers.view', 'teachers.manage', 'employees.view', 'employees.manage',
            'branches.view', 'payments.view', 'payments.create', 'payments.correct', 'debts.view', 'expenses.view', 'expenses.manage',
            'salaries.view', 'salaries.manage', 'reports.sales', 'reports.finance', 'reports.students', 'reports.attendance', 'reports.teachers',
            'telegram.manage', 'audit.view',
        ]],
        'admin' => ['Администратор', 'Лиды, ученики, группы, расписание, посещаемость, оплаты, долги', [
            'dashboard.view', 'leads.view', 'leads.view_all', 'leads.manage', 'students.view', 'students.manage', 'courses.view',
            'groups.view', 'groups.manage', 'schedule.view', 'schedule.manage', 'attendance.view', 'attendance.mark',
            'teachers.view', 'branches.view', 'payments.view', 'payments.create', 'debts.view',
        ]],
        'sales_manager' => ['Sales Manager', 'Лиды, воронка, конверсия, свои продажи', [
            'dashboard.view', 'leads.view', 'leads.manage', 'students.view', 'courses.view', 'reports.sales',
        ]],
        'teacher' => ['Преподаватель', 'Только свои группы, ученики, расписание и посещаемость', [
            'groups.view_own', 'schedule.view', 'attendance.view', 'attendance.mark',
        ]],
        'accountant' => ['Бухгалтер', 'Оплаты, расходы, зарплаты, финансовые отчёты', [
            'dashboard.view', 'students.view', 'payments.view', 'payments.create', 'payments.correct', 'debts.view',
            'expenses.view', 'expenses.manage', 'salaries.view', 'salaries.manage', 'reports.finance',
        ]],
    ];

    public static function syncPermissions(): void
    {
        foreach (Permission::ALL as $slug => [$group, $name]) {
            Permission::updateOrCreate(['slug' => $slug], ['group' => $group, 'name' => $name]);
        }
    }

    /** System roles are shared by all organizations (organization_id = null). */
    public static function syncRoles(): void
    {
        static::syncPermissions();
        $ids = Permission::pluck('id', 'slug');

        foreach (static::ROLES as $slug => [$name, $desc, $perms]) {
            $role = Role::updateOrCreate(
                ['organization_id' => null, 'slug' => $slug],
                ['name' => $name, 'description' => $desc, 'is_system' => true]
            );
            $role->permissions()->sync($perms === ['*'] ? $ids->values() : collect($perms)->map(fn ($p) => $ids[$p])->all());
        }
    }

    public function createOrganization(string $name, array $attrs = []): Organization
    {
        $org = Organization::create($attrs + [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'webhook_key' => Str::random(40),
        ]);

        Tenant::run($org, fn () => $this->seedDictionaries());

        return $org;
    }

    /** Must run inside Tenant::run(). */
    public function seedDictionaries(): void
    {
        foreach (['Instagram', 'Telegram', 'Website', 'Facebook', 'Google', 'Рекомендация', 'Звонок', 'Офлайн', 'Другое'] as $n) {
            LeadSource::firstOrCreate(['name' => $n]);
        }

        // name, slug, funnel stage, lost?, color
        $statuses = [
            ['Новый', 'new', 1, false, 'blue'], ['В работе', 'in_work', 1, false, 'indigo'],
            ['Связались', 'contacted', 2, false, 'indigo'], ['Интересуется', 'interested', 2, false, 'indigo'],
            ['Записан на пробный', 'trial_booked', 3, false, 'amber'], ['Пришёл на пробный', 'trial_attended', 4, false, 'amber'],
            ['Оплатил', 'paid', 5, false, 'emerald'], ['Не отвечает', 'no_answer', 0, false, 'slate'],
            ['Думает', 'thinking', 2, false, 'slate'], ['Перенёс', 'rescheduled', 3, false, 'slate'],
            ['Не пришёл', 'no_show', 3, false, 'rose'], ['Отказ', 'lost', 0, true, 'rose'],
            ['Ученик', 'converted', 5, false, 'emerald'],
        ];
        foreach ($statuses as $i => [$name, $slug, $stage, $lost, $color]) {
            LeadStatus::firstOrCreate(['slug' => $slug], ['name' => $name, 'stage' => $stage, 'is_lost' => $lost, 'color' => $color, 'sort' => $i]);
        }

        foreach ([['Наличные', 'cash'], ['Click', 'click'], ['Payme', 'payme'], ['Uzum', 'uzum'], ['Перевод', 'transfer'], ['Другое', 'other']] as [$n, $c]) {
            PaymentMethod::firstOrCreate(['code' => $c], ['name' => $n]);
        }

        foreach (['Аренда', 'Зарплата', 'Реклама', 'Коммунальные', 'Интернет', 'Канцелярия', 'Оборудование', 'Ремонт', 'Прочее'] as $n) {
            ExpenseCategory::firstOrCreate(['name' => $n]);
        }

        foreach (['Дорого', 'Нет времени', 'Переезд', 'Не понравился преподаватель', 'Не получил результат', 'Смена курса', 'Смена центра', 'Неизвестно', 'Другое'] as $n) {
            ExpulsionReason::firstOrCreate(['name' => $n]);
        }
    }
}

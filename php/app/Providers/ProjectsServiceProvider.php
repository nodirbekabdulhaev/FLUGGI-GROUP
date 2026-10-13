<?php

namespace App\Providers;

use App\Models\PayrollEntry;
use App\Services\Automation\Scheduler;
use App\Services\Finance\CostLineService;
use App\Services\Finance\PartnerEvents;
use App\Services\People\PeopleService;
use App\Support\Outbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

/**
 * Модуль «Проекты и люди»: план расходов проекта по тарифу принятого КП (подписчик outbox)
 * предварительный расчёт зарплаты для ежемесячной задачи планировщика и уведомления о выплатах партнёру.
 */
class ProjectsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->instance(Scheduler::PAYROLL_BINDING, function (string $period): int {
            DB::transaction(fn () => PeopleService::calculate($period));

            return PayrollEntry::where('period', $period)->count();
        });
    }

    public function boot(): void
    {
        Outbox::on('project.created', function (array $payload) {
            DB::transaction(fn () => CostLineService::generate($payload['projectId']));
        });
        // Выплаты доли партнёра: уведомления CEO о заявке и партнёру о решении
        PartnerEvents::register();
    }
}

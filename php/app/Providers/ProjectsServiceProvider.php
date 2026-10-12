<?php

namespace App\Providers;

use App\Services\Finance\CostLineService;
use App\Support\Outbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

/** Подписчики модуля «Проекты и люди»: план расходов проекта по тарифу принятого КП. */
class ProjectsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Outbox::on('project.created', function (array $payload) {
            DB::transaction(fn () => CostLineService::generate($payload['projectId']));
        });
    }
}

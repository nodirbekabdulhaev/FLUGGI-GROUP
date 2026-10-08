<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Debt;
use App\Services\NotificationService;
use App\Services\PaymentService;

class CheckDebts extends Command
{
    use RunsPerOrganization;

    protected $signature = 'debts:check';
    protected $description = 'Проверка задолженностей и напоминания об оплате (cron 20:00)';

    public function handle(): int
    {
        return $this->eachOrganization(function () {
            $n = app(PaymentService::class)->remindDebts();
            $total = (float) Debt::where('balance', '>', 0)->sum('balance');
            $cnt = Debt::where('balance', '>', 0)->count();
            if ($cnt) {
                app(NotificationService::class)->toStaff('debts.view', 'payment_due', 'Задолженности',
                    "Должников: $cnt, общая сумма: ".number_format($total, 0, '.', ' ').' сум', '/finance/debts', null, 'debts-summary:'.now()->toDateString());
            }
            $this->info("reminders queued: $n");
        });
    }
}

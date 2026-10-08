<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\BackupService;

class RunBackup extends Command
{
    use RunsPerOrganization;

    protected $signature = 'backup:run';
    protected $description = 'Резервная копия базы данных (cron ежедневно)';

    public function handle(): int
    {
        $name = app(BackupService::class)->run();
        $this->info($name);

        return self::SUCCESS;
    }
}

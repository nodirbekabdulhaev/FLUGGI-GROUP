<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;

class RestoreBackup extends Command
{
    protected $signature = 'backup:restore {file : имя файла из storage/app/backups} {--force : без подтверждения}';
    protected $description = 'Восстановление базы данных из резервной копии (ПЕРЕЗАПИСЫВАЕТ все данные)';

    public function handle(BackupService $svc): int
    {
        if (! $this->option('force') && ! $this->confirm('Все текущие данные будут заменены данными из '.$this->argument('file').'. Продолжить?')) {
            return self::FAILURE;
        }
        $n = $svc->restore($this->argument('file'));
        $this->info("Восстановлено, выполнено операций: $n");

        return self::SUCCESS;
    }
}

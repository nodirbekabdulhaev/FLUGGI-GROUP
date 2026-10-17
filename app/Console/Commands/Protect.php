<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Триггеры MySQL, защищающие журналы и финансовые записи (database/schema/protect.sql).
 * На виртуальном хостинге создание триггеров бывает запрещено — тогда защиту обеспечивает
 * приложение, установка продолжается с предупреждением.
 */
class Protect extends Command
{
    protected $signature = 'fluggi:protect';

    protected $description = 'Триггеры неизменяемости данных (если хостинг разрешает)';

    public function handle(): int
    {
        $sql = preg_replace('/^\s*--.*$/m', '', file_get_contents(database_path('schema/protect.sql')));
        try {
            foreach (array_filter(array_map('trim', explode(";\n", $sql))) as $statement) {
                DB::unprepared($statement);
            }
            $this->info('✓ Защита данных триггерами включена');
        } catch (Throwable $e) {
            $this->warn('⚠ Хостинг не разрешает триггеры — CRM работает и без них (записи защищает приложение)');
        }

        return self::SUCCESS;
    }
}

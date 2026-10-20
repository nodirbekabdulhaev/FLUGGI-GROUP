<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Полная схема Fluggi (69 таблиц) — та же, что у предыдущей версии на Node.js, поэтому
 * база от неё подходит без переноса данных: если таблицы уже есть, миграция их не трогает.
 * Описание таблиц — раздел 13 ТЗ и DATABASE.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users')) {
            return;
        }
        foreach (self::statements(database_path('schema/fluggi.sql')) as $sql) {
            DB::unprepared($sql);
        }
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach (DB::select("SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name <> 'migrations'") as $row) {
            Schema::drop($row->t);
        }
        Schema::enableForeignKeyConstraints();
    }

    /** SQL-файл → отдельные команды (комментарии `--` убираются). */
    public static function statements(string $file): array
    {
        $sql = preg_replace('/^\s*--.*$/m', '', file_get_contents($file));

        return array_values(array_filter(array_map('trim', explode(";\n", $sql))));
    }
};

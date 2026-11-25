<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Сданные в налоговую отчёты (приложение 4: доход и НДФЛ по каждому человеку нарастающим итогом за год).
 * Строки загружаются из файлов отчёта (Бухгалтерия → Сданные отчёты) и служат источником правды для НДФЛ:
 * НДФЛ месяца = Σ «с начала года» этого месяца − то же за предыдущий месяц года; сальдо идёт нарастающим итогом.
 * person_id — человек из acc_people (по ПИНФЛ); NULL — строка без человека (например, пробная запись в отчёте).
 * Повторный запуск безопасен.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('acc_tax_filings')) {
            return;
        }
        $charset = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
        DB::statement("CREATE TABLE `acc_tax_filings` (
            `id` CHAR(36) NOT NULL,
            `period` CHAR(7) NOT NULL,
            `row_no` SMALLINT UNSIGNED NOT NULL,
            `person_id` CHAR(36) NULL,
            `pinfl` VARCHAR(20) NULL,
            `full_name` VARCHAR(191) NOT NULL,
            `position` VARCHAR(191) NULL,
            `status` TINYINT UNSIGNED NOT NULL DEFAULT 1,
            `work_start` DATE NULL,
            `income_ytd` DECIMAL(18, 2) NOT NULL DEFAULT 0,
            `income_month` DECIMAL(18, 2) NOT NULL DEFAULT 0,
            `ndfl_ytd` DECIMAL(18, 2) NOT NULL DEFAULT 0,
            `ndfl_month` DECIMAL(18, 2) NOT NULL DEFAULT 0,
            `benefits` JSON NULL,
            `source_file` VARCHAR(191) NULL,
            `imported_by_id` CHAR(36) NULL,
            `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            `updated_at` DATETIME(3) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE INDEX `acc_tax_filings_period_row_key` (`period`, `row_no`),
            INDEX `acc_tax_filings_person_idx` (`person_id`),
            CONSTRAINT `acc_tax_filings_person_id_fkey` FOREIGN KEY (`person_id`) REFERENCES `acc_people`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `acc_tax_filings_imported_by_fkey` FOREIGN KEY (`imported_by_id`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB $charset");
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_tax_filings');
    }
};

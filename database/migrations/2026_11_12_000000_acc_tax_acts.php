<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Акты сверки с налоговой («Солиштирма далолатнома» из личного кабинета): по каждому году и налогу — сальдо на начало и конец,
 * начислено и оплачено. year_end = 1: акт за прошлый год (сальдо на 31 декабря); 0: за текущий год на дату формирования.
 * Остаток на конец прошлого года из акта становится сальдо на начало года в разделе «Налоги». Повторный запуск безопасен.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('acc_tax_acts')) {
            return;
        }
        $charset = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
        DB::statement("CREATE TABLE `acc_tax_acts` (
            `id` CHAR(36) NOT NULL,
            `year` SMALLINT UNSIGNED NOT NULL,
            `tax_code` VARCHAR(8) NOT NULL,
            `tax_name` VARCHAR(191) NOT NULL,
            `as_of` DATE NOT NULL,
            `year_end` TINYINT(1) NOT NULL DEFAULT 0,
            `opening_debt` DECIMAL(18, 2) NOT NULL DEFAULT 0,
            `opening_overpay` DECIMAL(18, 2) NOT NULL DEFAULT 0,
            `closing_debt` DECIMAL(18, 2) NOT NULL DEFAULT 0,
            `closing_overpay` DECIMAL(18, 2) NOT NULL DEFAULT 0,
            `closing_penalty` DECIMAL(18, 2) NOT NULL DEFAULT 0,
            `accrued` DECIMAL(18, 2) NOT NULL DEFAULT 0,
            `accrued_penalty` DECIMAL(18, 2) NOT NULL DEFAULT 0,
            `paid` DECIMAL(18, 2) NOT NULL DEFAULT 0,
            `refunded` DECIMAL(18, 2) NOT NULL DEFAULT 0,
            `source_file` VARCHAR(191) NULL,
            `imported_by_id` CHAR(36) NULL,
            `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            `updated_at` DATETIME(3) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE INDEX `acc_tax_acts_year_code_key` (`year`, `tax_code`),
            CONSTRAINT `acc_tax_acts_imported_by_fkey` FOREIGN KEY (`imported_by_id`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB $charset");
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_tax_acts');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Бухгалтерский баланс (форма №1) и отчёт о финансовых результатах (форма №2): значения строк, заданные вручную или загруженные из
 * сданных форм. form: B — баланс (сумма на конец года по коду строки), F — финрезультаты (сумма за год). amount — в сумах.
 * Строка без записи считается автоматически из данных системы; остаток на начало года — это конец прошлого года (переносится).
 * Повторный запуск безопасен.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('acc_statement_lines')) {
            return;
        }
        $charset = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
        DB::statement("CREATE TABLE `acc_statement_lines` (
            `id` CHAR(36) NOT NULL,
            `year` SMALLINT UNSIGNED NOT NULL,
            `form` CHAR(1) NOT NULL,
            `code` VARCHAR(4) NOT NULL,
            `amount` DECIMAL(18, 2) NOT NULL,
            `source` VARCHAR(8) NOT NULL DEFAULT 'MANUAL',
            `note` VARCHAR(500) NULL,
            `source_file` VARCHAR(191) NULL,
            `updated_by_id` CHAR(36) NULL,
            `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            `updated_at` DATETIME(3) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE INDEX `acc_statement_lines_key` (`year`, `form`, `code`),
            CONSTRAINT `acc_statement_lines_user_fkey` FOREIGN KEY (`updated_by_id`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB $charset");
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_statement_lines');
    }
};

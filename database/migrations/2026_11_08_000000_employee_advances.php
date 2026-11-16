<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Авансы сотрудникам:
 * 1) employee_advances — выданный аванс (сумма в UZS, дата выдачи, месяц зарплаты, из которой он удерживается);
 *    не удаляется — только отмена с причиной (cancelled_at / cancelled_by_id / cancel_reason);
 *    при желании — с операцией «Зарплата» в Бухгалтерии (accounting_transaction_id);
 * 2) payroll_entries.advances — сумма авансов месяца в строке зарплаты; к выплате = итого − авансы.
 * Повторный запуск безопасен.
 */
return new class extends Migration
{
    public function up(): void
    {
        $charset = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';

        if (! Schema::hasTable('employee_advances')) {
            DB::statement("CREATE TABLE `employee_advances` (
                `id` CHAR(36) NOT NULL,
                `user_id` CHAR(36) NOT NULL,
                `amount` DECIMAL(18, 2) NOT NULL,
                `paid_at` DATE NOT NULL,
                `period` CHAR(7) NOT NULL,
                `comment` TEXT NULL,
                `created_by_id` CHAR(36) NOT NULL,
                `accounting_transaction_id` CHAR(36) NULL,
                `cancelled_at` DATETIME(3) NULL,
                `cancelled_by_id` CHAR(36) NULL,
                `cancel_reason` TEXT NULL,
                `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                `updated_at` DATETIME(3) NOT NULL,
                PRIMARY KEY (`id`),
                INDEX `employee_advances_user_period_idx` (`user_id`, `period`),
                INDEX `employee_advances_period_idx` (`period`),
                CONSTRAINT `employee_advances_user_id_fkey` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
                CONSTRAINT `employee_advances_created_by_id_fkey` FOREIGN KEY (`created_by_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
                CONSTRAINT `employee_advances_cancelled_by_id_fkey` FOREIGN KEY (`cancelled_by_id`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT `employee_advances_acc_transaction_id_fkey` FOREIGN KEY (`accounting_transaction_id`) REFERENCES `acc_transactions`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB $charset");
        }
        if (! Schema::hasColumn('payroll_entries', 'advances')) {
            DB::statement('ALTER TABLE `payroll_entries` ADD COLUMN `advances` DECIMAL(18, 2) NOT NULL DEFAULT 0 AFTER `penalty`');
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('payroll_entries', 'advances')) {
            DB::statement('ALTER TABLE `payroll_entries` DROP COLUMN `advances`');
        }
        DB::unprepared('DROP TRIGGER IF EXISTS `employee_advances_no_delete`');
        Schema::dropIfExists('employee_advances');
    }
};

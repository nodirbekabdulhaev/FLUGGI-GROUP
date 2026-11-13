<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Кредиты: договор (сумма, ставка, срок) и график платежей — по строке на каждый месяц, с отметкой «оплачено».
 * За N дней до срока платежа планировщик создаёт дело (todo_id хранит созданное дело, чтобы не дублировать). Повторный запуск безопасен.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('acc_loans')) {
            return;
        }
        $charset = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
        DB::statement("CREATE TABLE `acc_loans` (
            `id` CHAR(36) NOT NULL,
            `name` VARCHAR(191) NOT NULL,
            `lender` VARCHAR(191) NULL,
            `currency` CHAR(3) NOT NULL DEFAULT 'UZS',
            `principal` DECIMAL(18, 2) NOT NULL,
            `rate` DECIMAL(6, 2) NULL,
            `start_date` DATE NULL,
            `end_date` DATE NULL,
            `note` TEXT NULL,
            `owner_id` CHAR(36) NULL,
            `remind_days` TINYINT UNSIGNED NOT NULL DEFAULT 7,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_by_id` CHAR(36) NULL,
            `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            `updated_at` DATETIME(3) NOT NULL,
            `deleted_at` DATETIME(3) NULL,
            PRIMARY KEY (`id`),
            CONSTRAINT `acc_loans_owner_id_fkey` FOREIGN KEY (`owner_id`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
        ) $charset");
        DB::statement("CREATE TABLE `acc_loan_payments` (
            `id` CHAR(36) NOT NULL,
            `loan_id` CHAR(36) NOT NULL,
            `due_date` DATE NOT NULL,
            `principal` DECIMAL(18, 2) NOT NULL DEFAULT 0,
            `interest` DECIMAL(18, 2) NOT NULL DEFAULT 0,
            `total` DECIMAL(18, 2) NOT NULL,
            `paid_at` DATE NULL,
            `paid_amount` DECIMAL(18, 2) NULL,
            `todo_id` CHAR(36) NULL,
            `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            `updated_at` DATETIME(3) NOT NULL,
            PRIMARY KEY (`id`),
            INDEX `acc_loan_payments_loan_due_idx` (`loan_id`, `due_date`),
            INDEX `acc_loan_payments_due_idx` (`due_date`, `paid_at`),
            CONSTRAINT `acc_loan_payments_loan_id_fkey` FOREIGN KEY (`loan_id`) REFERENCES `acc_loans`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) $charset");
    }

    public function down(): void {}
};

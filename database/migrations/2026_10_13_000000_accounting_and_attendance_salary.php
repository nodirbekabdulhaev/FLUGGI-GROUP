<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1) Оклад по посещаемости: в строке зарплаты — оклад при 100% и процент посещаемости месяца.
 * 2) Бухгалтерия: счета и кошельки, операции, люди для налоговой, зарплата для налоговой.
 * 3) Роль «Бухгалтер».
 */
return new class extends Migration
{
    public function up(): void
    {
        $roles = "'CEO', 'ROP', 'MANAGER', 'EXECUTOR', 'HR_ADMIN', 'PROJECT_MANAGER', 'ACCOUNTANT'";
        DB::statement("ALTER TABLE `roles` MODIFY `code` ENUM($roles) NOT NULL");
        DB::statement("ALTER TABLE `work_schedules` MODIFY `role_code` ENUM($roles) NULL");

        if (! Schema::hasColumn('payroll_entries', 'attendance_pct')) {
            DB::statement('ALTER TABLE `payroll_entries` ADD COLUMN `base_full` DECIMAL(18, 2) NULL AFTER `base_salary`, ADD COLUMN `attendance_pct` DECIMAL(6, 2) NULL AFTER `base_full`');
        }

        if (Schema::hasTable('acc_accounts')) {
            return;
        }
        $charset = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
        DB::statement("CREATE TABLE `acc_accounts` (
            `id` CHAR(36) NOT NULL,
            `name` VARCHAR(191) NOT NULL,
            `kind` ENUM('BANK', 'CASH', 'CARD') NOT NULL,
            `bank_name` TEXT NULL,
            `account_number` VARCHAR(64) NULL,
            `currency` ENUM('UZS', 'USD') NOT NULL DEFAULT 'UZS',
            `opening_balance` DECIMAL(18, 2) NOT NULL DEFAULT 0,
            `opening_date` DATE NULL,
            `crm_methods` JSON NULL,
            `is_active` BOOLEAN NOT NULL DEFAULT true,
            `sort` INTEGER NOT NULL DEFAULT 0,
            `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            `updated_at` DATETIME(3) NOT NULL,
            PRIMARY KEY (`id`)
        ) $charset");
        DB::statement("CREATE TABLE `acc_people` (
            `id` CHAR(36) NOT NULL,
            `full_name` VARCHAR(191) NOT NULL,
            `pinfl` VARCHAR(14) NULL,
            `position` VARCHAR(191) NULL,
            `monthly_salary` DECIMAL(18, 2) NOT NULL DEFAULT 0,
            `user_id` CHAR(36) NULL,
            `hired_at` DATE NULL,
            `fired_at` DATE NULL,
            `is_active` BOOLEAN NOT NULL DEFAULT true,
            `comment` TEXT NULL,
            `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            `updated_at` DATETIME(3) NOT NULL,
            PRIMARY KEY (`id`),
            CONSTRAINT `acc_people_user_id_fkey` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
        ) $charset");
        DB::statement("CREATE TABLE `acc_transactions` (
            `id` CHAR(36) NOT NULL,
            `account_id` CHAR(36) NOT NULL,
            `direction` ENUM('IN', 'OUT') NOT NULL,
            `category` VARCHAR(32) NOT NULL,
            `amount` DECIMAL(18, 2) NOT NULL,
            `date` DATE NOT NULL,
            `period` CHAR(7) NULL,
            `counterparty` VARCHAR(191) NULL,
            `description` TEXT NULL,
            `person_id` CHAR(36) NULL,
            `payment_id` CHAR(36) NULL,
            `transfer_group` CHAR(36) NULL,
            `created_by_id` CHAR(36) NULL,
            `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            `updated_at` DATETIME(3) NOT NULL,
            `deleted_at` DATETIME(3) NULL,
            `deleted_by_id` CHAR(36) NULL,
            `delete_reason` TEXT NULL,
            PRIMARY KEY (`id`),
            UNIQUE INDEX `acc_transactions_payment_id_direction_key` (`payment_id`, `direction`),
            INDEX `acc_transactions_account_date_idx` (`account_id`, `date`),
            INDEX `acc_transactions_period_category_idx` (`period`, `category`),
            INDEX `acc_transactions_date_idx` (`date`),
            CONSTRAINT `acc_transactions_account_id_fkey` FOREIGN KEY (`account_id`) REFERENCES `acc_accounts`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
            CONSTRAINT `acc_transactions_person_id_fkey` FOREIGN KEY (`person_id`) REFERENCES `acc_people`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
        ) $charset");
        DB::statement("CREATE TABLE `acc_payroll` (
            `id` CHAR(36) NOT NULL,
            `person_id` CHAR(36) NOT NULL,
            `period` CHAR(7) NOT NULL,
            `gross` DECIMAL(18, 2) NOT NULL DEFAULT 0,
            `ndfl_rate` DECIMAL(6, 3) NOT NULL,
            `ndfl` DECIMAL(18, 2) NOT NULL DEFAULT 0,
            `inps_rate` DECIMAL(6, 3) NOT NULL,
            `inps` DECIMAL(18, 2) NOT NULL DEFAULT 0,
            `net` DECIMAL(18, 2) NOT NULL DEFAULT 0,
            `status` ENUM('DRAFT', 'APPROVED') NOT NULL DEFAULT 'DRAFT',
            `approved_by_id` CHAR(36) NULL,
            `approved_at` DATETIME(3) NULL,
            `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            `updated_at` DATETIME(3) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE INDEX `acc_payroll_person_id_period_key` (`person_id`, `period`),
            INDEX `acc_payroll_period_idx` (`period`),
            CONSTRAINT `acc_payroll_person_id_fkey` FOREIGN KEY (`person_id`) REFERENCES `acc_people`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE
        ) $charset");
    }

    public function down(): void
    {
        foreach (['acc_payroll', 'acc_transactions', 'acc_people', 'acc_accounts'] as $t) {
            Schema::dropIfExists($t);
        }
        if (Schema::hasColumn('payroll_entries', 'attendance_pct')) {
            DB::statement('ALTER TABLE `payroll_entries` DROP COLUMN `base_full`, DROP COLUMN `attendance_pct`');
        }
    }
};

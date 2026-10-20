<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Бухгалтерия: свои статьи прихода и расхода поверх базовых категорий.
 * Статья «учитывается как» одна из базовых категорий ручного ввода (base): налоги, годовой отчёт
 * и прочие расчёты по-прежнему идут по acc_transactions.category (туда пишется base статьи),
 * а acc_transactions.category_id хранит саму статью — для списков, фильтров и выгрузок.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE TABLE IF NOT EXISTS `acc_categories` (
            `id` CHAR(36) NOT NULL,
            `direction` ENUM('IN', 'OUT') NOT NULL,
            `name` VARCHAR(120) NOT NULL,
            `base` VARCHAR(32) NOT NULL,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `sort` INT NOT NULL DEFAULT 0,
            `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            `updated_at` DATETIME(3) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE INDEX `acc_categories_direction_name_key` (`direction`, `name`),
            INDEX `acc_categories_direction_base_idx` (`direction`, `base`)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        if (! Schema::hasColumn('acc_transactions', 'category_id')) {
            DB::statement('ALTER TABLE `acc_transactions` ADD COLUMN `category_id` CHAR(36) NULL AFTER `category`');
        }
        $fk = DB::selectOne("SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'acc_transactions' AND CONSTRAINT_NAME = 'acc_transactions_category_id_fkey'");
        if ((int) $fk->n === 0) {
            DB::statement('ALTER TABLE `acc_transactions` ADD CONSTRAINT `acc_transactions_category_id_fkey` FOREIGN KEY (`category_id`) REFERENCES `acc_categories`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE');
        }
    }

    public function down(): void
    {
        $fk = DB::selectOne("SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'acc_transactions' AND CONSTRAINT_NAME = 'acc_transactions_category_id_fkey'");
        if ((int) $fk->n > 0) {
            DB::statement('ALTER TABLE `acc_transactions` DROP FOREIGN KEY `acc_transactions_category_id_fkey`');
        }
        if (Schema::hasColumn('acc_transactions', 'category_id')) {
            DB::statement('ALTER TABLE `acc_transactions` DROP COLUMN `category_id`');
        }
        DB::statement('DROP TABLE IF EXISTS `acc_categories`');
    }
};

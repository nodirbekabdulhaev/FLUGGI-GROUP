<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Бухгалтерия: загрузка банковской выписки.
 * acc_transactions.source — откуда операция (BANK_IMPORT — из выписки), external_ref — отпечаток строки выписки
 * (номер документа, дата, сумма, направление), уникальный в пределах счёта: повторная загрузка
 * пересекающихся периодов не задваивает операции. acc_categories.keywords — слова для автоподбора статьи.
 */
return new class extends Migration
{
    private function hasIndex(string $table, string $name): bool
    {
        return (int) DB::selectOne('SELECT COUNT(*) AS n FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?', [$table, $name])->n > 0;
    }

    public function up(): void
    {
        if (! Schema::hasColumn('acc_transactions', 'source')) {
            DB::statement('ALTER TABLE `acc_transactions` ADD COLUMN `source` VARCHAR(16) NULL AFTER `transfer_group`');
        }
        if (! Schema::hasColumn('acc_transactions', 'external_ref')) {
            DB::statement('ALTER TABLE `acc_transactions` ADD COLUMN `external_ref` VARCHAR(64) NULL AFTER `source`');
        }
        if (! $this->hasIndex('acc_transactions', 'acc_transactions_account_external_ref_key')) {
            DB::statement('ALTER TABLE `acc_transactions` ADD UNIQUE INDEX `acc_transactions_account_external_ref_key` (`account_id`, `external_ref`)');
        }
        if (! Schema::hasColumn('acc_categories', 'keywords')) {
            DB::statement('ALTER TABLE `acc_categories` ADD COLUMN `keywords` VARCHAR(500) NULL AFTER `base`');
        }
    }

    public function down(): void
    {
        if ($this->hasIndex('acc_transactions', 'acc_transactions_account_external_ref_key')) {
            DB::statement('ALTER TABLE `acc_transactions` DROP INDEX `acc_transactions_account_external_ref_key`');
        }
        foreach (['external_ref', 'source'] as $col) {
            if (Schema::hasColumn('acc_transactions', $col)) {
                DB::statement("ALTER TABLE `acc_transactions` DROP COLUMN `$col`");
            }
        }
        if (Schema::hasColumn('acc_categories', 'keywords')) {
            DB::statement('ALTER TABLE `acc_categories` DROP COLUMN `keywords`');
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Платёж по кредиту ↔ операция из выписки/ручного ввода: оплата отмечается сама. Повторный запуск безопасен. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('acc_loan_payments') || Schema::hasColumn('acc_loan_payments', 'transaction_id')) {
            return;
        }
        DB::statement('ALTER TABLE `acc_loan_payments` ADD COLUMN `transaction_id` CHAR(36) NULL AFTER `todo_id`');
        DB::statement('ALTER TABLE `acc_loan_payments` ADD INDEX `acc_loan_payments_transaction_idx` (`transaction_id`)');
    }

    public function down(): void {}
};

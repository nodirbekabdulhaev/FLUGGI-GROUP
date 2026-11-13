<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Зарплата и комиссия из кассы/с карты: получатель — настоящий сотрудник CRM (users), а не человек из налоговой ведомости.
 * Такие операции хранятся, но в бухгалтерские отчёты (баланс, итоги, зарплатная ведомость) не попадают. Повторный запуск безопасен.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('acc_transactions', 'employee_id')) {
            return;
        }
        DB::statement('ALTER TABLE `acc_transactions` ADD COLUMN `employee_id` CHAR(36) NULL AFTER `person_id`');
        DB::statement('ALTER TABLE `acc_transactions` ADD INDEX `acc_transactions_employee_idx` (`employee_id`)');
        DB::statement('ALTER TABLE `acc_transactions` ADD CONSTRAINT `acc_transactions_employee_id_fkey` FOREIGN KEY (`employee_id`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE');
    }

    public function down(): void {}
};

<?php

use Brick\Math\BigDecimal;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ежемесячный отчёт в налоговую (приложения 4 и 5 к расчёту НДФЛ и социального налога):
 * 1) acc_people — данные для отчёта: ФИО латиницей как в паспорте, тип лица (резидент/нерезидент),
 *    вид договора, ставка (доля ставки), тип рабочего места, льготы (JSON: [{code, percent|amount}];
 *    NULL — льгота компании из настроек, [] — без льгот);
 * 2) acc_person_salaries — история оклада: сумма действует с месяца effective_from (YYYY-MM) и далее,
 *    пока не изменена; изменение в месяце не трогает прошлые месяцы;
 * 3) настройки бухгалтерии: прежняя «ставка НДФЛ» (эффективная, 7,5 %) раскладывается на ставку НДФЛ
 *    с облагаемой части (12 %) и льготу IT Park (код 103848, 37,5 % дохода не облагается) — сумма налога та же.
 * Повторный запуск безопасен.
 */
return new class extends Migration
{
    public function up(): void
    {
        $charset = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';

        $columns = [
            'passport_name' => 'VARCHAR(191) NULL AFTER `full_name`',
            'resident_type' => 'TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER `position`',
            'contract_type' => 'TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER `resident_type`',
            'work_rate' => 'DECIMAL(4, 2) NOT NULL DEFAULT 1.00 AFTER `contract_type`',
            'workplace_type' => 'TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER `work_rate`',
            'benefits' => 'JSON NULL AFTER `workplace_type`',
        ];
        foreach ($columns as $name => $def) {
            if (! Schema::hasColumn('acc_people', $name)) {
                DB::statement("ALTER TABLE `acc_people` ADD COLUMN `$name` $def");
            }
        }

        if (! Schema::hasTable('acc_person_salaries')) {
            DB::statement("CREATE TABLE `acc_person_salaries` (
                `id` CHAR(36) NOT NULL,
                `person_id` CHAR(36) NOT NULL,
                `effective_from` CHAR(7) NOT NULL,
                `amount` DECIMAL(18, 2) NOT NULL,
                `created_by_id` CHAR(36) NULL,
                `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                `updated_at` DATETIME(3) NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE INDEX `acc_person_salaries_person_from_key` (`person_id`, `effective_from`),
                CONSTRAINT `acc_person_salaries_person_id_fkey` FOREIGN KEY (`person_id`) REFERENCES `acc_people`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT `acc_person_salaries_created_by_id_fkey` FOREIGN KEY (`created_by_id`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB $charset");
        }

        // Сохранённые ставки: эффективная ставка НДФЛ → ставка с облагаемой части + льгота (один раз)
        $row = DB::table('settings')->where('key', 'accounting')->first();
        $value = $row ? json_decode((string) $row->value, true) : null;
        if (is_array($value) && ! array_key_exists('ndflBaseRate', $value)) {
            $old = isset($value['ndflRate']) ? (string) $value['ndflRate'] : '7.5';
            if (BigDecimal::of($old)->isEqualTo('7.5')) {
                $value += ['ndflBaseRate' => '12', 'benefitCode' => '103848', 'benefitPercent' => '37.5'];
            } else {
                // Своя ставка без льготы — та же сумма налога: ставка применяется ко всему доходу
                $value += ['ndflBaseRate' => $old, 'benefitCode' => '', 'benefitPercent' => '0'];
            }
            unset($value['ndflRate']);
            DB::table('settings')->where('key', 'accounting')->update(['value' => json_encode($value, JSON_UNESCAPED_UNICODE)]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_person_salaries');
        foreach (['benefits', 'workplace_type', 'work_rate', 'contract_type', 'resident_type', 'passport_name'] as $c) {
            if (Schema::hasColumn('acc_people', $c)) {
                DB::statement("ALTER TABLE `acc_people` DROP COLUMN `$c`");
            }
        }
    }
};

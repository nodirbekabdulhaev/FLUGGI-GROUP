<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Карточка кандидата из игровой формы сайта: анкета, результаты тестов и оценка. Повторный запуск безопасен. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vacancy_applications') || Schema::hasColumn('vacancy_applications', 'profile')) {
            return;
        }
        DB::statement('ALTER TABLE `vacancy_applications`
            ADD COLUMN `profile` JSON NULL AFTER `utm`,
            ADD COLUMN `assessment` JSON NULL AFTER `profile`,
            ADD COLUMN `score` TINYINT UNSIGNED NULL AFTER `assessment`,
            ADD COLUMN `score_level` VARCHAR(12) NULL AFTER `score`,
            ADD COLUMN `score_parts` JSON NULL AFTER `score_level`');
    }

    public function down(): void {}
};

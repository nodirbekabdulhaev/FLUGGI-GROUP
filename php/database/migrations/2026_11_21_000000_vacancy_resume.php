<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Резюме кандидата с сайта: страница HTML и подписанная ссылка на неё. Повторный запуск безопасен. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vacancy_applications') || Schema::hasColumn('vacancy_applications', 'resume_html')) {
            return;
        }
        DB::statement('ALTER TABLE `vacancy_applications` ADD COLUMN `resume_html` MEDIUMTEXT NULL AFTER `score_parts`, ADD COLUMN `resume_url` VARCHAR(1000) NULL AFTER `resume_html`');
    }

    public function down(): void {}
};

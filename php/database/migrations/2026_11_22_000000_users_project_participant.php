<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** users.project_participant — «Участвует в проектах»: сотрудника можно добавлять в команду проекта и назначать исполнителем. У существующих включено. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'project_participant')) {
            DB::statement('ALTER TABLE `users` ADD COLUMN `project_participant` BOOLEAN NOT NULL DEFAULT 1 AFTER `meeting_participant`');
        }
    }

    public function down(): void {}
};

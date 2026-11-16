<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «Поставить задачу» из карточек: дело связывается с идеей SMM (smm_idea_id), а для блока
 * «Задачи» в карточках лида, сделки, клиента и идеи — индексы по связям.
 * Задачи по проекту — обычные задачи проекта (tasks), отдельная связь дела с проектом не нужна.
 */
return new class extends Migration
{
    private function hasIndex(string $table, string $index): bool
    {
        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())->where('table_name', $table)->where('index_name', $index)
            ->exists();
    }

    public function up(): void
    {
        if (! Schema::hasColumn('todos', 'smm_idea_id')) {
            DB::statement('ALTER TABLE `todos` ADD COLUMN `smm_idea_id` CHAR(36) NULL AFTER `lead_id`, ADD INDEX `todos_smm_idea_id_idx` (`smm_idea_id`), ADD CONSTRAINT `todos_smm_idea_id_fkey` FOREIGN KEY (`smm_idea_id`) REFERENCES `smm_ideas`(`id`) ON DELETE SET NULL ON UPDATE CASCADE');
        }
        foreach (['lead_id', 'deal_id', 'client_id'] as $column) {
            $index = 'todos_'.$column.'_idx';
            if (! $this->hasIndex('todos', $index)) {
                DB::statement("ALTER TABLE `todos` ADD INDEX `$index` (`$column`)");
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('todos', 'smm_idea_id')) {
            DB::statement('ALTER TABLE `todos` DROP FOREIGN KEY `todos_smm_idea_id_fkey`');
            DB::statement('ALTER TABLE `todos` DROP COLUMN `smm_idea_id`');
        }
        foreach (['lead_id', 'deal_id', 'client_id'] as $column) {
            $index = 'todos_'.$column.'_idx';
            if ($this->hasIndex('todos', $index)) {
                DB::statement("ALTER TABLE `todos` DROP INDEX `$index`");
            }
        }
    }
};

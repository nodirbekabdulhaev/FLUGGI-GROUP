<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Видеограф идеи ролика (smm_ideas.videographer_id): выбирают автор/ответственный, проект-менеджер или CEO;
 * обязателен при переходе в «Съёмка». Опубликованный ролик засчитывается в план роликов и видеографу.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('smm_ideas', 'videographer_id')) {
            DB::statement('ALTER TABLE `smm_ideas` ADD COLUMN `videographer_id` CHAR(36) NULL AFTER `assignee_id`,
                ADD INDEX `smm_ideas_videographer_status_idx` (`videographer_id`, `status`, `published_at`),
                ADD CONSTRAINT `smm_ideas_videographer_id_fkey` FOREIGN KEY (`videographer_id`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE');
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('smm_ideas', 'videographer_id')) {
            DB::statement('ALTER TABLE `smm_ideas` DROP FOREIGN KEY `smm_ideas_videographer_id_fkey`');
            DB::statement('ALTER TABLE `smm_ideas` DROP INDEX `smm_ideas_videographer_status_idx`, DROP COLUMN `videographer_id`');
        }
    }
};

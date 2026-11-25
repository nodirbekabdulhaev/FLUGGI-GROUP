<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Встречи с несколькими участниками и слоты.
 * users.meeting_participant — «Участвует во встречах»; users.meeting_slots — личная сетка слотов
 * (JSON: days [1..7, ISO, 1 = пн], from/to «ЧЧ:ММ» по Ташкенту, slot — длина слота в минутах).
 * meeting_participants — участники встречи; meetings.manager_id остаётся ответственным за результат
 * и всегда входит в участники. Прежние встречи получают участника = manager_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'meeting_participant')) {
            DB::statement('ALTER TABLE `users` ADD COLUMN `meeting_participant` BOOLEAN NOT NULL DEFAULT 0 AFTER `team_id`');
        }
        if (! Schema::hasColumn('users', 'meeting_slots')) {
            DB::statement('ALTER TABLE `users` ADD COLUMN `meeting_slots` JSON NULL AFTER `meeting_participant`');
        }
        if (! Schema::hasTable('meeting_participants')) {
            DB::statement('CREATE TABLE `meeting_participants` (
                `id` CHAR(36) NOT NULL,
                `meeting_id` CHAR(36) NOT NULL,
                `user_id` CHAR(36) NOT NULL,
                `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                UNIQUE INDEX `meeting_participants_meeting_id_user_id_key`(`meeting_id`, `user_id`),
                INDEX `meeting_participants_user_id_idx`(`user_id`),
                PRIMARY KEY (`id`)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        }
        // Прежние встречи: участник — ответственный менеджер (повторный запуск ничего не задваивает)
        DB::statement('INSERT IGNORE INTO `meeting_participants` (`id`, `meeting_id`, `user_id`, `created_at`)
            SELECT UUID(), m.`id`, m.`manager_id`, m.`created_at` FROM `meetings` m
            WHERE NOT EXISTS (SELECT 1 FROM `meeting_participants` p WHERE p.`meeting_id` = m.`id` AND p.`user_id` = m.`manager_id`)');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS `meeting_participants`');
        foreach (['meeting_slots', 'meeting_participant'] as $col) {
            if (Schema::hasColumn('users', $col)) {
                DB::statement("ALTER TABLE `users` DROP COLUMN `$col`");
            }
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Автопубликация SMM в несколько мест: Instagram и Telegram-каналы.
 * 1) smm_telegram_channels — каналы для публикаций (бот — администратор с правом публиковать); удаление — мягкое
 *    (deleted_at), чтобы у опубликованных записей осталось название канала.
 * 2) smm_publication_targets — куда публикуется идея: у каждого места свой статус, ошибка, id сообщения/поста,
 *    ссылка, попытки; target_key ('instagram' / 'tg:<id канала>') — одно место один раз в публикации.
 *    Состояние Instagram (контейнер, проверка статуса, id поста) переносится сюда; столбцы
 *    smm_publications с прежним состоянием остаются (не используются), status — общий статус публикации.
 * 3) smm_publications.tg_media_* — необязательная «версия для Telegram» (видео до 50 МБ — лимит Bot API).
 * 4) Перенос: у каждой публикации без мест — место INSTAGRAM с её прежним состоянием (повторный запуск ничего не дублирует).
 */
return new class extends Migration
{
    public function up(): void
    {
        $charset = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';

        if (! Schema::hasTable('smm_telegram_channels')) {
            DB::statement("CREATE TABLE `smm_telegram_channels` (
                `id` CHAR(36) NOT NULL,
                `chat_id` VARCHAR(32) NOT NULL,
                `title` VARCHAR(255) NOT NULL,
                `username` VARCHAR(64) NULL,
                `can_post` BOOLEAN NOT NULL DEFAULT FALSE,
                `checked_at` DATETIME(3) NULL,
                `error` TEXT NULL,
                `created_by_id` CHAR(36) NULL,
                `deleted_at` DATETIME(3) NULL,
                `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                `updated_at` DATETIME(3) NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE INDEX `smm_telegram_channels_chat_key` (`chat_id`),
                CONSTRAINT `smm_telegram_channels_created_by_id_fkey` FOREIGN KEY (`created_by_id`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB $charset");
        }

        if (! Schema::hasTable('smm_publication_targets')) {
            DB::statement("CREATE TABLE `smm_publication_targets` (
                `id` CHAR(36) NOT NULL,
                `publication_id` CHAR(36) NOT NULL,
                `kind` ENUM('INSTAGRAM', 'TELEGRAM') NOT NULL,
                `channel_id` CHAR(36) NULL,
                `target_key` VARCHAR(64) NOT NULL,
                `status` ENUM('DRAFT', 'SCHEDULED', 'PUBLISHING', 'PUBLISHED', 'FAILED') NOT NULL DEFAULT 'DRAFT',
                `scheduled_at` DATETIME(3) NULL,
                `error` TEXT NULL,
                `external_id` VARCHAR(64) NULL,
                `permalink` VARCHAR(500) NULL,
                `container_id` VARCHAR(64) NULL,
                `sent_parts` INTEGER NOT NULL DEFAULT 0,
                `attempts` INTEGER NOT NULL DEFAULT 0,
                `next_check_at` DATETIME(3) NULL,
                `started_at` DATETIME(3) NULL,
                `published_at` DATETIME(3) NULL,
                `failed_at` DATETIME(3) NULL,
                `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                `updated_at` DATETIME(3) NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE INDEX `smm_publication_targets_key` (`publication_id`, `target_key`),
                INDEX `smm_publication_targets_due_idx` (`status`, `scheduled_at`),
                INDEX `smm_publication_targets_channel_idx` (`channel_id`),
                CONSTRAINT `smm_publication_targets_publication_id_fkey` FOREIGN KEY (`publication_id`) REFERENCES `smm_publications`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT `smm_publication_targets_channel_id_fkey` FOREIGN KEY (`channel_id`) REFERENCES `smm_telegram_channels`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE
            ) ENGINE=InnoDB $charset");
        }

        $columns = [
            'tg_media_path' => 'VARCHAR(500) NULL',
            'tg_media_name' => 'VARCHAR(255) NULL',
            'tg_media_mime' => 'VARCHAR(100) NULL',
            'tg_media_size' => 'BIGINT NULL',
        ];
        foreach ($columns as $name => $type) {
            if (! Schema::hasColumn('smm_publications', $name)) {
                DB::statement("ALTER TABLE `smm_publications` ADD COLUMN `$name` $type");
            }
        }

        DB::statement("INSERT INTO `smm_publication_targets` (`id`, `publication_id`, `kind`, `channel_id`, `target_key`, `status`, `scheduled_at`,
                `error`, `external_id`, `permalink`, `container_id`, `attempts`, `next_check_at`, `started_at`, `published_at`, `failed_at`, `created_at`, `updated_at`)
            SELECT UUID(), p.`id`, 'INSTAGRAM', NULL, 'instagram', p.`status`, p.`scheduled_at`,
                p.`error`, p.`ig_media_id`, p.`permalink`, p.`container_id`, p.`attempts`, p.`next_check_at`, p.`publishing_started_at`, p.`published_at`, p.`failed_at`,
                p.`created_at`, p.`updated_at`
            FROM `smm_publications` p
            WHERE NOT EXISTS (SELECT 1 FROM `smm_publication_targets` t WHERE t.`publication_id` = p.`id`)");
    }

    public function down(): void
    {
        Schema::dropIfExists('smm_publication_targets');
        Schema::dropIfExists('smm_telegram_channels');
        foreach (['tg_media_path', 'tg_media_name', 'tg_media_mime', 'tg_media_size'] as $name) {
            if (Schema::hasColumn('smm_publications', $name)) {
                DB::statement("ALTER TABLE `smm_publications` DROP COLUMN `$name`");
            }
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1) Web Push: подписки браузеров (push_subscriptions) и канал PUSH в очереди notification_deliveries.
 *    Адрес подписки длинный, поэтому уникальность — по endpoint_hash (sha256 адреса).
 * 2) Автопубликация роликов SMM в Instagram (smm_publications): одна публикация на идею,
 *    файл — в storage/app/smm-media, после публикации удаляется, в базе остаются имя, размер, ссылка и id поста.
 */
return new class extends Migration
{
    public function up(): void
    {
        $charset = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';

        if (! Schema::hasTable('push_subscriptions')) {
            DB::statement("CREATE TABLE `push_subscriptions` (
                `id` CHAR(36) NOT NULL,
                `user_id` CHAR(36) NOT NULL,
                `endpoint` TEXT NOT NULL,
                `endpoint_hash` CHAR(64) NOT NULL,
                `p256dh` VARCHAR(200) NOT NULL,
                `auth` VARCHAR(64) NOT NULL,
                `user_agent` VARCHAR(500) NULL,
                `last_success_at` DATETIME(3) NULL,
                `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                `updated_at` DATETIME(3) NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE INDEX `push_subscriptions_endpoint_hash_key` (`endpoint_hash`),
                INDEX `push_subscriptions_user_idx` (`user_id`),
                CONSTRAINT `push_subscriptions_user_id_fkey` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB $charset");
        }

        $type = DB::selectOne("SELECT COLUMN_TYPE AS t FROM information_schema.columns WHERE table_schema = DATABASE()
            AND table_name = 'notification_deliveries' AND column_name = 'channel'")?->t;
        if ($type !== null && ! str_contains($type, "'PUSH'")) {
            DB::statement("ALTER TABLE `notification_deliveries` MODIFY `channel` ENUM('IN_APP', 'TELEGRAM', 'PUSH') NOT NULL");
        }

        if (! Schema::hasTable('smm_publications')) {
            DB::statement("CREATE TABLE `smm_publications` (
                `id` CHAR(36) NOT NULL,
                `idea_id` CHAR(36) NOT NULL,
                `kind` ENUM('REELS', 'IMAGE') NOT NULL DEFAULT 'REELS',
                `status` ENUM('DRAFT', 'SCHEDULED', 'PUBLISHING', 'PUBLISHED', 'FAILED') NOT NULL DEFAULT 'DRAFT',
                `caption` TEXT NULL,
                `share_to_feed` BOOLEAN NOT NULL DEFAULT TRUE,
                `thumb_offset_ms` INTEGER NULL,
                `scheduled_at` DATETIME(3) NULL,
                `media_path` VARCHAR(500) NULL,
                `media_name` VARCHAR(255) NULL,
                `media_mime` VARCHAR(100) NULL,
                `media_size` BIGINT NULL,
                `cover_path` VARCHAR(500) NULL,
                `cover_name` VARCHAR(255) NULL,
                `cover_size` BIGINT NULL,
                `file_deleted_at` DATETIME(3) NULL,
                `container_id` VARCHAR(64) NULL,
                `ig_media_id` VARCHAR(64) NULL,
                `permalink` VARCHAR(500) NULL,
                `error` TEXT NULL,
                `attempts` INTEGER NOT NULL DEFAULT 0,
                `next_check_at` DATETIME(3) NULL,
                `publishing_started_at` DATETIME(3) NULL,
                `published_at` DATETIME(3) NULL,
                `failed_at` DATETIME(3) NULL,
                `created_by_id` CHAR(36) NULL,
                `updated_by_id` CHAR(36) NULL,
                `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                `updated_at` DATETIME(3) NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE INDEX `smm_publications_idea_key` (`idea_id`),
                INDEX `smm_publications_status_idx` (`status`, `scheduled_at`),
                CONSTRAINT `smm_publications_idea_id_fkey` FOREIGN KEY (`idea_id`) REFERENCES `smm_ideas`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
                CONSTRAINT `smm_publications_created_by_id_fkey` FOREIGN KEY (`created_by_id`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT `smm_publications_updated_by_id_fkey` FOREIGN KEY (`updated_by_id`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB $charset");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('smm_publications');
        DB::statement("DELETE FROM `notification_deliveries` WHERE `channel` = 'PUSH'");
        DB::statement("ALTER TABLE `notification_deliveries` MODIFY `channel` ENUM('IN_APP', 'TELEGRAM') NOT NULL");
        Schema::dropIfExists('push_subscriptions');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Вакансии компании: создаются в CRM и сразу отдаются сайту (публичная лента /hooks/website-vacancies). Повторный запуск безопасен. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('vacancy_postings')) {
            return;
        }
        DB::statement("CREATE TABLE `vacancy_postings` (
            `id` CHAR(36) NOT NULL,
            `slug` VARCHAR(120) NOT NULL,
            `title_ru` VARCHAR(191) NOT NULL,
            `title_uz` VARCHAR(191) NULL,
            `title_en` VARCHAR(191) NULL,
            `description_ru` TEXT NULL,
            `description_uz` TEXT NULL,
            `description_en` TEXT NULL,
            `city` VARCHAR(100) NULL,
            `employment` VARCHAR(20) NOT NULL DEFAULT 'FULL',
            `salary` VARCHAR(100) NULL,
            `status` ENUM('DRAFT', 'PUBLISHED', 'CLOSED') NOT NULL DEFAULT 'DRAFT',
            `published_at` DATETIME(3) NULL,
            `sort` INT NOT NULL DEFAULT 0,
            `created_by_id` CHAR(36) NULL,
            `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            `updated_at` DATETIME(3) NOT NULL,
            `deleted_at` DATETIME(3) NULL,
            PRIMARY KEY (`id`),
            UNIQUE INDEX `vacancy_postings_slug_key` (`slug`),
            INDEX `vacancy_postings_status_idx` (`status`, `sort`)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }

    public function down(): void {}
};

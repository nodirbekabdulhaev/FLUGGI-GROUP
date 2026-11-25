<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Отклики на вакансии с сайта (раздел «Вакансии»): отдельно от лидов продаж. Повторный запуск безопасен. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vacancy_applications')) {
            DB::statement("CREATE TABLE `vacancy_applications` (
                `id` CHAR(36) NOT NULL,
                `number` INTEGER NOT NULL AUTO_INCREMENT,
                `external_id` VARCHAR(191) NOT NULL,
                `name` VARCHAR(191) NOT NULL,
                `phone` VARCHAR(32) NOT NULL,
                `email` VARCHAR(191) NULL,
                `message` TEXT NULL,
                `vacancy_slug` VARCHAR(191) NULL,
                `vacancy_name` VARCHAR(191) NULL,
                `page_url` TEXT NULL,
                `locale` VARCHAR(5) NULL,
                `utm` JSON NULL,
                `site_created_at` VARCHAR(40) NULL,
                `status` ENUM('NEW', 'VIEWED', 'INTERVIEW', 'HIRED', 'REJECTED') NOT NULL DEFAULT 'NEW',
                `comment` TEXT NULL,
                `updated_by_id` CHAR(36) NULL,
                `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                `updated_at` DATETIME(3) NOT NULL,
                `deleted_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                UNIQUE INDEX `vacancy_applications_number_key` (`number`),
                UNIQUE INDEX `vacancy_applications_external_id_key` (`external_id`),
                INDEX `vacancy_applications_status_idx` (`status`, `created_at`)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        }
        if (Schema::hasTable('website_leads') && ! Schema::hasColumn('website_leads', 'vacancy_id')) {
            DB::statement('ALTER TABLE `website_leads` ADD COLUMN `vacancy_id` CHAR(36) NULL AFTER `lead_id`');
        }
    }

    public function down(): void {}
};

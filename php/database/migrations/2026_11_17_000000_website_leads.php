<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Заявки с сайта fluggi.uz: external_id — ключ защиты от дублей при повторной отправке. Повторный запуск безопасен. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('website_leads')) {
            return;
        }
        DB::statement('CREATE TABLE `website_leads` (
            `id` CHAR(36) NOT NULL,
            `external_id` VARCHAR(191) NOT NULL,
            `lead_id` CHAR(36) NULL,
            `type` VARCHAR(32) NOT NULL,
            `payload` JSON NOT NULL,
            `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            `updated_at` DATETIME(3) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE INDEX `website_leads_external_id_key` (`external_id`),
            CONSTRAINT `website_leads_lead_id_fkey` FOREIGN KEY (`lead_id`) REFERENCES `leads`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    }

    public function down(): void {}
};

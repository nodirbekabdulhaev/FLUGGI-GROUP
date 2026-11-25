<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Доход партнёра (второго CEO): заявки на выплату его доли чистой прибыли проекта.
 * Партнёр или CEO создают заявку (REQUESTED), CEO, который сам не партнёр, утверждает (APPROVED)
 * или отклоняет с причиной (REJECTED). Записи не удаляются. Партнёр и процент — в настройках «finance».
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('partner_payouts')) {
            DB::statement("CREATE TABLE `partner_payouts` (
                `id` CHAR(36) NOT NULL,
                `project_id` CHAR(36) NOT NULL,
                `partner_id` CHAR(36) NOT NULL,
                `amount_uzs` DECIMAL(18, 2) NOT NULL,
                `status` ENUM('REQUESTED', 'APPROVED', 'REJECTED') NOT NULL DEFAULT 'REQUESTED',
                `comment` TEXT NULL,
                `requested_by` CHAR(36) NOT NULL,
                `decided_by` CHAR(36) NULL,
                `decided_at` DATETIME(3) NULL,
                `reject_reason` TEXT NULL,
                `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                `updated_at` DATETIME(3) NOT NULL,
                PRIMARY KEY (`id`),
                INDEX `partner_payouts_project_status_idx` (`project_id`, `status`),
                INDEX `partner_payouts_partner_status_idx` (`partner_id`, `status`),
                CONSTRAINT `partner_payouts_project_id_fkey` FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
                CONSTRAINT `partner_payouts_partner_id_fkey` FOREIGN KEY (`partner_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
                CONSTRAINT `partner_payouts_requested_by_fkey` FOREIGN KEY (`requested_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
                CONSTRAINT `partner_payouts_decided_by_fkey` FOREIGN KEY (`decided_by`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_payouts');
    }
};

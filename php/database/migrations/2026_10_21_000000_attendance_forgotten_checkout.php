<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Забытая отметка ухода: cron закрывает день по концу графика (attendance.auto_checkout = 1) и
 * отправляет его на утверждение CEO (approval_status: PENDING → APPROVED | REJECTED).
 * notification_deliveries.buttons — inline-кнопки сообщения Telegram (callback_data).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('attendance', 'auto_checkout')) {
            DB::statement("ALTER TABLE `attendance`
                ADD COLUMN `auto_checkout` BOOLEAN NOT NULL DEFAULT 0 AFTER `work_minutes`,
                ADD COLUMN `approval_status` ENUM('PENDING', 'APPROVED', 'REJECTED') NULL AFTER `auto_checkout`,
                ADD COLUMN `approved_by_id` CHAR(36) NULL AFTER `approval_status`,
                ADD COLUMN `approved_at` DATETIME(3) NULL AFTER `approved_by_id`,
                ADD COLUMN `reject_reason` TEXT NULL AFTER `approved_at`,
                ADD INDEX `attendance_approval_status_date_idx` (`approval_status`, `date`)");
        }
        if (! Schema::hasColumn('notification_deliveries', 'buttons')) {
            DB::statement('ALTER TABLE `notification_deliveries` ADD COLUMN `buttons` JSON NULL AFTER `link`');
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('notification_deliveries', 'buttons')) {
            DB::statement('ALTER TABLE `notification_deliveries` DROP COLUMN `buttons`');
        }
        if (Schema::hasColumn('attendance', 'auto_checkout')) {
            DB::statement('ALTER TABLE `attendance` DROP INDEX `attendance_approval_status_date_idx`,
                DROP COLUMN `reject_reason`, DROP COLUMN `approved_at`, DROP COLUMN `approved_by_id`, DROP COLUMN `approval_status`, DROP COLUMN `auto_checkout`');
        }
    }
};

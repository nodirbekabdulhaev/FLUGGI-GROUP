<?php

use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * SMM «Финансы»:
 * 1) монтажёр идеи (smm_ideas.editor_id) — ставка за ролик по роли «Монтажёр»;
 * 2) личная ставка за ролик SMM у сотрудника (employees.smm_video_rate, NULL — ставка из настроек SMM);
 * 3) начисления за опубликованные ролики (smm_video_accruals): сумма — по ставке на момент начисления,
 *    при снятии публикации / смене видеографа или монтажёра — сторно (REVERSED) с причиной;
 *    активное начисление одно на (идея, сотрудник, роль): active = 1, у сторно — NULL (уникальный индекс не мешает);
 * 4) строка зарплаты: «За ролики SMM» (payroll_entries.smm_video_pay, входит в начислено) и число роликов;
 * 5) прочие расходы SMM за месяц (smm_expenses), при желании — с операцией в Бухгалтерии;
 * 6) право smm.finance (по умолчанию — только CEO).
 * Повторный запуск безопасен.
 */
return new class extends Migration
{
    public function up(): void
    {
        $charset = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';

        if (! Schema::hasColumn('smm_ideas', 'editor_id')) {
            DB::statement('ALTER TABLE `smm_ideas` ADD COLUMN `editor_id` CHAR(36) NULL AFTER `videographer_id`,
                ADD INDEX `smm_ideas_editor_idx` (`editor_id`),
                ADD CONSTRAINT `smm_ideas_editor_id_fkey` FOREIGN KEY (`editor_id`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE');
        }
        if (! Schema::hasColumn('employees', 'smm_video_rate')) {
            DB::statement('ALTER TABLE `employees` ADD COLUMN `smm_video_rate` DECIMAL(18, 2) NULL AFTER `video_target`');
        }
        if (! Schema::hasColumn('payroll_entries', 'smm_video_pay')) {
            DB::statement('ALTER TABLE `payroll_entries` ADD COLUMN `smm_video_pay` DECIMAL(18, 2) NOT NULL DEFAULT 0 AFTER `piece_rate`,
                ADD COLUMN `smm_video_count` INTEGER NULL AFTER `smm_video_pay`');
        }
        if (! Schema::hasTable('smm_video_accruals')) {
            DB::statement("CREATE TABLE `smm_video_accruals` (
                `id` CHAR(36) NOT NULL,
                `idea_id` CHAR(36) NOT NULL,
                `user_id` CHAR(36) NOT NULL,
                `role` ENUM('VIDEOGRAPHER', 'MOBILOGRAPHER', 'EDITOR') NOT NULL,
                `amount_uzs` DECIMAL(18, 2) NOT NULL,
                `period` CHAR(7) NOT NULL,
                `status` ENUM('ACCRUED', 'REVERSED') NOT NULL DEFAULT 'ACCRUED',
                `active` TINYINT(1) NULL DEFAULT 1,
                `created_by_id` CHAR(36) NULL,
                `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                `reversed_at` DATETIME(3) NULL,
                `reversed_by_id` CHAR(36) NULL,
                `reverse_reason` VARCHAR(64) NULL,
                PRIMARY KEY (`id`),
                UNIQUE INDEX `smm_video_accruals_active_key` (`idea_id`, `user_id`, `role`, `active`),
                INDEX `smm_video_accruals_period_idx` (`period`, `status`),
                INDEX `smm_video_accruals_user_period_idx` (`user_id`, `period`),
                CONSTRAINT `smm_video_accruals_idea_id_fkey` FOREIGN KEY (`idea_id`) REFERENCES `smm_ideas`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
                CONSTRAINT `smm_video_accruals_user_id_fkey` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE
            ) ENGINE=InnoDB $charset");
        }
        if (! Schema::hasTable('smm_expenses')) {
            DB::statement("CREATE TABLE `smm_expenses` (
                `id` CHAR(36) NOT NULL,
                `date` DATE NOT NULL,
                `category` VARCHAR(100) NOT NULL,
                `amount_uzs` DECIMAL(18, 2) NOT NULL,
                `comment` TEXT NULL,
                `acc_transaction_id` CHAR(36) NULL,
                `created_by_id` CHAR(36) NOT NULL,
                `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                `updated_at` DATETIME(3) NOT NULL,
                `deleted_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                INDEX `smm_expenses_date_idx` (`date`),
                CONSTRAINT `smm_expenses_acc_transaction_id_fkey` FOREIGN KEY (`acc_transaction_id`) REFERENCES `acc_transactions`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT `smm_expenses_created_by_id_fkey` FOREIGN KEY (`created_by_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE
            ) ENGINE=InnoDB $charset");
        }

        $this->grantPermission();
    }

    /** Право smm.finance — роли CEO (RolesSeeder ставит права по умолчанию только при первой установке). */
    private function grantPermission(): void
    {
        DB::table('permissions')->insertOrIgnore([
            'id' => (string) Str::uuid(), 'code' => 'smm.finance', 'module' => 'smm', 'description' => Permissions::ALL['smm.finance'],
        ]);
        $permissionId = DB::table('permissions')->where('code', 'smm.finance')->value('id');
        $roleId = DB::table('roles')->where('code', 'CEO')->value('id');
        // Нет роли или у неё ещё нет прав — это первая установка: права поставит RolesSeeder
        if (! $roleId || ! $permissionId || ! DB::table('role_permissions')->where('role_id', $roleId)->exists()) {
            return;
        }
        DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId, 'scope' => 'ALL']);
    }

    public function down(): void
    {
        Schema::dropIfExists('smm_expenses');
        Schema::dropIfExists('smm_video_accruals');
        if (Schema::hasColumn('payroll_entries', 'smm_video_pay')) {
            DB::statement('ALTER TABLE `payroll_entries` DROP COLUMN `smm_video_pay`, DROP COLUMN `smm_video_count`');
        }
        if (Schema::hasColumn('employees', 'smm_video_rate')) {
            DB::statement('ALTER TABLE `employees` DROP COLUMN `smm_video_rate`');
        }
        if (Schema::hasColumn('smm_ideas', 'editor_id')) {
            DB::statement('ALTER TABLE `smm_ideas` DROP FOREIGN KEY `smm_ideas_editor_id_fkey`');
            DB::statement('ALTER TABLE `smm_ideas` DROP INDEX `smm_ideas_editor_idx`, DROP COLUMN `editor_id`');
        }
        DB::table('role_permissions')->whereIn('permission_id', DB::table('permissions')->where('code', 'smm.finance')->select('id'))->delete();
        DB::table('permissions')->where('code', 'smm.finance')->delete();
    }
};

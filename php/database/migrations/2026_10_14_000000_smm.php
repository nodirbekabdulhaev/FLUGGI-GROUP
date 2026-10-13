<?php

use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Модуль «SMM» — собственный видеоконтент компании:
 * 1) идеи роликов (Kanban), комментарии и неизменяемая история;
 * 2) план роликов в месяц у сотрудника и факт в строке зарплаты;
 * 3) источник лида «Видео SMM»: ролик в лиде и сделке, комиссия SMM-специалисту с оплат;
 * 4) права smm.* для существующих ролей (на новой установке их ставит RolesSeeder).
 */
return new class extends Migration
{
    public function up(): void
    {
        $charset = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';

        if (! Schema::hasTable('smm_ideas')) {
            DB::statement("CREATE TABLE `smm_ideas` (
                `id` CHAR(36) NOT NULL,
                `number` INTEGER NOT NULL AUTO_INCREMENT,
                `title` VARCHAR(300) NOT NULL,
                `scenario` TEXT NULL,
                `notes` TEXT NULL,
                `status` ENUM('IDEA', 'APPROVED', 'SHOOTING', 'EDITING', 'SCHEDULED', 'PUBLISHED', 'REJECTED') NOT NULL DEFAULT 'IDEA',
                `author_id` CHAR(36) NOT NULL,
                `assignee_id` CHAR(36) NOT NULL,
                `pm_approved_by_id` CHAR(36) NULL,
                `pm_approved_at` DATETIME(3) NULL,
                `ceo_approved_by_id` CHAR(36) NULL,
                `ceo_approved_at` DATETIME(3) NULL,
                `rejected_by_id` CHAR(36) NULL,
                `rejected_at` DATETIME(3) NULL,
                `reject_reason` TEXT NULL,
                `planned_publish_date` DATE NULL,
                `published_at` DATETIME(3) NULL,
                `publish_url` VARCHAR(500) NULL,
                `sort_order` DOUBLE NOT NULL DEFAULT 0,
                `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                `updated_at` DATETIME(3) NOT NULL,
                `deleted_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                UNIQUE INDEX `smm_ideas_number_key` (`number`),
                INDEX `smm_ideas_status_sort_idx` (`status`, `sort_order`),
                INDEX `smm_ideas_assignee_status_idx` (`assignee_id`, `status`, `published_at`),
                INDEX `smm_ideas_author_idx` (`author_id`),
                CONSTRAINT `smm_ideas_author_id_fkey` FOREIGN KEY (`author_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
                CONSTRAINT `smm_ideas_assignee_id_fkey` FOREIGN KEY (`assignee_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE
            ) ENGINE=InnoDB $charset");
        }
        if (! Schema::hasTable('smm_idea_comments')) {
            DB::statement("CREATE TABLE `smm_idea_comments` (
                `id` CHAR(36) NOT NULL,
                `idea_id` CHAR(36) NOT NULL,
                `author_id` CHAR(36) NOT NULL,
                `body` TEXT NOT NULL,
                `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                `updated_at` DATETIME(3) NOT NULL,
                PRIMARY KEY (`id`),
                INDEX `smm_idea_comments_idea_idx` (`idea_id`, `created_at`),
                CONSTRAINT `smm_idea_comments_idea_id_fkey` FOREIGN KEY (`idea_id`) REFERENCES `smm_ideas`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
                CONSTRAINT `smm_idea_comments_author_id_fkey` FOREIGN KEY (`author_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE
            ) ENGINE=InnoDB $charset");
        }
        // История идеи — только добавление (кто, когда, что: старое и новое значение)
        if (! Schema::hasTable('smm_idea_history')) {
            DB::statement("CREATE TABLE `smm_idea_history` (
                `id` CHAR(36) NOT NULL,
                `idea_id` CHAR(36) NOT NULL,
                `user_id` CHAR(36) NULL,
                `action` VARCHAR(32) NOT NULL,
                `changes` JSON NULL,
                `comment` TEXT NULL,
                `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                PRIMARY KEY (`id`),
                INDEX `smm_idea_history_idea_idx` (`idea_id`, `created_at`),
                CONSTRAINT `smm_idea_history_idea_id_fkey` FOREIGN KEY (`idea_id`) REFERENCES `smm_ideas`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE
            ) ENGINE=InnoDB $charset");
        }

        if (! Schema::hasColumn('employees', 'video_target')) {
            DB::statement('ALTER TABLE `employees` ADD COLUMN `video_target` INTEGER NULL AFTER `kpi_bonus_target`');
        }
        if (! Schema::hasColumn('payroll_entries', 'videos_published')) {
            DB::statement('ALTER TABLE `payroll_entries` ADD COLUMN `videos_published` INTEGER NULL AFTER `attendance_pct`, ADD COLUMN `videos_target` INTEGER NULL AFTER `videos_published`');
        }
        if (! Schema::hasColumn('leads', 'smm_idea_id')) {
            DB::statement('ALTER TABLE `leads` ADD COLUMN `smm_idea_id` CHAR(36) NULL AFTER `source_id`, ADD INDEX `leads_smm_idea_id_idx` (`smm_idea_id`), ADD CONSTRAINT `leads_smm_idea_id_fkey` FOREIGN KEY (`smm_idea_id`) REFERENCES `smm_ideas`(`id`) ON DELETE SET NULL ON UPDATE CASCADE');
        }
        if (! Schema::hasColumn('deals', 'smm_idea_id')) {
            DB::statement('ALTER TABLE `deals` ADD COLUMN `smm_idea_id` CHAR(36) NULL AFTER `service_id`, ADD INDEX `deals_smm_idea_id_idx` (`smm_idea_id`), ADD CONSTRAINT `deals_smm_idea_id_fkey` FOREIGN KEY (`smm_idea_id`) REFERENCES `smm_ideas`(`id`) ON DELETE SET NULL ON UPDATE CASCADE');
        }
        DB::statement("ALTER TABLE `commissions` MODIFY `role` ENUM('MANAGER', 'ROP', 'SMM') NOT NULL");
        DB::statement("ALTER TABLE `commission_rules` MODIFY `applies_to` ENUM('MANAGER', 'ROP', 'SMM') NOT NULL");

        $this->grantPermissions();
    }

    /** Новые права — существующим ролям (RolesSeeder ставит права по умолчанию только при первой установке). */
    private function grantPermissions(): void
    {
        foreach (['smm.read', 'smm.create', 'smm.approve'] as $code) {
            DB::table('permissions')->insertOrIgnore([
                'id' => (string) Str::uuid(), 'code' => $code, 'module' => 'smm', 'description' => Permissions::ALL[$code],
            ]);
        }
        $permissionIds = DB::table('permissions')->whereIn('code', ['smm.read', 'smm.create', 'smm.approve'])->pluck('id', 'code');
        $grants = [
            'CEO' => ['smm.read' => 'ALL', 'smm.create' => 'ALL', 'smm.approve' => 'ALL'],
            'PROJECT_MANAGER' => ['smm.read' => 'ALL', 'smm.create' => 'ALL', 'smm.approve' => 'ALL'],
            'EXECUTOR' => ['smm.read' => 'OWN', 'smm.create' => 'OWN'],
        ];
        foreach ($grants as $roleCode => $map) {
            $roleId = DB::table('roles')->where('code', $roleCode)->value('id');
            // Нет роли или у неё ещё нет прав — это первая установка: права поставит RolesSeeder
            if (! $roleId || ! DB::table('role_permissions')->where('role_id', $roleId)->exists()) {
                continue;
            }
            $rows = [];
            foreach ($map as $code => $scope) {
                $rows[] = ['role_id' => $roleId, 'permission_id' => $permissionIds[$code], 'scope' => $scope];
            }
            DB::table('role_permissions')->insertOrIgnore($rows);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('deals', 'smm_idea_id')) {
            DB::statement('ALTER TABLE `deals` DROP FOREIGN KEY `deals_smm_idea_id_fkey`');
            DB::statement('ALTER TABLE `deals` DROP COLUMN `smm_idea_id`');
        }
        if (Schema::hasColumn('leads', 'smm_idea_id')) {
            DB::statement('ALTER TABLE `leads` DROP FOREIGN KEY `leads_smm_idea_id_fkey`');
            DB::statement('ALTER TABLE `leads` DROP COLUMN `smm_idea_id`');
        }
        if (Schema::hasColumn('payroll_entries', 'videos_published')) {
            DB::statement('ALTER TABLE `payroll_entries` DROP COLUMN `videos_published`, DROP COLUMN `videos_target`');
        }
        if (Schema::hasColumn('employees', 'video_target')) {
            DB::statement('ALTER TABLE `employees` DROP COLUMN `video_target`');
        }
        foreach (['smm_idea_history', 'smm_idea_comments', 'smm_ideas'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};

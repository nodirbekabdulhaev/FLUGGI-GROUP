<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Несколько позиций у одного человека: фотограф, видеограф и мобилограф может быть одним
 * сотрудником, и за каждую позицию ему платят отдельно (строки себестоимости по специальности).
 * project_members.roles и employees.specialties — список позиций (SET), прежние role / specialty
 * остаются основной (первой выбранной) позицией для совместимости.
 */
return new class extends Migration
{
    private const SPECIALTIES = "'SMM', 'DESIGNER', 'VIDEOGRAPHER', 'EDITOR', 'TARGETOLOGIST', 'DEVELOPER', 'PHOTOGRAPHER', 'COPYWRITER', 'MOBILOGRAPHER', 'BRANDFACE'";

    public function up(): void
    {
        $set = self::SPECIALTIES;
        if (! Schema::hasColumn('project_members', 'roles')) {
            DB::statement("ALTER TABLE `project_members` ADD COLUMN `roles` SET($set) NULL AFTER `role`");
        }
        DB::statement('UPDATE `project_members` SET `roles` = `role` WHERE `role` IS NOT NULL AND (`roles` IS NULL OR `roles` = \'\')');

        if (! Schema::hasColumn('employees', 'specialties')) {
            DB::statement("ALTER TABLE `employees` ADD COLUMN `specialties` SET($set) NULL AFTER `specialty`");
        }
        DB::statement('UPDATE `employees` SET `specialties` = `specialty` WHERE `specialty` IS NOT NULL AND (`specialties` IS NULL OR `specialties` = \'\')');
    }

    public function down(): void
    {
        if (Schema::hasColumn('project_members', 'roles')) {
            DB::statement('ALTER TABLE `project_members` DROP COLUMN `roles`');
        }
        if (Schema::hasColumn('employees', 'specialties')) {
            DB::statement('ALTER TABLE `employees` DROP COLUMN `specialties`');
        }
    }
};

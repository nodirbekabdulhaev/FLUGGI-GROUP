-- AlterTable
ALTER TABLE "payroll_entries" ADD COLUMN     "piece_rate" DECIMAL(18,2) NOT NULL DEFAULT 0;

-- Посещаемость — только у менеджеров и РОП: исполнитель и проект-менеджер её не видят
DELETE FROM "role_permissions" rp
USING "roles" r, "permissions" p
WHERE rp."role_id" = r."id" AND rp."permission_id" = p."id"
  AND r."code" IN ('EXECUTOR', 'PROJECT_MANAGER')
  AND p."code" = 'attendance.read';


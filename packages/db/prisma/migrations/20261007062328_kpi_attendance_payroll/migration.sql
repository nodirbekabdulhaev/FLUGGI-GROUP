-- CreateEnum
CREATE TYPE "KpiMetric" AS ENUM ('REVENUE', 'ORDERS', 'LEADS', 'MEETINGS', 'TASKS');

-- CreateEnum
CREATE TYPE "AttendanceStatus" AS ENUM ('PRESENT', 'LATE', 'ABSENT', 'DAY_OFF', 'VACATION', 'SICK');

-- CreateEnum
CREATE TYPE "PayrollStatus" AS ENUM ('DRAFT', 'APPROVED', 'PAID');

-- AlterTable
ALTER TABLE "employees" ADD COLUMN     "base_salary" DECIMAL(18,2),
ADD COLUMN     "schedule_id" UUID;

-- CreateTable
CREATE TABLE "kpi_targets" (
    "id" UUID NOT NULL,
    "user_id" UUID NOT NULL,
    "period" TEXT NOT NULL,
    "metric" "KpiMetric" NOT NULL,
    "target_value" DECIMAL(18,2) NOT NULL,
    "currency" "Currency" NOT NULL DEFAULT 'UZS',
    "set_by_id" UUID NOT NULL,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "kpi_targets_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "work_schedules" (
    "id" UUID NOT NULL,
    "name" TEXT NOT NULL,
    "role_code" "RoleCode",
    "start_time" TEXT NOT NULL,
    "end_time" TEXT NOT NULL,
    "work_days" INTEGER[],
    "grace_minutes" INTEGER NOT NULL DEFAULT 0,
    "is_active" BOOLEAN NOT NULL DEFAULT true,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "work_schedules_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "attendance" (
    "id" UUID NOT NULL,
    "user_id" UUID NOT NULL,
    "date" DATE NOT NULL,
    "check_in" TIMESTAMP(3),
    "check_out" TIMESTAMP(3),
    "status" "AttendanceStatus" NOT NULL,
    "late_minutes" INTEGER NOT NULL DEFAULT 0,
    "work_minutes" INTEGER NOT NULL DEFAULT 0,
    "comment" TEXT,
    "edited_by_id" UUID,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "attendance_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "payroll_entries" (
    "id" UUID NOT NULL,
    "user_id" UUID NOT NULL,
    "period" TEXT NOT NULL,
    "base_salary" DECIMAL(18,2) NOT NULL DEFAULT 0,
    "kpi_bonus" DECIMAL(18,2) NOT NULL DEFAULT 0,
    "commission" DECIMAL(18,2) NOT NULL DEFAULT 0,
    "other_bonus" DECIMAL(18,2) NOT NULL DEFAULT 0,
    "penalty" DECIMAL(18,2) NOT NULL DEFAULT 0,
    "final_salary" DECIMAL(18,2) NOT NULL DEFAULT 0,
    "kpi_pct" DECIMAL(8,2),
    "status" "PayrollStatus" NOT NULL DEFAULT 'DRAFT',
    "comment" TEXT,
    "approved_by_id" UUID,
    "approved_at" TIMESTAMP(3),
    "paid_at" TIMESTAMP(3),
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "payroll_entries_pkey" PRIMARY KEY ("id")
);

-- CreateIndex
CREATE INDEX "kpi_targets_period_idx" ON "kpi_targets"("period");

-- CreateIndex
CREATE UNIQUE INDEX "kpi_targets_user_id_period_metric_key" ON "kpi_targets"("user_id", "period", "metric");

-- CreateIndex
CREATE INDEX "attendance_date_idx" ON "attendance"("date");

-- CreateIndex
CREATE UNIQUE INDEX "attendance_user_id_date_key" ON "attendance"("user_id", "date");

-- CreateIndex
CREATE INDEX "payroll_entries_period_idx" ON "payroll_entries"("period");

-- CreateIndex
CREATE UNIQUE INDEX "payroll_entries_user_id_period_key" ON "payroll_entries"("user_id", "period");

-- AddForeignKey
ALTER TABLE "employees" ADD CONSTRAINT "employees_schedule_id_fkey" FOREIGN KEY ("schedule_id") REFERENCES "work_schedules"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "kpi_targets" ADD CONSTRAINT "kpi_targets_user_id_fkey" FOREIGN KEY ("user_id") REFERENCES "users"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "kpi_targets" ADD CONSTRAINT "kpi_targets_set_by_id_fkey" FOREIGN KEY ("set_by_id") REFERENCES "users"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "attendance" ADD CONSTRAINT "attendance_user_id_fkey" FOREIGN KEY ("user_id") REFERENCES "users"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "attendance" ADD CONSTRAINT "attendance_edited_by_id_fkey" FOREIGN KEY ("edited_by_id") REFERENCES "users"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "payroll_entries" ADD CONSTRAINT "payroll_entries_user_id_fkey" FOREIGN KEY ("user_id") REFERENCES "users"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "payroll_entries" ADD CONSTRAINT "payroll_entries_approved_by_id_fkey" FOREIGN KEY ("approved_by_id") REFERENCES "users"("id") ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE "kpi_targets" ADD CONSTRAINT "kpi_targets_value_check" CHECK ("target_value" >= 0);
ALTER TABLE "work_schedules" ADD CONSTRAINT "work_schedules_time_check"
  CHECK ("start_time" ~ '^([01][0-9]|2[0-3]):[0-5][0-9]$' AND "end_time" ~ '^([01][0-9]|2[0-3]):[0-5][0-9]$');
ALTER TABLE "attendance" ADD CONSTRAINT "attendance_minutes_check" CHECK ("late_minutes" >= 0 AND "work_minutes" >= 0);
ALTER TABLE "payroll_entries" ADD CONSTRAINT "payroll_amounts_check"
  CHECK ("base_salary" >= 0 AND "kpi_bonus" >= 0 AND "other_bonus" >= 0 AND "penalty" >= 0);
-- Зарплатные начисления не удаляются (ТЗ §66)
CREATE TRIGGER payroll_entries_no_delete BEFORE DELETE ON "payroll_entries" FOR EACH ROW EXECUTE FUNCTION forbid_delete();

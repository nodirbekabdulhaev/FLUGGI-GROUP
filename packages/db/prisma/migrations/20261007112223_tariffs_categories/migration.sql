/*
  Warnings:

  - Changed the type of `category` on the `expenses` table. No cast exists, the column would be dropped and recreated, which cannot be done if there is data, since the column is required.

*/
-- CreateEnum
CREATE TYPE "FinanceCategoryKind" AS ENUM ('EXPENSE', 'INCOME');

-- CreateEnum
CREATE TYPE "TariffItemKind" AS ENUM ('PIECE', 'FIXED');

-- CreateEnum
CREATE TYPE "CostLineStatus" AS ENUM ('PLANNED', 'ACCRUED', 'CANCELLED');

-- AlterEnum
-- This migration adds more than one value to an enum.
-- With PostgreSQL versions 11 and earlier, this is not possible
-- in a single migration. This can be worked around by creating
-- multiple migrations, each migration adding only one value to
-- the enum.


ALTER TYPE "ExecutorSpecialty" ADD VALUE 'MOBILOGRAPHER';
ALTER TYPE "ExecutorSpecialty" ADD VALUE 'BRANDFACE';

-- AlterTable
-- Категория расхода: enum → код из редактируемого справочника (данные сохраняются)
ALTER TABLE "expenses" ALTER COLUMN "category" TYPE TEXT USING "category"::text;

-- AlterTable
ALTER TABLE "proposal_items" ADD COLUMN     "tariff_id" UUID;

-- DropEnum
DROP TYPE "ExpenseCategory";

-- CreateTable
CREATE TABLE "finance_categories" (
    "id" UUID NOT NULL,
    "code" TEXT NOT NULL,
    "kind" "FinanceCategoryKind" NOT NULL,
    "name" TEXT NOT NULL,
    "account_hint" TEXT,
    "is_overhead" BOOLEAN NOT NULL DEFAULT false,
    "is_active" BOOLEAN NOT NULL DEFAULT true,
    "sort" INTEGER NOT NULL DEFAULT 0,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "finance_categories_pkey" PRIMARY KEY ("id")
);

-- Категории по умолчанию (дальше их меняет CEO)
INSERT INTO "finance_categories" ("id", "code", "kind", "name", "account_hint", "is_overhead", "sort") VALUES
  (gen_random_uuid(), 'EXECUTOR', 'EXPENSE', 'Исполнитель', '9130', false, 0),
  (gen_random_uuid(), 'ADS', 'EXPENSE', 'Реклама', '9410', false, 1),
  (gen_random_uuid(), 'PRODUCTION', 'EXPENSE', 'Производство', '9130', false, 2),
  (gen_random_uuid(), 'PHOTO', 'EXPENSE', 'Фото', '9130', false, 3),
  (gen_random_uuid(), 'VIDEO', 'EXPENSE', 'Видео', '9130', false, 4),
  (gen_random_uuid(), 'DESIGN', 'EXPENSE', 'Дизайн', '9130', false, 5),
  (gen_random_uuid(), 'DEVELOPMENT', 'EXPENSE', 'Разработка', '9130', false, 6),
  (gen_random_uuid(), 'TRANSPORT', 'EXPENSE', 'Транспорт', '9420', false, 7),
  (gen_random_uuid(), 'MATERIALS', 'EXPENSE', 'Материалы', '9130', false, 8),
  (gen_random_uuid(), 'SERVICES', 'EXPENSE', 'Сервисы', '9420', false, 9),
  (gen_random_uuid(), 'OTHER', 'EXPENSE', 'Прочее', '9420', false, 10),
  (gen_random_uuid(), 'RENT', 'EXPENSE', 'Аренда', '9420', true, 11),
  (gen_random_uuid(), 'OFFICE', 'EXPENSE', 'Офис (связь, интернет, хозтовары)', '9420', true, 12),
  (gen_random_uuid(), 'TAXES', 'EXPENSE', 'Налоги и сборы', '9430', false, 13),
  (gen_random_uuid(), 'BANK', 'EXPENSE', 'Банковские комиссии', '9430', false, 14),
  (gen_random_uuid(), 'PARTNER', 'INCOME', 'Партнёрское вознаграждение', '9390', false, 15),
  (gen_random_uuid(), 'SUPPLIER_REFUND', 'INCOME', 'Возврат от поставщика', '9390', false, 16),
  (gen_random_uuid(), 'BANK_INTEREST', 'INCOME', 'Проценты банка', '9530', false, 17),
  (gen_random_uuid(), 'FX_GAIN', 'INCOME', 'Курсовая разница', '9540', false, 18),
  (gen_random_uuid(), 'OTHER_INCOME', 'INCOME', 'Прочие доходы', '9390', false, 19);

-- CreateTable
CREATE TABLE "other_incomes" (
    "id" UUID NOT NULL,
    "number" SERIAL NOT NULL,
    "category" TEXT NOT NULL,
    "amount" DECIMAL(18,2) NOT NULL,
    "currency" "Currency" NOT NULL DEFAULT 'UZS',
    "exchange_rate" DECIMAL(18,6) NOT NULL,
    "amount_uzs" DECIMAL(18,2) NOT NULL,
    "income_date" DATE NOT NULL,
    "project_id" UUID,
    "client_id" UUID,
    "description" TEXT,
    "created_by_id" UUID NOT NULL,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,
    "deleted_at" TIMESTAMP(3),

    CONSTRAINT "other_incomes_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "work_items" (
    "id" UUID NOT NULL,
    "code" TEXT NOT NULL,
    "name" TEXT NOT NULL,
    "unit" TEXT NOT NULL DEFAULT 'шт',
    "specialty" "ExecutorSpecialty",
    "default_rate" DECIMAL(18,2) NOT NULL DEFAULT 0,
    "currency" "Currency" NOT NULL DEFAULT 'UZS',
    "is_active" BOOLEAN NOT NULL DEFAULT true,
    "sort" INTEGER NOT NULL DEFAULT 0,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "work_items_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "employee_rates" (
    "user_id" UUID NOT NULL,
    "work_item_id" UUID NOT NULL,
    "rate" DECIMAL(18,2) NOT NULL,
    "currency" "Currency" NOT NULL DEFAULT 'UZS',
    "updated_at" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "employee_rates_pkey" PRIMARY KEY ("user_id","work_item_id")
);

-- CreateTable
CREATE TABLE "tariffs" (
    "id" UUID NOT NULL,
    "service_id" UUID NOT NULL,
    "name" TEXT NOT NULL,
    "description" TEXT,
    "price" DECIMAL(18,2) NOT NULL,
    "currency" "Currency" NOT NULL DEFAULT 'UZS',
    "is_active" BOOLEAN NOT NULL DEFAULT true,
    "sort" INTEGER NOT NULL DEFAULT 0,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "tariffs_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "tariff_items" (
    "id" UUID NOT NULL,
    "tariff_id" UUID NOT NULL,
    "kind" "TariffItemKind" NOT NULL,
    "work_item_id" UUID,
    "quantity" DECIMAL(12,2) NOT NULL DEFAULT 1,
    "specialty" "ExecutorSpecialty",
    "amount" DECIMAL(18,2),
    "currency" "Currency" NOT NULL DEFAULT 'UZS',
    "label" TEXT,
    "sort" INTEGER NOT NULL DEFAULT 0,

    CONSTRAINT "tariff_items_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "project_cost_lines" (
    "id" UUID NOT NULL,
    "project_id" UUID NOT NULL,
    "tariff_id" UUID,
    "tariff_item_id" UUID,
    "kind" "TariffItemKind" NOT NULL,
    "work_item_id" UUID,
    "specialty" "ExecutorSpecialty",
    "label" TEXT NOT NULL,
    "quantity" DECIMAL(12,2) NOT NULL,
    "rate" DECIMAL(18,2) NOT NULL,
    "currency" "Currency" NOT NULL DEFAULT 'UZS',
    "assignee_id" UUID,
    "status" "CostLineStatus" NOT NULL DEFAULT 'PLANNED',
    "expense_id" UUID,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "project_cost_lines_pkey" PRIMARY KEY ("id")
);

-- CreateIndex
CREATE UNIQUE INDEX "finance_categories_code_key" ON "finance_categories"("code");

-- CreateIndex
CREATE UNIQUE INDEX "other_incomes_number_key" ON "other_incomes"("number");

-- CreateIndex
CREATE INDEX "other_incomes_income_date_idx" ON "other_incomes"("income_date");

-- CreateIndex
CREATE UNIQUE INDEX "work_items_code_key" ON "work_items"("code");

-- CreateIndex
CREATE INDEX "tariffs_service_id_idx" ON "tariffs"("service_id");

-- CreateIndex
CREATE INDEX "tariff_items_tariff_id_idx" ON "tariff_items"("tariff_id");

-- CreateIndex
CREATE UNIQUE INDEX "project_cost_lines_expense_id_key" ON "project_cost_lines"("expense_id");

-- CreateIndex
CREATE INDEX "project_cost_lines_project_id_idx" ON "project_cost_lines"("project_id");

-- AddForeignKey
ALTER TABLE "proposal_items" ADD CONSTRAINT "proposal_items_tariff_id_fkey" FOREIGN KEY ("tariff_id") REFERENCES "tariffs"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "expenses" ADD CONSTRAINT "expenses_category_fkey" FOREIGN KEY ("category") REFERENCES "finance_categories"("code") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "other_incomes" ADD CONSTRAINT "other_incomes_category_fkey" FOREIGN KEY ("category") REFERENCES "finance_categories"("code") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "other_incomes" ADD CONSTRAINT "other_incomes_project_id_fkey" FOREIGN KEY ("project_id") REFERENCES "projects"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "other_incomes" ADD CONSTRAINT "other_incomes_client_id_fkey" FOREIGN KEY ("client_id") REFERENCES "clients"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "other_incomes" ADD CONSTRAINT "other_incomes_created_by_id_fkey" FOREIGN KEY ("created_by_id") REFERENCES "users"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "employee_rates" ADD CONSTRAINT "employee_rates_user_id_fkey" FOREIGN KEY ("user_id") REFERENCES "users"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "employee_rates" ADD CONSTRAINT "employee_rates_work_item_id_fkey" FOREIGN KEY ("work_item_id") REFERENCES "work_items"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "tariffs" ADD CONSTRAINT "tariffs_service_id_fkey" FOREIGN KEY ("service_id") REFERENCES "services"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "tariff_items" ADD CONSTRAINT "tariff_items_tariff_id_fkey" FOREIGN KEY ("tariff_id") REFERENCES "tariffs"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "tariff_items" ADD CONSTRAINT "tariff_items_work_item_id_fkey" FOREIGN KEY ("work_item_id") REFERENCES "work_items"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "project_cost_lines" ADD CONSTRAINT "project_cost_lines_project_id_fkey" FOREIGN KEY ("project_id") REFERENCES "projects"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "project_cost_lines" ADD CONSTRAINT "project_cost_lines_tariff_id_fkey" FOREIGN KEY ("tariff_id") REFERENCES "tariffs"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "project_cost_lines" ADD CONSTRAINT "project_cost_lines_tariff_item_id_fkey" FOREIGN KEY ("tariff_item_id") REFERENCES "tariff_items"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "project_cost_lines" ADD CONSTRAINT "project_cost_lines_work_item_id_fkey" FOREIGN KEY ("work_item_id") REFERENCES "work_items"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "project_cost_lines" ADD CONSTRAINT "project_cost_lines_assignee_id_fkey" FOREIGN KEY ("assignee_id") REFERENCES "users"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "project_cost_lines" ADD CONSTRAINT "project_cost_lines_expense_id_fkey" FOREIGN KEY ("expense_id") REFERENCES "expenses"("id") ON DELETE SET NULL ON UPDATE CASCADE;

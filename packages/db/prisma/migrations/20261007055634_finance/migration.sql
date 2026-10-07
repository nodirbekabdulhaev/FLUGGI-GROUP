-- CreateEnum
CREATE TYPE "ExpenseScope" AS ENUM ('PROJECT', 'COMPANY');

-- CreateEnum
CREATE TYPE "ExpenseCategory" AS ENUM ('EXECUTOR', 'ADS', 'PRODUCTION', 'PHOTO', 'VIDEO', 'DESIGN', 'DEVELOPMENT', 'TRANSPORT', 'MATERIALS', 'SERVICES', 'OTHER');

-- AlterTable
ALTER TABLE "commissions" ADD COLUMN     "approved_at" TIMESTAMP(3),
ADD COLUMN     "approved_by_id" UUID,
ADD COLUMN     "paid_at" TIMESTAMP(3),
ADD COLUMN     "paid_by_id" UUID;

-- CreateTable
CREATE TABLE "expenses" (
    "id" UUID NOT NULL,
    "number" SERIAL NOT NULL,
    "scope" "ExpenseScope" NOT NULL,
    "project_id" UUID,
    "category" "ExpenseCategory" NOT NULL,
    "amount" DECIMAL(18,2) NOT NULL,
    "currency" "Currency" NOT NULL DEFAULT 'UZS',
    "exchange_rate" DECIMAL(18,6) NOT NULL,
    "amount_uzs" DECIMAL(18,2) NOT NULL,
    "expense_date" DATE NOT NULL,
    "payee_user_id" UUID,
    "description" TEXT,
    "created_by_id" UUID NOT NULL,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,
    "deleted_at" TIMESTAMP(3),

    CONSTRAINT "expenses_pkey" PRIMARY KEY ("id")
);

-- CreateIndex
CREATE UNIQUE INDEX "expenses_number_key" ON "expenses"("number");

-- CreateIndex
CREATE INDEX "expenses_project_id_idx" ON "expenses"("project_id");

-- CreateIndex
CREATE INDEX "expenses_scope_expense_date_idx" ON "expenses"("scope", "expense_date");

-- AddForeignKey
ALTER TABLE "expenses" ADD CONSTRAINT "expenses_project_id_fkey" FOREIGN KEY ("project_id") REFERENCES "projects"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "expenses" ADD CONSTRAINT "expenses_payee_user_id_fkey" FOREIGN KEY ("payee_user_id") REFERENCES "users"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "expenses" ADD CONSTRAINT "expenses_created_by_id_fkey" FOREIGN KEY ("created_by_id") REFERENCES "users"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "commissions" ADD CONSTRAINT "commissions_approved_by_id_fkey" FOREIGN KEY ("approved_by_id") REFERENCES "users"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "commissions" ADD CONSTRAINT "commissions_paid_by_id_fkey" FOREIGN KEY ("paid_by_id") REFERENCES "users"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- Проектный расход привязан к проекту, расход компании — нет (ТЗ §26)
ALTER TABLE "expenses" ADD CONSTRAINT "expenses_scope_project_check"
  CHECK (("scope" = 'PROJECT' AND "project_id" IS NOT NULL) OR ("scope" = 'COMPANY' AND "project_id" IS NULL));
ALTER TABLE "expenses" ADD CONSTRAINT "expenses_amount_positive" CHECK ("amount" > 0);

-- ТЗ §77, Rule 5: у задачи всегда есть исполнитель.
-- Задачи без ответственного (из шаблона) переходят к РОП проекта.
UPDATE "tasks" t SET "assignee_id" = p."rop_id"
  FROM "projects" p
  WHERE t."project_id" = p."id" AND t."assignee_id" IS NULL;

-- DropForeignKey
ALTER TABLE "tasks" DROP CONSTRAINT "tasks_assignee_id_fkey";

-- AlterTable
ALTER TABLE "tasks" ALTER COLUMN "assignee_id" SET NOT NULL;

-- AddForeignKey
ALTER TABLE "tasks" ADD CONSTRAINT "tasks_assignee_id_fkey" FOREIGN KEY ("assignee_id") REFERENCES "users"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

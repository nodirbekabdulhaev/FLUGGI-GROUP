-- AlterEnum
ALTER TYPE "RoleCode" ADD VALUE 'PROJECT_MANAGER';

-- AlterTable
ALTER TABLE "projects" ADD COLUMN     "direction_id" UUID;

-- AlterTable
ALTER TABLE "services" ADD COLUMN     "direction_id" UUID;

-- CreateTable
CREATE TABLE "directions" (
    "id" UUID NOT NULL,
    "code" TEXT NOT NULL,
    "name" TEXT NOT NULL,
    "sort" INTEGER NOT NULL DEFAULT 0,
    "is_active" BOOLEAN NOT NULL DEFAULT true,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "directions_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "user_directions" (
    "user_id" UUID NOT NULL,
    "direction_id" UUID NOT NULL,

    CONSTRAINT "user_directions_pkey" PRIMARY KEY ("user_id","direction_id")
);

-- CreateIndex
CREATE UNIQUE INDEX "directions_code_key" ON "directions"("code");

-- AddForeignKey
ALTER TABLE "services" ADD CONSTRAINT "services_direction_id_fkey" FOREIGN KEY ("direction_id") REFERENCES "directions"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "projects" ADD CONSTRAINT "projects_direction_id_fkey" FOREIGN KEY ("direction_id") REFERENCES "directions"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "user_directions" ADD CONSTRAINT "user_directions_user_id_fkey" FOREIGN KEY ("user_id") REFERENCES "users"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "user_directions" ADD CONSTRAINT "user_directions_direction_id_fkey" FOREIGN KEY ("direction_id") REFERENCES "directions"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- Направления по умолчанию (CEO переименовывает и добавляет в «Справочниках»)
INSERT INTO "directions" ("id", "code", "name", "sort", "updated_at") VALUES
  (gen_random_uuid(), 'IT', 'IT и разработка', 10, CURRENT_TIMESTAMP),
  (gen_random_uuid(), 'MEDIA', 'Медиа: SMM, брендинг, продакшн', 20, CURRENT_TIMESTAMP),
  (gen_random_uuid(), 'MARKETING', 'Маркетинг и реклама', 30, CURRENT_TIMESTAMP)
ON CONFLICT ("code") DO NOTHING;

-- Услуги → направления
UPDATE "services" s SET "direction_id" = d."id"
FROM "directions" d
WHERE s."direction_id" IS NULL AND (
  (d."code" = 'IT' AND s."code" IN ('WEBSITE', 'CRM', 'ERP')) OR
  (d."code" = 'MEDIA' AND s."code" IN ('SMM', 'BRANDING', 'DESIGN', 'PHOTO', 'VIDEO')) OR
  (d."code" = 'MARKETING' AND s."code" IN ('TARGET', 'MARKETING'))
);

-- Проекты: направление услуги сделки, иначе — первой позиции принятого КП
UPDATE "projects" p SET "direction_id" = s."direction_id"
FROM "deals" dl JOIN "services" s ON s."id" = dl."service_id"
WHERE p."deal_id" = dl."id" AND p."direction_id" IS NULL;

UPDATE "projects" p SET "direction_id" = x."direction_id"
FROM (
  SELECT DISTINCT ON (pr."deal_id") pr."deal_id", s."direction_id"
  FROM "proposals" pr
  JOIN "proposal_items" pi ON pi."proposal_id" = pr."id"
  JOIN "services" s ON s."id" = pi."service_id"
  WHERE s."direction_id" IS NOT NULL
  ORDER BY pr."deal_id", (pr."status" = 'ACCEPTED') DESC, pr."updated_at" DESC, pi."sort"
) x
WHERE p."deal_id" = x."deal_id" AND p."direction_id" IS NULL;

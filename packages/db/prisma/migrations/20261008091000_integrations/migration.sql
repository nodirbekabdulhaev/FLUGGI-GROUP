-- CreateEnum
CREATE TYPE "IntakeResult" AS ENUM ('LEAD_CREATED', 'DUPLICATE', 'SPAM');

-- CreateEnum
CREATE TYPE "SocialChannel" AS ENUM ('INSTAGRAM_DM', 'INSTAGRAM_COMMENT', 'FACEBOOK_COMMENT');

-- CreateTable
CREATE TABLE "lead_forms" (
    "id" UUID NOT NULL,
    "key" TEXT NOT NULL,
    "name" TEXT NOT NULL,
    "title" TEXT NOT NULL,
    "description" TEXT,
    "button_text" TEXT NOT NULL DEFAULT 'Отправить',
    "success_message" TEXT NOT NULL DEFAULT 'Спасибо! Мы свяжемся с вами в ближайшее время.',
    "fields" JSONB NOT NULL,
    "service_id" UUID,
    "source_id" UUID,
    "owner_id" UUID,
    "team_id" UUID,
    "is_active" BOOLEAN NOT NULL DEFAULT true,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,
    "deleted_at" TIMESTAMP(3),

    CONSTRAINT "lead_forms_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "form_submissions" (
    "id" UUID NOT NULL,
    "form_id" UUID NOT NULL,
    "lead_id" UUID,
    "data" JSONB NOT NULL,
    "utm" JSONB,
    "page" TEXT,
    "ip" TEXT,
    "result" "IntakeResult" NOT NULL,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "form_submissions_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "social_threads" (
    "id" UUID NOT NULL,
    "channel" "SocialChannel" NOT NULL,
    "peer_id" TEXT NOT NULL,
    "peer_name" TEXT,
    "peer_username" TEXT,
    "lead_id" UUID,
    "owner_id" UUID,
    "team_id" UUID,
    "unread" INTEGER NOT NULL DEFAULT 0,
    "last_message_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "social_threads_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "social_messages" (
    "id" UUID NOT NULL,
    "thread_id" UUID NOT NULL,
    "direction" TEXT NOT NULL,
    "external_id" TEXT,
    "text" TEXT NOT NULL,
    "media_id" TEXT,
    "author_id" UUID,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "social_messages_pkey" PRIMARY KEY ("id")
);

-- CreateIndex
CREATE UNIQUE INDEX "lead_forms_key_key" ON "lead_forms"("key");

-- CreateIndex
CREATE INDEX "form_submissions_form_id_created_at_idx" ON "form_submissions"("form_id", "created_at");

-- CreateIndex
CREATE INDEX "social_threads_owner_id_last_message_at_idx" ON "social_threads"("owner_id", "last_message_at");

-- CreateIndex
CREATE UNIQUE INDEX "social_threads_channel_peer_id_key" ON "social_threads"("channel", "peer_id");

-- CreateIndex
CREATE UNIQUE INDEX "social_messages_external_id_key" ON "social_messages"("external_id");

-- CreateIndex
CREATE INDEX "social_messages_thread_id_created_at_idx" ON "social_messages"("thread_id", "created_at");

-- AddForeignKey
ALTER TABLE "lead_forms" ADD CONSTRAINT "lead_forms_owner_id_fkey" FOREIGN KEY ("owner_id") REFERENCES "users"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "form_submissions" ADD CONSTRAINT "form_submissions_form_id_fkey" FOREIGN KEY ("form_id") REFERENCES "lead_forms"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "form_submissions" ADD CONSTRAINT "form_submissions_lead_id_fkey" FOREIGN KEY ("lead_id") REFERENCES "leads"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "social_threads" ADD CONSTRAINT "social_threads_lead_id_fkey" FOREIGN KEY ("lead_id") REFERENCES "leads"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "social_threads" ADD CONSTRAINT "social_threads_owner_id_fkey" FOREIGN KEY ("owner_id") REFERENCES "users"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "social_messages" ADD CONSTRAINT "social_messages_thread_id_fkey" FOREIGN KEY ("thread_id") REFERENCES "social_threads"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "social_messages" ADD CONSTRAINT "social_messages_author_id_fkey" FOREIGN KEY ("author_id") REFERENCES "users"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- Источник «Таргет (Meta Ads)» для лидов из рекламных форм Instagram/Facebook
INSERT INTO "lead_sources" ("id", "code", "name_ru", "sort", "updated_at")
SELECT gen_random_uuid(), 'TARGET', 'Таргет (Meta Ads)', 9, CURRENT_TIMESTAMP
WHERE EXISTS (SELECT 1 FROM "lead_sources")
ON CONFLICT ("code") DO NOTHING;

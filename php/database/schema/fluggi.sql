-- CreateTable
CREATE TABLE `users` (
    `id` CHAR(36) NOT NULL,
    `email` VARCHAR(191) NOT NULL,
    `phone` TEXT NULL,
    `password_hash` TEXT NOT NULL,
    `full_name` TEXT NOT NULL,
    `role_id` CHAR(36) NOT NULL,
    `team_id` CHAR(36) NULL,
    `status` ENUM('ACTIVE', 'BLOCKED') NOT NULL DEFAULT 'ACTIVE',
    `locale` ENUM('ru', 'uz', 'en') NOT NULL DEFAULT 'ru',
    `telegram_chat_id` VARCHAR(191) NULL,
    `telegram_username` TEXT NULL,
    `failed_login_count` INTEGER NOT NULL DEFAULT 0,
    `locked_until` DATETIME(3) NULL,
    `last_login_at` DATETIME(3) NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL,
    `deleted_at` DATETIME(3) NULL,

    UNIQUE INDEX `users_email_key`(`email`),
    UNIQUE INDEX `users_telegram_chat_id_key`(`telegram_chat_id`),
    INDEX `users_role_id_idx`(`role_id`),
    INDEX `users_team_id_idx`(`team_id`),
    INDEX `users_status_idx`(`status`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `roles` (
    `id` CHAR(36) NOT NULL,
    `code` ENUM('CEO', 'ROP', 'MANAGER', 'EXECUTOR', 'HR_ADMIN', 'PROJECT_MANAGER') NOT NULL,
    `name` TEXT NOT NULL,
    `is_system` BOOLEAN NOT NULL DEFAULT true,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL,

    UNIQUE INDEX `roles_code_key`(`code`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `permissions` (
    `id` CHAR(36) NOT NULL,
    `code` VARCHAR(191) NOT NULL,
    `module` TEXT NOT NULL,
    `description` TEXT NOT NULL,

    UNIQUE INDEX `permissions_code_key`(`code`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `role_permissions` (
    `role_id` CHAR(36) NOT NULL,
    `permission_id` CHAR(36) NOT NULL,
    `scope` ENUM('OWN', 'TEAM', 'ALL') NOT NULL,

    PRIMARY KEY (`role_id`, `permission_id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `teams` (
    `id` CHAR(36) NOT NULL,
    `name` VARCHAR(191) NOT NULL,
    `head_id` CHAR(36) NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL,
    `deleted_at` DATETIME(3) NULL,

    UNIQUE INDEX `teams_name_key`(`name`),
    INDEX `teams_head_id_idx`(`head_id`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `employees` (
    `id` CHAR(36) NOT NULL,
    `user_id` CHAR(36) NOT NULL,
    `position` TEXT NULL,
    `specialty` ENUM('SMM', 'DESIGNER', 'VIDEOGRAPHER', 'EDITOR', 'TARGETOLOGIST', 'DEVELOPER', 'PHOTOGRAPHER', 'COPYWRITER', 'MOBILOGRAPHER', 'BRANDFACE') NULL,
    `base_salary` DECIMAL(18, 2) NULL,
    `kpi_bonus_target` DECIMAL(18, 2) NULL,
    `schedule_id` CHAR(36) NULL,
    `hired_at` DATE NULL,
    `fired_at` DATE NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL,

    UNIQUE INDEX `employees_user_id_key`(`user_id`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `sessions` (
    `id` CHAR(36) NOT NULL,
    `user_id` CHAR(36) NOT NULL,
    `token_hash` VARCHAR(191) NOT NULL,
    `ip` TEXT NULL,
    `user_agent` TEXT NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `last_seen_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `expires_at` DATETIME(3) NOT NULL,
    `revoked_at` DATETIME(3) NULL,

    UNIQUE INDEX `sessions_token_hash_key`(`token_hash`),
    INDEX `sessions_user_id_idx`(`user_id`),
    INDEX `sessions_expires_at_idx`(`expires_at`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `audit_logs` (
    `id` CHAR(36) NOT NULL,
    `actor_id` CHAR(36) NULL,
    `action` TEXT NOT NULL,
    `entity_type` VARCHAR(191) NOT NULL,
    `entity_id` CHAR(36) NULL,
    `changes` JSON NULL,
    `ip` TEXT NULL,
    `user_agent` TEXT NULL,
    `session_id` CHAR(36) NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),

    INDEX `audit_logs_entity_type_entity_id_idx`(`entity_type`, `entity_id`),
    INDEX `audit_logs_actor_id_idx`(`actor_id`),
    INDEX `audit_logs_created_at_idx`(`created_at`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `outbox_events` (
    `id` CHAR(36) NOT NULL,
    `type` TEXT NOT NULL,
    `payload` JSON NOT NULL,
    `actor_id` CHAR(36) NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `available_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `processed_at` DATETIME(3) NULL,
    `attempts` INTEGER NOT NULL DEFAULT 0,
    `last_error` TEXT NULL,

    INDEX `outbox_events_processed_at_available_at_idx`(`processed_at`, `available_at`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `services` (
    `id` CHAR(36) NOT NULL,
    `code` VARCHAR(191) NOT NULL,
    `name_ru` TEXT NOT NULL,
    `name_uz` TEXT NULL,
    `name_en` TEXT NULL,
    `description` TEXT NULL,
    `base_price` DECIMAL(18, 2) NULL,
    `min_price` DECIMAL(18, 2) NULL,
    `currency` ENUM('UZS', 'USD') NOT NULL DEFAULT 'UZS',
    `pricing_type` ENUM('FIXED', 'MONTHLY', 'HOURLY', 'CUSTOM') NOT NULL DEFAULT 'FIXED',
    `direction_id` CHAR(36) NULL,
    `is_active` BOOLEAN NOT NULL DEFAULT true,
    `sort` INTEGER NOT NULL DEFAULT 0,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL,

    UNIQUE INDEX `services_code_key`(`code`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `lead_sources` (
    `id` CHAR(36) NOT NULL,
    `code` VARCHAR(191) NOT NULL,
    `name_ru` TEXT NOT NULL,
    `name_uz` TEXT NULL,
    `name_en` TEXT NULL,
    `is_active` BOOLEAN NOT NULL DEFAULT true,
    `sort` INTEGER NOT NULL DEFAULT 0,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL,

    UNIQUE INDEX `lead_sources_code_key`(`code`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `loss_reasons` (
    `id` CHAR(36) NOT NULL,
    `code` VARCHAR(191) NOT NULL,
    `name_ru` TEXT NOT NULL,
    `name_uz` TEXT NULL,
    `name_en` TEXT NULL,
    `requires_comment` BOOLEAN NOT NULL DEFAULT false,
    `is_active` BOOLEAN NOT NULL DEFAULT true,
    `sort` INTEGER NOT NULL DEFAULT 0,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL,

    UNIQUE INDEX `loss_reasons_code_key`(`code`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `deal_stages` (
    `id` CHAR(36) NOT NULL,
    `code` VARCHAR(191) NOT NULL,
    `entity` ENUM('LEAD', 'DEAL') NOT NULL,
    `name_ru` TEXT NOT NULL,
    `name_uz` TEXT NULL,
    `name_en` TEXT NULL,
    `sort` INTEGER NOT NULL,
    `probability` INTEGER NOT NULL DEFAULT 0,
    `color` VARCHAR(191) NOT NULL DEFAULT '#71717a',
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL,

    UNIQUE INDEX `deal_stages_code_key`(`code`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `exchange_rates` (
    `id` CHAR(36) NOT NULL,
    `date` DATE NOT NULL,
    `currency` ENUM('UZS', 'USD') NOT NULL,
    `rate_to_uzs` DECIMAL(18, 6) NOT NULL,
    `set_by_id` CHAR(36) NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),

    UNIQUE INDEX `exchange_rates_date_currency_key`(`date`, `currency`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `leads` (
    `id` CHAR(36) NOT NULL,
    `number` INTEGER NOT NULL AUTO_INCREMENT,
    `title` TEXT NOT NULL,
    `contact_name` TEXT NULL,
    `company_name` TEXT NULL,
    `phone` TEXT NULL,
    `telegram` TEXT NULL,
    `whatsapp` TEXT NULL,
    `instagram` TEXT NULL,
    `email` TEXT NULL,
    `website` TEXT NULL,
    `city` TEXT NULL,
    `country` TEXT NULL,
    `source_id` CHAR(36) NOT NULL,
    `owner_id` CHAR(36) NOT NULL,
    `team_id` CHAR(36) NULL,
    `service_id` CHAR(36) NULL,
    `budget` DECIMAL(18, 2) NULL,
    `currency` ENUM('UZS', 'USD') NOT NULL DEFAULT 'UZS',
    `budget_uzs` DECIMAL(18, 2) NULL,
    `desired_date` DATE NULL,
    `priority` ENUM('LOW', 'MEDIUM', 'HIGH', 'URGENT') NOT NULL DEFAULT 'MEDIUM',
    `company_size` ENUM('SOLO', 'SMALL', 'MEDIUM', 'LARGE') NULL,
    `interest` INTEGER NULL,
    `stage_id` CHAR(36) NOT NULL,
    `status` ENUM('OPEN', 'CONVERTED', 'LOST', 'REJECTED', 'PAUSED', 'NO_RESPONSE') NOT NULL DEFAULT 'OPEN',
    `score` INTEGER NOT NULL DEFAULT 0,
    `score_level` ENUM('LOW', 'MEDIUM', 'HIGH', 'HOT') NOT NULL DEFAULT 'LOW',
    `next_contact_at` DATETIME(3) NULL,
    `last_contact_at` DATETIME(3) NULL,
    `comment` TEXT NULL,
    `client_id` CHAR(36) NULL,
    `deal_id` CHAR(36) NULL,
    `converted_at` DATETIME(3) NULL,
    `loss_reason_id` CHAR(36) NULL,
    `loss_comment` TEXT NULL,
    `closed_at` DATETIME(3) NULL,
    `created_by_id` CHAR(36) NOT NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL,
    `deleted_at` DATETIME(3) NULL,

    UNIQUE INDEX `leads_number_key`(`number`),
    UNIQUE INDEX `leads_deal_id_key`(`deal_id`),
    INDEX `leads_owner_id_idx`(`owner_id`),
    INDEX `leads_team_id_idx`(`team_id`),
    INDEX `leads_status_stage_id_idx`(`status`, `stage_id`),
    INDEX `leads_source_id_idx`(`source_id`),
    INDEX `leads_created_at_idx`(`created_at`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `clients` (
    `id` CHAR(36) NOT NULL,
    `number` INTEGER NOT NULL AUTO_INCREMENT,
    `name` TEXT NOT NULL,
    `type` ENUM('COMPANY', 'PERSON') NOT NULL DEFAULT 'COMPANY',
    `industry` TEXT NULL,
    `phone` TEXT NULL,
    `email` TEXT NULL,
    `telegram` TEXT NULL,
    `website` TEXT NULL,
    `city` TEXT NULL,
    `country` TEXT NULL,
    `owner_id` CHAR(36) NOT NULL,
    `team_id` CHAR(36) NULL,
    `source_id` CHAR(36) NULL,
    `health` ENUM('HEALTHY', 'ATTENTION', 'RISK', 'LOST') NOT NULL DEFAULT 'HEALTHY',
    `comment` TEXT NULL,
    `requisites` JSON NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL,
    `deleted_at` DATETIME(3) NULL,

    UNIQUE INDEX `clients_number_key`(`number`),
    INDEX `clients_owner_id_idx`(`owner_id`),
    INDEX `clients_team_id_idx`(`team_id`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `contacts` (
    `id` CHAR(36) NOT NULL,
    `client_id` CHAR(36) NOT NULL,
    `full_name` TEXT NOT NULL,
    `position` TEXT NULL,
    `phone` TEXT NULL,
    `telegram` TEXT NULL,
    `whatsapp` TEXT NULL,
    `instagram` TEXT NULL,
    `email` TEXT NULL,
    `is_primary` BOOLEAN NOT NULL DEFAULT false,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL,

    INDEX `contacts_client_id_idx`(`client_id`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `deals` (
    `id` CHAR(36) NOT NULL,
    `number` INTEGER NOT NULL AUTO_INCREMENT,
    `title` TEXT NOT NULL,
    `client_id` CHAR(36) NOT NULL,
    `contact_id` CHAR(36) NULL,
    `owner_id` CHAR(36) NOT NULL,
    `team_id` CHAR(36) NULL,
    `service_id` CHAR(36) NULL,
    `amount` DECIMAL(18, 2) NOT NULL,
    `currency` ENUM('UZS', 'USD') NOT NULL DEFAULT 'UZS',
    `exchange_rate` DECIMAL(18, 6) NOT NULL DEFAULT 1,
    `amount_uzs` DECIMAL(18, 2) NOT NULL,
    `stage_id` CHAR(36) NOT NULL,
    `status` ENUM('OPEN', 'WON', 'LOST', 'REJECTED', 'PAUSED', 'NO_RESPONSE') NOT NULL DEFAULT 'OPEN',
    `probability_override` INTEGER NULL,
    `expected_close_date` DATE NULL,
    `is_repeat` BOOLEAN NOT NULL DEFAULT false,
    `loss_reason_id` CHAR(36) NULL,
    `loss_comment` TEXT NULL,
    `won_at` DATETIME(3) NULL,
    `closed_at` DATETIME(3) NULL,
    `created_by_id` CHAR(36) NOT NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL,
    `deleted_at` DATETIME(3) NULL,

    UNIQUE INDEX `deals_number_key`(`number`),
    INDEX `deals_owner_id_idx`(`owner_id`),
    INDEX `deals_team_id_idx`(`team_id`),
    INDEX `deals_client_id_idx`(`client_id`),
    INDEX `deals_status_stage_id_idx`(`status`, `stage_id`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `stage_history` (
    `id` CHAR(36) NOT NULL,
    `lead_id` CHAR(36) NULL,
    `deal_id` CHAR(36) NULL,
    `from_stage_id` CHAR(36) NULL,
    `to_stage_id` CHAR(36) NOT NULL,
    `changed_by_id` CHAR(36) NOT NULL,
    `duration_sec` INTEGER NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),

    INDEX `stage_history_lead_id_idx`(`lead_id`),
    INDEX `stage_history_deal_id_idx`(`deal_id`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `activities` (
    `id` CHAR(36) NOT NULL,
    `type` TEXT NOT NULL,
    `actor_id` CHAR(36) NULL,
    `lead_id` CHAR(36) NULL,
    `deal_id` CHAR(36) NULL,
    `client_id` CHAR(36) NULL,
    `meeting_id` CHAR(36) NULL,
    `project_id` CHAR(36) NULL,
    `task_id` CHAR(36) NULL,
    `payload` JSON NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),

    INDEX `activities_project_id_created_at_idx`(`project_id`, `created_at`),
    INDEX `activities_task_id_created_at_idx`(`task_id`, `created_at`),
    INDEX `activities_lead_id_created_at_idx`(`lead_id`, `created_at`),
    INDEX `activities_deal_id_created_at_idx`(`deal_id`, `created_at`),
    INDEX `activities_client_id_created_at_idx`(`client_id`, `created_at`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `comments` (
    `id` CHAR(36) NOT NULL,
    `author_id` CHAR(36) NOT NULL,
    `body` TEXT NOT NULL,
    `lead_id` CHAR(36) NULL,
    `deal_id` CHAR(36) NULL,
    `client_id` CHAR(36) NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL,
    `deleted_at` DATETIME(3) NULL,

    INDEX `comments_lead_id_idx`(`lead_id`),
    INDEX `comments_deal_id_idx`(`deal_id`),
    INDEX `comments_client_id_idx`(`client_id`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `meetings` (
    `id` CHAR(36) NOT NULL,
    `lead_id` CHAR(36) NULL,
    `deal_id` CHAR(36) NULL,
    `client_id` CHAR(36) NULL,
    `manager_id` CHAR(36) NOT NULL,
    `rop_id` CHAR(36) NULL,
    `team_id` CHAR(36) NULL,
    `starts_at` DATETIME(3) NOT NULL,
    `duration_min` INTEGER NOT NULL DEFAULT 60,
    `type` ENUM('ONLINE', 'OFFLINE', 'PHONE', 'TELEGRAM', 'GOOGLE_MEET', 'ZOOM') NOT NULL,
    `link` TEXT NULL,
    `status` ENUM('SCHEDULED', 'CONFIRMED', 'DONE', 'RESCHEDULED', 'CANCELLED', 'NO_SHOW') NOT NULL DEFAULT 'SCHEDULED',
    `comment` TEXT NULL,
    `result` TEXT NULL,
    `created_by_id` CHAR(36) NOT NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL,

    INDEX `meetings_manager_id_starts_at_idx`(`manager_id`, `starts_at`),
    INDEX `meetings_team_id_starts_at_idx`(`team_id`, `starts_at`),
    INDEX `meetings_lead_id_idx`(`lead_id`),
    INDEX `meetings_deal_id_idx`(`deal_id`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `notifications` (
    `id` CHAR(36) NOT NULL,
    `user_id` CHAR(36) NOT NULL,
    `type` TEXT NOT NULL,
    `title` TEXT NOT NULL,
    `body` TEXT NULL,
    `link` TEXT NULL,
    `read_at` DATETIME(3) NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),

    INDEX `notifications_user_id_read_at_created_at_idx`(`user_id`, `read_at`, `created_at`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `idempotency_keys` (
    `key` VARCHAR(191) NOT NULL,
    `user_id` CHAR(36) NOT NULL,
    `route` TEXT NOT NULL,
    `status_code` INTEGER NOT NULL,
    `response` JSON NOT NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),

    INDEX `idempotency_keys_created_at_idx`(`created_at`),
    PRIMARY KEY (`key`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `proposals` (
    `id` CHAR(36) NOT NULL,
    `number` INTEGER NOT NULL AUTO_INCREMENT,
    `deal_id` CHAR(36) NOT NULL,
    `client_id` CHAR(36) NOT NULL,
    `manager_id` CHAR(36) NOT NULL,
    `title` TEXT NOT NULL,
    `description` TEXT NULL,
    `status` ENUM('DRAFT', 'SENT', 'VIEWED', 'IN_APPROVAL', 'ACCEPTED', 'REJECTED', 'EXPIRED') NOT NULL DEFAULT 'DRAFT',
    `currency` ENUM('UZS', 'USD') NOT NULL DEFAULT 'UZS',
    `subtotal` DECIMAL(18, 2) NOT NULL DEFAULT 0,
    `discount_amount` DECIMAL(18, 2) NOT NULL DEFAULT 0,
    `total` DECIMAL(18, 2) NOT NULL DEFAULT 0,
    `exchange_rate` DECIMAL(18, 6) NOT NULL DEFAULT 1,
    `total_uzs` DECIMAL(18, 2) NOT NULL DEFAULT 0,
    `implementation_term` TEXT NULL,
    `payment_terms` TEXT NULL,
    `valid_until` DATE NULL,
    `current_version` INTEGER NOT NULL DEFAULT 1,
    `approved_by_id` CHAR(36) NULL,
    `approved_at` DATETIME(3) NULL,
    `sent_at` DATETIME(3) NULL,
    `viewed_at` DATETIME(3) NULL,
    `accepted_at` DATETIME(3) NULL,
    `rejected_at` DATETIME(3) NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL,

    UNIQUE INDEX `proposals_number_key`(`number`),
    INDEX `proposals_deal_id_idx`(`deal_id`),
    INDEX `proposals_status_idx`(`status`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `proposal_items` (
    `id` CHAR(36) NOT NULL,
    `proposal_id` CHAR(36) NOT NULL,
    `service_id` CHAR(36) NULL,
    `tariff_id` CHAR(36) NULL,
    `description` TEXT NOT NULL,
    `quantity` DECIMAL(12, 2) NOT NULL,
    `unit_price` DECIMAL(18, 2) NOT NULL,
    `discount_pct` DECIMAL(5, 2) NOT NULL DEFAULT 0,
    `total` DECIMAL(18, 2) NOT NULL,
    `sort` INTEGER NOT NULL DEFAULT 0,

    INDEX `proposal_items_proposal_id_idx`(`proposal_id`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `proposal_versions` (
    `id` CHAR(36) NOT NULL,
    `proposal_id` CHAR(36) NOT NULL,
    `version` INTEGER NOT NULL,
    `snapshot` JSON NOT NULL,
    `total` DECIMAL(18, 2) NOT NULL,
    `currency` ENUM('UZS', 'USD') NOT NULL,
    `author_id` CHAR(36) NOT NULL,
    `comment` TEXT NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),

    UNIQUE INDEX `proposal_versions_proposal_id_version_key`(`proposal_id`, `version`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `contracts` (
    `id` CHAR(36) NOT NULL,
    `number` INTEGER NOT NULL AUTO_INCREMENT,
    `deal_id` CHAR(36) NOT NULL,
    `client_id` CHAR(36) NOT NULL,
    `proposal_id` CHAR(36) NULL,
    `contract_date` DATE NOT NULL,
    `amount` DECIMAL(18, 2) NOT NULL,
    `currency` ENUM('UZS', 'USD') NOT NULL DEFAULT 'UZS',
    `exchange_rate` DECIMAL(18, 6) NOT NULL DEFAULT 1,
    `amount_uzs` DECIMAL(18, 2) NOT NULL,
    `status` ENUM('DRAFT', 'SENT', 'IN_APPROVAL', 'SIGNED', 'CANCELLED') NOT NULL DEFAULT 'DRAFT',
    `signed_at` DATETIME(3) NULL,
    `comment` TEXT NULL,
    `created_by_id` CHAR(36) NOT NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL,

    UNIQUE INDEX `contracts_number_key`(`number`),
    INDEX `contracts_deal_id_idx`(`deal_id`),
    INDEX `contracts_status_idx`(`status`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `payments` (
    `id` CHAR(36) NOT NULL,
    `number` INTEGER NOT NULL AUTO_INCREMENT,
    `deal_id` CHAR(36) NOT NULL,
    `client_id` CHAR(36) NOT NULL,
    `contract_id` CHAR(36) NULL,
    `project_id` CHAR(36) NULL,
    `refund_of_id` CHAR(36) NULL,
    `amount` DECIMAL(18, 2) NOT NULL,
    `currency` ENUM('UZS', 'USD') NOT NULL DEFAULT 'UZS',
    `exchange_rate` DECIMAL(18, 6) NOT NULL DEFAULT 1,
    `amount_uzs` DECIMAL(18, 2) NOT NULL,
    `type` ENUM('PREPAYMENT', 'PARTIAL', 'FULL', 'FINAL', 'REFUND') NOT NULL,
    `method` ENUM('CASH', 'BANK', 'CARD', 'TRANSFER', 'OTHER') NOT NULL,
    `status` ENUM('PENDING', 'PAID', 'CANCELLED') NOT NULL DEFAULT 'PENDING',
    `due_date` DATE NULL,
    `paid_at` DATETIME(3) NULL,
    `comment` TEXT NULL,
    `confirmed_by_id` CHAR(36) NULL,
    `confirmed_at` DATETIME(3) NULL,
    `created_by_id` CHAR(36) NOT NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL,

    UNIQUE INDEX `payments_number_key`(`number`),
    INDEX `payments_deal_id_idx`(`deal_id`),
    INDEX `payments_status_paid_at_idx`(`status`, `paid_at`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `projects` (
    `id` CHAR(36) NOT NULL,
    `number` INTEGER NOT NULL AUTO_INCREMENT,
    `name` TEXT NOT NULL,
    `client_id` CHAR(36) NOT NULL,
    `deal_id` CHAR(36) NOT NULL,
    `price` DECIMAL(18, 2) NOT NULL,
    `currency` ENUM('UZS', 'USD') NOT NULL DEFAULT 'UZS',
    `price_uzs` DECIMAL(18, 2) NOT NULL,
    `rop_id` CHAR(36) NOT NULL,
    `manager_id` CHAR(36) NOT NULL,
    `team_id` CHAR(36) NULL,
    `status` ENUM('NEW', 'PLANNING', 'IN_PROGRESS', 'REVIEW', 'WAITING_CLIENT', 'PAUSED', 'COMPLETED', 'CANCELLED') NOT NULL DEFAULT 'NEW',
    `priority` ENUM('LOW', 'MEDIUM', 'HIGH', 'URGENT') NOT NULL DEFAULT 'MEDIUM',
    `start_date` DATE NULL,
    `deadline` DATE NULL,
    `description` TEXT NULL,
    `template_id` CHAR(36) NULL,
    `direction_id` CHAR(36) NULL,
    `completed_at` DATETIME(3) NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL,
    `deleted_at` DATETIME(3) NULL,

    UNIQUE INDEX `projects_number_key`(`number`),
    UNIQUE INDEX `projects_deal_id_key`(`deal_id`),
    INDEX `projects_rop_id_idx`(`rop_id`),
    INDEX `projects_manager_id_idx`(`manager_id`),
    INDEX `projects_status_idx`(`status`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `project_members` (
    `id` CHAR(36) NOT NULL,
    `project_id` CHAR(36) NOT NULL,
    `user_id` CHAR(36) NOT NULL,
    `role` ENUM('SMM', 'DESIGNER', 'VIDEOGRAPHER', 'EDITOR', 'TARGETOLOGIST', 'DEVELOPER', 'PHOTOGRAPHER', 'COPYWRITER', 'MOBILOGRAPHER', 'BRANDFACE') NULL,
    `workload_pct` INTEGER NULL,
    `deadline` DATE NULL,
    `status` ENUM('ACTIVE', 'DONE', 'REMOVED') NOT NULL DEFAULT 'ACTIVE',
    `assigned_by_id` CHAR(36) NOT NULL,
    `assigned_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL,

    INDEX `project_members_user_id_status_idx`(`user_id`, `status`),
    UNIQUE INDEX `project_members_project_id_user_id_key`(`project_id`, `user_id`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `tasks` (
    `id` CHAR(36) NOT NULL,
    `number` INTEGER NOT NULL AUTO_INCREMENT,
    `project_id` CHAR(36) NOT NULL,
    `title` TEXT NOT NULL,
    `description` TEXT NULL,
    `assignee_id` CHAR(36) NOT NULL,
    `creator_id` CHAR(36) NOT NULL,
    `template_role` ENUM('SMM', 'DESIGNER', 'VIDEOGRAPHER', 'EDITOR', 'TARGETOLOGIST', 'DEVELOPER', 'PHOTOGRAPHER', 'COPYWRITER', 'MOBILOGRAPHER', 'BRANDFACE') NULL,
    `priority` ENUM('LOW', 'MEDIUM', 'HIGH', 'URGENT') NOT NULL DEFAULT 'MEDIUM',
    `status` ENUM('TODO', 'IN_PROGRESS', 'REVIEW', 'DONE', 'BLOCKED', 'CANCELLED') NOT NULL DEFAULT 'TODO',
    `start_date` DATE NULL,
    `deadline` DATETIME(3) NULL,
    `progress_pct` INTEGER NOT NULL DEFAULT 0,
    `sort_order` DOUBLE NOT NULL DEFAULT 0,
    `started_at` DATETIME(3) NULL,
    `completed_at` DATETIME(3) NULL,
    `rework_count` INTEGER NOT NULL DEFAULT 0,
    `overdue_notified_at` DATETIME(3) NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL,
    `deleted_at` DATETIME(3) NULL,

    UNIQUE INDEX `tasks_number_key`(`number`),
    INDEX `tasks_project_id_status_sort_order_idx`(`project_id`, `status`, `sort_order`),
    INDEX `tasks_assignee_id_status_idx`(`assignee_id`, `status`),
    INDEX `tasks_deadline_idx`(`deadline`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `task_status_history` (
    `id` CHAR(36) NOT NULL,
    `task_id` CHAR(36) NOT NULL,
    `from_status` ENUM('TODO', 'IN_PROGRESS', 'REVIEW', 'DONE', 'BLOCKED', 'CANCELLED') NULL,
    `to_status` ENUM('TODO', 'IN_PROGRESS', 'REVIEW', 'DONE', 'BLOCKED', 'CANCELLED') NOT NULL,
    `changed_by_id` CHAR(36) NOT NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),

    INDEX `task_status_history_task_id_created_at_idx`(`task_id`, `created_at`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `task_comments` (
    `id` CHAR(36) NOT NULL,
    `task_id` CHAR(36) NOT NULL,
    `author_id` CHAR(36) NOT NULL,
    `body` TEXT NOT NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL,
    `deleted_at` DATETIME(3) NULL,

    INDEX `task_comments_task_id_created_at_idx`(`task_id`, `created_at`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `project_templates` (
    `id` CHAR(36) NOT NULL,
    `name` TEXT NOT NULL,
    `service_id` CHAR(36) NULL,
    `description` TEXT NULL,
    `is_active` BOOLEAN NOT NULL DEFAULT true,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL,

    INDEX `project_templates_service_id_idx`(`service_id`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `task_templates` (
    `id` CHAR(36) NOT NULL,
    `project_template_id` CHAR(36) NOT NULL,
    `title` TEXT NOT NULL,
    `description` TEXT NULL,
    `role` ENUM('SMM', 'DESIGNER', 'VIDEOGRAPHER', 'EDITOR', 'TARGETOLOGIST', 'DEVELOPER', 'PHOTOGRAPHER', 'COPYWRITER', 'MOBILOGRAPHER', 'BRANDFACE') NULL,
    `start_offset_days` INTEGER NOT NULL DEFAULT 0,
    `duration_days` INTEGER NOT NULL DEFAULT 1,
    `priority` ENUM('LOW', 'MEDIUM', 'HIGH', 'URGENT') NOT NULL DEFAULT 'MEDIUM',
    `sort` INTEGER NOT NULL DEFAULT 0,

    INDEX `task_templates_project_template_id_idx`(`project_template_id`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `expenses` (
    `id` CHAR(36) NOT NULL,
    `number` INTEGER NOT NULL AUTO_INCREMENT,
    `scope` ENUM('PROJECT', 'COMPANY') NOT NULL,
    `project_id` CHAR(36) NULL,
    `category` VARCHAR(191) NOT NULL,
    `amount` DECIMAL(18, 2) NOT NULL,
    `currency` ENUM('UZS', 'USD') NOT NULL DEFAULT 'UZS',
    `exchange_rate` DECIMAL(18, 6) NOT NULL,
    `amount_uzs` DECIMAL(18, 2) NOT NULL,
    `expense_date` DATE NOT NULL,
    `payee_user_id` CHAR(36) NULL,
    `description` TEXT NULL,
    `created_by_id` CHAR(36) NOT NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL,
    `deleted_at` DATETIME(3) NULL,

    UNIQUE INDEX `expenses_number_key`(`number`),
    INDEX `expenses_project_id_idx`(`project_id`),
    INDEX `expenses_scope_expense_date_idx`(`scope`, `expense_date`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `kpi_targets` (
    `id` CHAR(36) NOT NULL,
    `user_id` CHAR(36) NOT NULL,
    `period` VARCHAR(191) NOT NULL,
    `metric` ENUM('REVENUE', 'ORDERS', 'LEADS', 'MEETINGS', 'TASKS') NOT NULL,
    `target_value` DECIMAL(18, 2) NOT NULL,
    `currency` ENUM('UZS', 'USD') NOT NULL DEFAULT 'UZS',
    `set_by_id` CHAR(36) NOT NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL,

    INDEX `kpi_targets_period_idx`(`period`),
    UNIQUE INDEX `kpi_targets_user_id_period_metric_key`(`user_id`, `period`, `metric`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `work_schedules` (
    `id` CHAR(36) NOT NULL,
    `name` TEXT NOT NULL,
    `role_code` ENUM('CEO', 'ROP', 'MANAGER', 'EXECUTOR', 'HR_ADMIN', 'PROJECT_MANAGER') NULL,
    `start_time` TEXT NOT NULL,
    `end_time` TEXT NOT NULL,
    `work_days` JSON NOT NULL,
    `grace_minutes` INTEGER NOT NULL DEFAULT 0,
    `is_active` BOOLEAN NOT NULL DEFAULT true,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL,

    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `attendance` (
    `id` CHAR(36) NOT NULL,
    `user_id` CHAR(36) NOT NULL,
    `date` DATE NOT NULL,
    `check_in` DATETIME(3) NULL,
    `check_out` DATETIME(3) NULL,
    `status` ENUM('PRESENT', 'LATE', 'ABSENT', 'DAY_OFF', 'VACATION', 'SICK') NOT NULL,
    `late_minutes` INTEGER NOT NULL DEFAULT 0,
    `work_minutes` INTEGER NOT NULL DEFAULT 0,
    `comment` TEXT NULL,
    `edited_by_id` CHAR(36) NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL,

    INDEX `attendance_date_idx`(`date`),
    UNIQUE INDEX `attendance_user_id_date_key`(`user_id`, `date`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `payroll_entries` (
    `id` CHAR(36) NOT NULL,
    `user_id` CHAR(36) NOT NULL,
    `period` VARCHAR(191) NOT NULL,
    `base_salary` DECIMAL(18, 2) NOT NULL DEFAULT 0,
    `piece_rate` DECIMAL(18, 2) NOT NULL DEFAULT 0,
    `kpi_bonus` DECIMAL(18, 2) NOT NULL DEFAULT 0,
    `commission` DECIMAL(18, 2) NOT NULL DEFAULT 0,
    `other_bonus` DECIMAL(18, 2) NOT NULL DEFAULT 0,
    `penalty` DECIMAL(18, 2) NOT NULL DEFAULT 0,
    `final_salary` DECIMAL(18, 2) NOT NULL DEFAULT 0,
    `kpi_pct` DECIMAL(8, 2) NULL,
    `status` ENUM('DRAFT', 'APPROVED', 'PAID') NOT NULL DEFAULT 'DRAFT',
    `comment` TEXT NULL,
    `approved_by_id` CHAR(36) NULL,
    `approved_at` DATETIME(3) NULL,
    `paid_at` DATETIME(3) NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL,

    INDEX `payroll_entries_period_idx`(`period`),
    UNIQUE INDEX `payroll_entries_user_id_period_key`(`user_id`, `period`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `telegram_link_tokens` (
    `id` CHAR(36) NOT NULL,
    `token_hash` VARCHAR(191) NOT NULL,
    `user_id` CHAR(36) NOT NULL,
    `expires_at` DATETIME(3) NOT NULL,
    `used_at` DATETIME(3) NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),

    UNIQUE INDEX `telegram_link_tokens_token_hash_key`(`token_hash`),
    INDEX `telegram_link_tokens_user_id_idx`(`user_id`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `notification_settings` (
    `user_id` CHAR(36) NOT NULL,
    `event_type` VARCHAR(191) NOT NULL,
    `channel` ENUM('IN_APP', 'TELEGRAM') NOT NULL,
    `enabled` BOOLEAN NOT NULL,

    PRIMARY KEY (`user_id`, `event_type`, `channel`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `notification_deliveries` (
    `id` CHAR(36) NOT NULL,
    `user_id` CHAR(36) NOT NULL,
    `channel` ENUM('IN_APP', 'TELEGRAM') NOT NULL,
    `type` TEXT NOT NULL,
    `title` TEXT NOT NULL,
    `body` TEXT NULL,
    `link` TEXT NULL,
    `status` ENUM('PENDING', 'SENT', 'FAILED') NOT NULL DEFAULT 'PENDING',
    `attempts` INTEGER NOT NULL DEFAULT 0,
    `last_error` TEXT NULL,
    `next_attempt_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `sent_at` DATETIME(3) NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),

    INDEX `notification_deliveries_status_next_attempt_at_idx`(`status`, `next_attempt_at`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `reminder_log` (
    `id` CHAR(36) NOT NULL,
    `kind` VARCHAR(191) NOT NULL,
    `dedupe_key` VARCHAR(191) NOT NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),

    UNIQUE INDEX `reminder_log_dedupe_key_key`(`dedupe_key`),
    INDEX `reminder_log_kind_created_at_idx`(`kind`, `created_at`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `follow_ups` (
    `id` CHAR(36) NOT NULL,
    `client_id` CHAR(36) NOT NULL,
    `project_id` CHAR(36) NULL,
    `owner_id` CHAR(36) NOT NULL,
    `kind` ENUM('CONTACT', 'NEW_PROJECT', 'REPEAT_SALE') NOT NULL,
    `due_date` DATE NOT NULL,
    `status` ENUM('PENDING', 'DONE', 'SKIPPED') NOT NULL DEFAULT 'PENDING',
    `result` TEXT NULL,
    `result_deal_id` CHAR(36) NULL,
    `notified_at` DATETIME(3) NULL,
    `completed_at` DATETIME(3) NULL,
    `completed_by_id` CHAR(36) NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL,

    INDEX `follow_ups_status_due_date_idx`(`status`, `due_date`),
    INDEX `follow_ups_owner_id_status_idx`(`owner_id`, `status`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `settings` (
    `key` VARCHAR(191) NOT NULL,
    `value` JSON NOT NULL,
    `updated_by_id` CHAR(36) NULL,
    `updated_at` DATETIME(3) NOT NULL,

    PRIMARY KEY (`key`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `job_runs` (
    `id` CHAR(36) NOT NULL,
    `job` VARCHAR(191) NOT NULL,
    `slot` VARCHAR(191) NOT NULL,
    `started_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `finished_at` DATETIME(3) NULL,
    `error` TEXT NULL,
    `result` JSON NULL,

    INDEX `job_runs_job_started_at_idx`(`job`, `started_at`),
    UNIQUE INDEX `job_runs_job_slot_key`(`job`, `slot`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `commission_rules` (
    `id` CHAR(36) NOT NULL,
    `name` TEXT NOT NULL,
    `applies_to` ENUM('MANAGER', 'ROP') NOT NULL,
    `user_id` CHAR(36) NULL,
    `calc_type` ENUM('PERCENT_OF_PAYMENT', 'PERCENT_OF_PROFIT', 'FIXED_PER_DEAL') NOT NULL,
    `value` DECIMAL(18, 4) NOT NULL,
    `conditions` JSON NULL,
    `priority` INTEGER NOT NULL DEFAULT 0,
    `is_active` BOOLEAN NOT NULL DEFAULT true,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL,

    INDEX `commission_rules_applies_to_is_active_idx`(`applies_to`, `is_active`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `commissions` (
    `id` CHAR(36) NOT NULL,
    `user_id` CHAR(36) NOT NULL,
    `payment_id` CHAR(36) NOT NULL,
    `deal_id` CHAR(36) NOT NULL,
    `rule_id` CHAR(36) NOT NULL,
    `role` ENUM('MANAGER', 'ROP') NOT NULL,
    `period` VARCHAR(191) NOT NULL,
    `base_amount_uzs` DECIMAL(18, 2) NOT NULL,
    `rate` DECIMAL(18, 4) NOT NULL,
    `amount_uzs` DECIMAL(18, 2) NOT NULL,
    `status` ENUM('ACCRUED', 'APPROVED', 'PAID', 'CANCELLED') NOT NULL DEFAULT 'ACCRUED',
    `calc_snapshot` JSON NOT NULL,
    `approved_by_id` CHAR(36) NULL,
    `approved_at` DATETIME(3) NULL,
    `paid_by_id` CHAR(36) NULL,
    `paid_at` DATETIME(3) NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL,

    INDEX `commissions_user_id_period_idx`(`user_id`, `period`),
    UNIQUE INDEX `commissions_payment_id_user_id_role_key`(`payment_id`, `user_id`, `role`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `files` (
    `id` CHAR(36) NOT NULL,
    `storage_key` VARCHAR(191) NOT NULL,
    `original_name` TEXT NOT NULL,
    `mime_type` TEXT NOT NULL,
    `size_bytes` INTEGER NOT NULL,
    `checksum` TEXT NOT NULL,
    `category` ENUM('PROPOSAL', 'CONTRACT', 'PAYMENT', 'PHOTO', 'VIDEO', 'DESIGN', 'DOCUMENT', 'OTHER') NOT NULL DEFAULT 'DOCUMENT',
    `uploaded_by_id` CHAR(36) NOT NULL,
    `deal_id` CHAR(36) NULL,
    `proposal_id` CHAR(36) NULL,
    `contract_id` CHAR(36) NULL,
    `payment_id` CHAR(36) NULL,
    `project_id` CHAR(36) NULL,
    `task_id` CHAR(36) NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `deleted_at` DATETIME(3) NULL,

    UNIQUE INDEX `files_storage_key_key`(`storage_key`),
    INDEX `files_deal_id_idx`(`deal_id`),
    INDEX `files_contract_id_idx`(`contract_id`),
    INDEX `files_project_id_idx`(`project_id`),
    INDEX `files_task_id_idx`(`task_id`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `todos` (
    `id` CHAR(36) NOT NULL,
    `number` INTEGER NOT NULL AUTO_INCREMENT,
    `title` TEXT NOT NULL,
    `description` TEXT NULL,
    `kind` ENUM('TASK', 'CALL', 'EMAIL', 'MEETING', 'PAYMENT', 'REPORT') NOT NULL DEFAULT 'TASK',
    `priority` ENUM('LOW', 'MEDIUM', 'HIGH', 'URGENT') NOT NULL DEFAULT 'MEDIUM',
    `status` ENUM('OPEN', 'DONE', 'CANCELLED') NOT NULL DEFAULT 'OPEN',
    `owner_id` CHAR(36) NOT NULL,
    `creator_id` CHAR(36) NOT NULL,
    `due_at` DATETIME(3) NULL,
    `client_id` CHAR(36) NULL,
    `deal_id` CHAR(36) NULL,
    `lead_id` CHAR(36) NULL,
    `recurring_id` CHAR(36) NULL,
    `recurring_due` DATE NULL,
    `completed_at` DATETIME(3) NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL,
    `deleted_at` DATETIME(3) NULL,

    UNIQUE INDEX `todos_number_key`(`number`),
    INDEX `todos_owner_id_status_due_at_idx`(`owner_id`, `status`, `due_at`),
    UNIQUE INDEX `todos_recurring_id_recurring_due_key`(`recurring_id`, `recurring_due`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `recurring_todos` (
    `id` CHAR(36) NOT NULL,
    `title` TEXT NOT NULL,
    `description` TEXT NULL,
    `kind` ENUM('TASK', 'CALL', 'EMAIL', 'MEETING', 'PAYMENT', 'REPORT') NOT NULL DEFAULT 'REPORT',
    `priority` ENUM('LOW', 'MEDIUM', 'HIGH', 'URGENT') NOT NULL DEFAULT 'HIGH',
    `owner_id` CHAR(36) NOT NULL,
    `created_by_id` CHAR(36) NOT NULL,
    `frequency` ENUM('MONTHLY', 'QUARTERLY', 'YEARLY') NOT NULL,
    `day_of_month` INTEGER NOT NULL,
    `month` INTEGER NULL,
    `remind_days_before` INTEGER NOT NULL DEFAULT 3,
    `is_active` BOOLEAN NOT NULL DEFAULT true,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL,
    `deleted_at` DATETIME(3) NULL,

    INDEX `recurring_todos_owner_id_idx`(`owner_id`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `conversations` (
    `id` CHAR(36) NOT NULL,
    `direct_key` VARCHAR(191) NULL,
    `last_message_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),

    UNIQUE INDEX `conversations_direct_key_key`(`direct_key`),
    INDEX `conversations_last_message_at_idx`(`last_message_at`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `conversation_members` (
    `conversation_id` CHAR(36) NOT NULL,
    `user_id` CHAR(36) NOT NULL,
    `last_read_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `notified_at` DATETIME(3) NULL,
    `joined_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),

    INDEX `conversation_members_user_id_idx`(`user_id`),
    PRIMARY KEY (`conversation_id`, `user_id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `chat_messages` (
    `id` CHAR(36) NOT NULL,
    `conversation_id` CHAR(36) NOT NULL,
    `author_id` CHAR(36) NOT NULL,
    `body` TEXT NOT NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),

    INDEX `chat_messages_conversation_id_created_at_idx`(`conversation_id`, `created_at`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `finance_categories` (
    `id` CHAR(36) NOT NULL,
    `code` VARCHAR(191) NOT NULL,
    `kind` ENUM('EXPENSE', 'INCOME') NOT NULL,
    `name` TEXT NOT NULL,
    `account_hint` TEXT NULL,
    `is_overhead` BOOLEAN NOT NULL DEFAULT false,
    `is_active` BOOLEAN NOT NULL DEFAULT true,
    `sort` INTEGER NOT NULL DEFAULT 0,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),

    UNIQUE INDEX `finance_categories_code_key`(`code`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `other_incomes` (
    `id` CHAR(36) NOT NULL,
    `number` INTEGER NOT NULL AUTO_INCREMENT,
    `category` VARCHAR(191) NOT NULL,
    `amount` DECIMAL(18, 2) NOT NULL,
    `currency` ENUM('UZS', 'USD') NOT NULL DEFAULT 'UZS',
    `exchange_rate` DECIMAL(18, 6) NOT NULL,
    `amount_uzs` DECIMAL(18, 2) NOT NULL,
    `income_date` DATE NOT NULL,
    `project_id` CHAR(36) NULL,
    `client_id` CHAR(36) NULL,
    `description` TEXT NULL,
    `created_by_id` CHAR(36) NOT NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL,
    `deleted_at` DATETIME(3) NULL,

    UNIQUE INDEX `other_incomes_number_key`(`number`),
    INDEX `other_incomes_income_date_idx`(`income_date`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `work_items` (
    `id` CHAR(36) NOT NULL,
    `code` VARCHAR(191) NOT NULL,
    `name` TEXT NOT NULL,
    `unit` VARCHAR(191) NOT NULL DEFAULT 'шт',
    `specialty` ENUM('SMM', 'DESIGNER', 'VIDEOGRAPHER', 'EDITOR', 'TARGETOLOGIST', 'DEVELOPER', 'PHOTOGRAPHER', 'COPYWRITER', 'MOBILOGRAPHER', 'BRANDFACE') NULL,
    `default_rate` DECIMAL(18, 2) NOT NULL DEFAULT 0,
    `currency` ENUM('UZS', 'USD') NOT NULL DEFAULT 'UZS',
    `is_active` BOOLEAN NOT NULL DEFAULT true,
    `sort` INTEGER NOT NULL DEFAULT 0,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL,

    UNIQUE INDEX `work_items_code_key`(`code`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `employee_rates` (
    `user_id` CHAR(36) NOT NULL,
    `work_item_id` CHAR(36) NOT NULL,
    `rate` DECIMAL(18, 2) NOT NULL,
    `currency` ENUM('UZS', 'USD') NOT NULL DEFAULT 'UZS',
    `updated_at` DATETIME(3) NOT NULL,

    PRIMARY KEY (`user_id`, `work_item_id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `tariffs` (
    `id` CHAR(36) NOT NULL,
    `service_id` CHAR(36) NOT NULL,
    `name` TEXT NOT NULL,
    `description` TEXT NULL,
    `price` DECIMAL(18, 2) NOT NULL,
    `currency` ENUM('UZS', 'USD') NOT NULL DEFAULT 'UZS',
    `is_active` BOOLEAN NOT NULL DEFAULT true,
    `sort` INTEGER NOT NULL DEFAULT 0,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL,

    INDEX `tariffs_service_id_idx`(`service_id`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `tariff_items` (
    `id` CHAR(36) NOT NULL,
    `tariff_id` CHAR(36) NOT NULL,
    `kind` ENUM('PIECE', 'FIXED') NOT NULL,
    `work_item_id` CHAR(36) NULL,
    `quantity` DECIMAL(12, 2) NOT NULL DEFAULT 1,
    `specialty` ENUM('SMM', 'DESIGNER', 'VIDEOGRAPHER', 'EDITOR', 'TARGETOLOGIST', 'DEVELOPER', 'PHOTOGRAPHER', 'COPYWRITER', 'MOBILOGRAPHER', 'BRANDFACE') NULL,
    `amount` DECIMAL(18, 2) NULL,
    `currency` ENUM('UZS', 'USD') NOT NULL DEFAULT 'UZS',
    `label` TEXT NULL,
    `sort` INTEGER NOT NULL DEFAULT 0,

    INDEX `tariff_items_tariff_id_idx`(`tariff_id`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `project_cost_lines` (
    `id` CHAR(36) NOT NULL,
    `project_id` CHAR(36) NOT NULL,
    `tariff_id` CHAR(36) NULL,
    `tariff_item_id` CHAR(36) NULL,
    `kind` ENUM('PIECE', 'FIXED') NOT NULL,
    `work_item_id` CHAR(36) NULL,
    `specialty` ENUM('SMM', 'DESIGNER', 'VIDEOGRAPHER', 'EDITOR', 'TARGETOLOGIST', 'DEVELOPER', 'PHOTOGRAPHER', 'COPYWRITER', 'MOBILOGRAPHER', 'BRANDFACE') NULL,
    `label` TEXT NOT NULL,
    `quantity` DECIMAL(12, 2) NOT NULL,
    `rate` DECIMAL(18, 2) NOT NULL,
    `currency` ENUM('UZS', 'USD') NOT NULL DEFAULT 'UZS',
    `assignee_id` CHAR(36) NULL,
    `status` ENUM('PLANNED', 'ACCRUED', 'CANCELLED') NOT NULL DEFAULT 'PLANNED',
    `expense_id` CHAR(36) NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL,

    UNIQUE INDEX `project_cost_lines_expense_id_key`(`expense_id`),
    INDEX `project_cost_lines_project_id_idx`(`project_id`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `directions` (
    `id` CHAR(36) NOT NULL,
    `code` VARCHAR(191) NOT NULL,
    `name` TEXT NOT NULL,
    `sort` INTEGER NOT NULL DEFAULT 0,
    `is_active` BOOLEAN NOT NULL DEFAULT true,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL,

    UNIQUE INDEX `directions_code_key`(`code`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `user_directions` (
    `user_id` CHAR(36) NOT NULL,
    `direction_id` CHAR(36) NOT NULL,

    PRIMARY KEY (`user_id`, `direction_id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `lead_forms` (
    `id` CHAR(36) NOT NULL,
    `key` VARCHAR(191) NOT NULL,
    `name` TEXT NOT NULL,
    `title` TEXT NOT NULL,
    `description` TEXT NULL,
    `button_text` VARCHAR(191) NOT NULL DEFAULT 'Отправить',
    `success_message` VARCHAR(191) NOT NULL DEFAULT 'Спасибо! Мы свяжемся с вами в ближайшее время.',
    `fields` JSON NOT NULL,
    `service_id` CHAR(36) NULL,
    `source_id` CHAR(36) NULL,
    `owner_id` CHAR(36) NULL,
    `team_id` CHAR(36) NULL,
    `is_active` BOOLEAN NOT NULL DEFAULT true,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL,
    `deleted_at` DATETIME(3) NULL,

    UNIQUE INDEX `lead_forms_key_key`(`key`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `form_submissions` (
    `id` CHAR(36) NOT NULL,
    `form_id` CHAR(36) NOT NULL,
    `lead_id` CHAR(36) NULL,
    `data` JSON NOT NULL,
    `utm` JSON NULL,
    `page` TEXT NULL,
    `ip` TEXT NULL,
    `result` ENUM('LEAD_CREATED', 'DUPLICATE', 'SPAM') NOT NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),

    INDEX `form_submissions_form_id_created_at_idx`(`form_id`, `created_at`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `social_threads` (
    `id` CHAR(36) NOT NULL,
    `channel` ENUM('INSTAGRAM_DM', 'INSTAGRAM_COMMENT', 'FACEBOOK_COMMENT') NOT NULL,
    `peer_id` VARCHAR(191) NOT NULL,
    `peer_name` TEXT NULL,
    `peer_username` TEXT NULL,
    `lead_id` CHAR(36) NULL,
    `owner_id` CHAR(36) NULL,
    `team_id` CHAR(36) NULL,
    `unread` INTEGER NOT NULL DEFAULT 0,
    `last_message_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),

    INDEX `social_threads_owner_id_last_message_at_idx`(`owner_id`, `last_message_at`),
    UNIQUE INDEX `social_threads_channel_peer_id_key`(`channel`, `peer_id`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `social_messages` (
    `id` CHAR(36) NOT NULL,
    `thread_id` CHAR(36) NOT NULL,
    `direction` TEXT NOT NULL,
    `external_id` VARCHAR(191) NULL,
    `text` TEXT NOT NULL,
    `media_id` TEXT NULL,
    `author_id` CHAR(36) NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),

    UNIQUE INDEX `social_messages_external_id_key`(`external_id`),
    INDEX `social_messages_thread_id_created_at_idx`(`thread_id`, `created_at`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- AddForeignKey
ALTER TABLE `users` ADD CONSTRAINT `users_role_id_fkey` FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `users` ADD CONSTRAINT `users_team_id_fkey` FOREIGN KEY (`team_id`) REFERENCES `teams`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `role_permissions` ADD CONSTRAINT `role_permissions_role_id_fkey` FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `role_permissions` ADD CONSTRAINT `role_permissions_permission_id_fkey` FOREIGN KEY (`permission_id`) REFERENCES `permissions`(`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `teams` ADD CONSTRAINT `teams_head_id_fkey` FOREIGN KEY (`head_id`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `employees` ADD CONSTRAINT `employees_user_id_fkey` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `employees` ADD CONSTRAINT `employees_schedule_id_fkey` FOREIGN KEY (`schedule_id`) REFERENCES `work_schedules`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `sessions` ADD CONSTRAINT `sessions_user_id_fkey` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `audit_logs` ADD CONSTRAINT `audit_logs_actor_id_fkey` FOREIGN KEY (`actor_id`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `services` ADD CONSTRAINT `services_direction_id_fkey` FOREIGN KEY (`direction_id`) REFERENCES `directions`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `exchange_rates` ADD CONSTRAINT `exchange_rates_set_by_id_fkey` FOREIGN KEY (`set_by_id`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `leads` ADD CONSTRAINT `leads_source_id_fkey` FOREIGN KEY (`source_id`) REFERENCES `lead_sources`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `leads` ADD CONSTRAINT `leads_owner_id_fkey` FOREIGN KEY (`owner_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `leads` ADD CONSTRAINT `leads_team_id_fkey` FOREIGN KEY (`team_id`) REFERENCES `teams`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `leads` ADD CONSTRAINT `leads_service_id_fkey` FOREIGN KEY (`service_id`) REFERENCES `services`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `leads` ADD CONSTRAINT `leads_stage_id_fkey` FOREIGN KEY (`stage_id`) REFERENCES `deal_stages`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `leads` ADD CONSTRAINT `leads_client_id_fkey` FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `leads` ADD CONSTRAINT `leads_deal_id_fkey` FOREIGN KEY (`deal_id`) REFERENCES `deals`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `leads` ADD CONSTRAINT `leads_loss_reason_id_fkey` FOREIGN KEY (`loss_reason_id`) REFERENCES `loss_reasons`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `leads` ADD CONSTRAINT `leads_created_by_id_fkey` FOREIGN KEY (`created_by_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `clients` ADD CONSTRAINT `clients_owner_id_fkey` FOREIGN KEY (`owner_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `clients` ADD CONSTRAINT `clients_team_id_fkey` FOREIGN KEY (`team_id`) REFERENCES `teams`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `clients` ADD CONSTRAINT `clients_source_id_fkey` FOREIGN KEY (`source_id`) REFERENCES `lead_sources`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `contacts` ADD CONSTRAINT `contacts_client_id_fkey` FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `deals` ADD CONSTRAINT `deals_client_id_fkey` FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `deals` ADD CONSTRAINT `deals_contact_id_fkey` FOREIGN KEY (`contact_id`) REFERENCES `contacts`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `deals` ADD CONSTRAINT `deals_owner_id_fkey` FOREIGN KEY (`owner_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `deals` ADD CONSTRAINT `deals_team_id_fkey` FOREIGN KEY (`team_id`) REFERENCES `teams`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `deals` ADD CONSTRAINT `deals_service_id_fkey` FOREIGN KEY (`service_id`) REFERENCES `services`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `deals` ADD CONSTRAINT `deals_stage_id_fkey` FOREIGN KEY (`stage_id`) REFERENCES `deal_stages`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `deals` ADD CONSTRAINT `deals_loss_reason_id_fkey` FOREIGN KEY (`loss_reason_id`) REFERENCES `loss_reasons`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `deals` ADD CONSTRAINT `deals_created_by_id_fkey` FOREIGN KEY (`created_by_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `stage_history` ADD CONSTRAINT `stage_history_lead_id_fkey` FOREIGN KEY (`lead_id`) REFERENCES `leads`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `stage_history` ADD CONSTRAINT `stage_history_deal_id_fkey` FOREIGN KEY (`deal_id`) REFERENCES `deals`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `stage_history` ADD CONSTRAINT `stage_history_from_stage_id_fkey` FOREIGN KEY (`from_stage_id`) REFERENCES `deal_stages`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `stage_history` ADD CONSTRAINT `stage_history_to_stage_id_fkey` FOREIGN KEY (`to_stage_id`) REFERENCES `deal_stages`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `stage_history` ADD CONSTRAINT `stage_history_changed_by_id_fkey` FOREIGN KEY (`changed_by_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `activities` ADD CONSTRAINT `activities_actor_id_fkey` FOREIGN KEY (`actor_id`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `activities` ADD CONSTRAINT `activities_lead_id_fkey` FOREIGN KEY (`lead_id`) REFERENCES `leads`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `activities` ADD CONSTRAINT `activities_deal_id_fkey` FOREIGN KEY (`deal_id`) REFERENCES `deals`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `activities` ADD CONSTRAINT `activities_client_id_fkey` FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `activities` ADD CONSTRAINT `activities_meeting_id_fkey` FOREIGN KEY (`meeting_id`) REFERENCES `meetings`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `activities` ADD CONSTRAINT `activities_project_id_fkey` FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `activities` ADD CONSTRAINT `activities_task_id_fkey` FOREIGN KEY (`task_id`) REFERENCES `tasks`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `comments` ADD CONSTRAINT `comments_author_id_fkey` FOREIGN KEY (`author_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `comments` ADD CONSTRAINT `comments_lead_id_fkey` FOREIGN KEY (`lead_id`) REFERENCES `leads`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `comments` ADD CONSTRAINT `comments_deal_id_fkey` FOREIGN KEY (`deal_id`) REFERENCES `deals`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `comments` ADD CONSTRAINT `comments_client_id_fkey` FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `meetings` ADD CONSTRAINT `meetings_lead_id_fkey` FOREIGN KEY (`lead_id`) REFERENCES `leads`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `meetings` ADD CONSTRAINT `meetings_deal_id_fkey` FOREIGN KEY (`deal_id`) REFERENCES `deals`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `meetings` ADD CONSTRAINT `meetings_client_id_fkey` FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `meetings` ADD CONSTRAINT `meetings_manager_id_fkey` FOREIGN KEY (`manager_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `meetings` ADD CONSTRAINT `meetings_rop_id_fkey` FOREIGN KEY (`rop_id`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `meetings` ADD CONSTRAINT `meetings_team_id_fkey` FOREIGN KEY (`team_id`) REFERENCES `teams`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `notifications` ADD CONSTRAINT `notifications_user_id_fkey` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `proposals` ADD CONSTRAINT `proposals_deal_id_fkey` FOREIGN KEY (`deal_id`) REFERENCES `deals`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `proposals` ADD CONSTRAINT `proposals_client_id_fkey` FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `proposals` ADD CONSTRAINT `proposals_manager_id_fkey` FOREIGN KEY (`manager_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `proposals` ADD CONSTRAINT `proposals_approved_by_id_fkey` FOREIGN KEY (`approved_by_id`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `proposal_items` ADD CONSTRAINT `proposal_items_proposal_id_fkey` FOREIGN KEY (`proposal_id`) REFERENCES `proposals`(`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `proposal_items` ADD CONSTRAINT `proposal_items_service_id_fkey` FOREIGN KEY (`service_id`) REFERENCES `services`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `proposal_items` ADD CONSTRAINT `proposal_items_tariff_id_fkey` FOREIGN KEY (`tariff_id`) REFERENCES `tariffs`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `proposal_versions` ADD CONSTRAINT `proposal_versions_proposal_id_fkey` FOREIGN KEY (`proposal_id`) REFERENCES `proposals`(`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `proposal_versions` ADD CONSTRAINT `proposal_versions_author_id_fkey` FOREIGN KEY (`author_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `contracts` ADD CONSTRAINT `contracts_deal_id_fkey` FOREIGN KEY (`deal_id`) REFERENCES `deals`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `contracts` ADD CONSTRAINT `contracts_client_id_fkey` FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `contracts` ADD CONSTRAINT `contracts_proposal_id_fkey` FOREIGN KEY (`proposal_id`) REFERENCES `proposals`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `contracts` ADD CONSTRAINT `contracts_created_by_id_fkey` FOREIGN KEY (`created_by_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `payments` ADD CONSTRAINT `payments_deal_id_fkey` FOREIGN KEY (`deal_id`) REFERENCES `deals`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `payments` ADD CONSTRAINT `payments_client_id_fkey` FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `payments` ADD CONSTRAINT `payments_contract_id_fkey` FOREIGN KEY (`contract_id`) REFERENCES `contracts`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `payments` ADD CONSTRAINT `payments_project_id_fkey` FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `payments` ADD CONSTRAINT `payments_refund_of_id_fkey` FOREIGN KEY (`refund_of_id`) REFERENCES `payments`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `payments` ADD CONSTRAINT `payments_confirmed_by_id_fkey` FOREIGN KEY (`confirmed_by_id`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `payments` ADD CONSTRAINT `payments_created_by_id_fkey` FOREIGN KEY (`created_by_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `projects` ADD CONSTRAINT `projects_client_id_fkey` FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `projects` ADD CONSTRAINT `projects_deal_id_fkey` FOREIGN KEY (`deal_id`) REFERENCES `deals`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `projects` ADD CONSTRAINT `projects_rop_id_fkey` FOREIGN KEY (`rop_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `projects` ADD CONSTRAINT `projects_manager_id_fkey` FOREIGN KEY (`manager_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `projects` ADD CONSTRAINT `projects_template_id_fkey` FOREIGN KEY (`template_id`) REFERENCES `project_templates`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `projects` ADD CONSTRAINT `projects_direction_id_fkey` FOREIGN KEY (`direction_id`) REFERENCES `directions`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `project_members` ADD CONSTRAINT `project_members_project_id_fkey` FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `project_members` ADD CONSTRAINT `project_members_user_id_fkey` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `project_members` ADD CONSTRAINT `project_members_assigned_by_id_fkey` FOREIGN KEY (`assigned_by_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `tasks` ADD CONSTRAINT `tasks_project_id_fkey` FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `tasks` ADD CONSTRAINT `tasks_assignee_id_fkey` FOREIGN KEY (`assignee_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `tasks` ADD CONSTRAINT `tasks_creator_id_fkey` FOREIGN KEY (`creator_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `task_status_history` ADD CONSTRAINT `task_status_history_task_id_fkey` FOREIGN KEY (`task_id`) REFERENCES `tasks`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `task_status_history` ADD CONSTRAINT `task_status_history_changed_by_id_fkey` FOREIGN KEY (`changed_by_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `task_comments` ADD CONSTRAINT `task_comments_task_id_fkey` FOREIGN KEY (`task_id`) REFERENCES `tasks`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `task_comments` ADD CONSTRAINT `task_comments_author_id_fkey` FOREIGN KEY (`author_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `project_templates` ADD CONSTRAINT `project_templates_service_id_fkey` FOREIGN KEY (`service_id`) REFERENCES `services`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `task_templates` ADD CONSTRAINT `task_templates_project_template_id_fkey` FOREIGN KEY (`project_template_id`) REFERENCES `project_templates`(`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `expenses` ADD CONSTRAINT `expenses_category_fkey` FOREIGN KEY (`category`) REFERENCES `finance_categories`(`code`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `expenses` ADD CONSTRAINT `expenses_project_id_fkey` FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `expenses` ADD CONSTRAINT `expenses_payee_user_id_fkey` FOREIGN KEY (`payee_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `expenses` ADD CONSTRAINT `expenses_created_by_id_fkey` FOREIGN KEY (`created_by_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `kpi_targets` ADD CONSTRAINT `kpi_targets_user_id_fkey` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `kpi_targets` ADD CONSTRAINT `kpi_targets_set_by_id_fkey` FOREIGN KEY (`set_by_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `attendance` ADD CONSTRAINT `attendance_user_id_fkey` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `attendance` ADD CONSTRAINT `attendance_edited_by_id_fkey` FOREIGN KEY (`edited_by_id`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `payroll_entries` ADD CONSTRAINT `payroll_entries_user_id_fkey` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `payroll_entries` ADD CONSTRAINT `payroll_entries_approved_by_id_fkey` FOREIGN KEY (`approved_by_id`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `telegram_link_tokens` ADD CONSTRAINT `telegram_link_tokens_user_id_fkey` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `notification_settings` ADD CONSTRAINT `notification_settings_user_id_fkey` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `notification_deliveries` ADD CONSTRAINT `notification_deliveries_user_id_fkey` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `follow_ups` ADD CONSTRAINT `follow_ups_client_id_fkey` FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `follow_ups` ADD CONSTRAINT `follow_ups_project_id_fkey` FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `follow_ups` ADD CONSTRAINT `follow_ups_owner_id_fkey` FOREIGN KEY (`owner_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `follow_ups` ADD CONSTRAINT `follow_ups_completed_by_id_fkey` FOREIGN KEY (`completed_by_id`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `follow_ups` ADD CONSTRAINT `follow_ups_result_deal_id_fkey` FOREIGN KEY (`result_deal_id`) REFERENCES `deals`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `commissions` ADD CONSTRAINT `commissions_user_id_fkey` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `commissions` ADD CONSTRAINT `commissions_approved_by_id_fkey` FOREIGN KEY (`approved_by_id`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `commissions` ADD CONSTRAINT `commissions_paid_by_id_fkey` FOREIGN KEY (`paid_by_id`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `commissions` ADD CONSTRAINT `commissions_payment_id_fkey` FOREIGN KEY (`payment_id`) REFERENCES `payments`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `commissions` ADD CONSTRAINT `commissions_deal_id_fkey` FOREIGN KEY (`deal_id`) REFERENCES `deals`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `commissions` ADD CONSTRAINT `commissions_rule_id_fkey` FOREIGN KEY (`rule_id`) REFERENCES `commission_rules`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `files` ADD CONSTRAINT `files_uploaded_by_id_fkey` FOREIGN KEY (`uploaded_by_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `files` ADD CONSTRAINT `files_deal_id_fkey` FOREIGN KEY (`deal_id`) REFERENCES `deals`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `files` ADD CONSTRAINT `files_proposal_id_fkey` FOREIGN KEY (`proposal_id`) REFERENCES `proposals`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `files` ADD CONSTRAINT `files_contract_id_fkey` FOREIGN KEY (`contract_id`) REFERENCES `contracts`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `files` ADD CONSTRAINT `files_payment_id_fkey` FOREIGN KEY (`payment_id`) REFERENCES `payments`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `files` ADD CONSTRAINT `files_project_id_fkey` FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `files` ADD CONSTRAINT `files_task_id_fkey` FOREIGN KEY (`task_id`) REFERENCES `tasks`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `todos` ADD CONSTRAINT `todos_owner_id_fkey` FOREIGN KEY (`owner_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `todos` ADD CONSTRAINT `todos_creator_id_fkey` FOREIGN KEY (`creator_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `todos` ADD CONSTRAINT `todos_client_id_fkey` FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `todos` ADD CONSTRAINT `todos_deal_id_fkey` FOREIGN KEY (`deal_id`) REFERENCES `deals`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `todos` ADD CONSTRAINT `todos_lead_id_fkey` FOREIGN KEY (`lead_id`) REFERENCES `leads`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `todos` ADD CONSTRAINT `todos_recurring_id_fkey` FOREIGN KEY (`recurring_id`) REFERENCES `recurring_todos`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `recurring_todos` ADD CONSTRAINT `recurring_todos_owner_id_fkey` FOREIGN KEY (`owner_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `recurring_todos` ADD CONSTRAINT `recurring_todos_created_by_id_fkey` FOREIGN KEY (`created_by_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `conversation_members` ADD CONSTRAINT `conversation_members_conversation_id_fkey` FOREIGN KEY (`conversation_id`) REFERENCES `conversations`(`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `conversation_members` ADD CONSTRAINT `conversation_members_user_id_fkey` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `chat_messages` ADD CONSTRAINT `chat_messages_conversation_id_fkey` FOREIGN KEY (`conversation_id`) REFERENCES `conversations`(`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `chat_messages` ADD CONSTRAINT `chat_messages_author_id_fkey` FOREIGN KEY (`author_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `other_incomes` ADD CONSTRAINT `other_incomes_category_fkey` FOREIGN KEY (`category`) REFERENCES `finance_categories`(`code`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `other_incomes` ADD CONSTRAINT `other_incomes_project_id_fkey` FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `other_incomes` ADD CONSTRAINT `other_incomes_client_id_fkey` FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `other_incomes` ADD CONSTRAINT `other_incomes_created_by_id_fkey` FOREIGN KEY (`created_by_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `employee_rates` ADD CONSTRAINT `employee_rates_user_id_fkey` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `employee_rates` ADD CONSTRAINT `employee_rates_work_item_id_fkey` FOREIGN KEY (`work_item_id`) REFERENCES `work_items`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `tariffs` ADD CONSTRAINT `tariffs_service_id_fkey` FOREIGN KEY (`service_id`) REFERENCES `services`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `tariff_items` ADD CONSTRAINT `tariff_items_tariff_id_fkey` FOREIGN KEY (`tariff_id`) REFERENCES `tariffs`(`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `tariff_items` ADD CONSTRAINT `tariff_items_work_item_id_fkey` FOREIGN KEY (`work_item_id`) REFERENCES `work_items`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `project_cost_lines` ADD CONSTRAINT `project_cost_lines_project_id_fkey` FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `project_cost_lines` ADD CONSTRAINT `project_cost_lines_tariff_id_fkey` FOREIGN KEY (`tariff_id`) REFERENCES `tariffs`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `project_cost_lines` ADD CONSTRAINT `project_cost_lines_tariff_item_id_fkey` FOREIGN KEY (`tariff_item_id`) REFERENCES `tariff_items`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `project_cost_lines` ADD CONSTRAINT `project_cost_lines_work_item_id_fkey` FOREIGN KEY (`work_item_id`) REFERENCES `work_items`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `project_cost_lines` ADD CONSTRAINT `project_cost_lines_assignee_id_fkey` FOREIGN KEY (`assignee_id`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `project_cost_lines` ADD CONSTRAINT `project_cost_lines_expense_id_fkey` FOREIGN KEY (`expense_id`) REFERENCES `expenses`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `user_directions` ADD CONSTRAINT `user_directions_user_id_fkey` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `user_directions` ADD CONSTRAINT `user_directions_direction_id_fkey` FOREIGN KEY (`direction_id`) REFERENCES `directions`(`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `lead_forms` ADD CONSTRAINT `lead_forms_owner_id_fkey` FOREIGN KEY (`owner_id`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `form_submissions` ADD CONSTRAINT `form_submissions_form_id_fkey` FOREIGN KEY (`form_id`) REFERENCES `lead_forms`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `form_submissions` ADD CONSTRAINT `form_submissions_lead_id_fkey` FOREIGN KEY (`lead_id`) REFERENCES `leads`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `social_threads` ADD CONSTRAINT `social_threads_lead_id_fkey` FOREIGN KEY (`lead_id`) REFERENCES `leads`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `social_threads` ADD CONSTRAINT `social_threads_owner_id_fkey` FOREIGN KEY (`owner_id`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `social_messages` ADD CONSTRAINT `social_messages_thread_id_fkey` FOREIGN KEY (`thread_id`) REFERENCES `social_threads`(`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `social_messages` ADD CONSTRAINT `social_messages_author_id_fkey` FOREIGN KEY (`author_id`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;


-- Защита неизменяемых данных на уровне БД (триггеры MySQL): журнал аудита, история этапов и
-- задач, таймлайн, версии КП, журнал напоминаний — только добавление; оплаты, договоры,
-- комиссии и зарплата — без удаления. Приложение такие операции и так не выполняет.
--
-- Отдельно от миграций: на виртуальном хостинге создание триггеров может быть запрещено
-- (бинарный лог без права SUPER) — тогда установка продолжается с предупреждением.
-- Применение: pnpm --filter @fluggi/db protect (или prisma db execute --file prisma/protect.sql)

DROP TRIGGER IF EXISTS `audit_logs_no_update`;
CREATE TRIGGER `audit_logs_no_update` BEFORE UPDATE ON `audit_logs` FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only';
DROP TRIGGER IF EXISTS `audit_logs_no_delete`;
CREATE TRIGGER `audit_logs_no_delete` BEFORE DELETE ON `audit_logs` FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only';
DROP TRIGGER IF EXISTS `activities_no_update`;
CREATE TRIGGER `activities_no_update` BEFORE UPDATE ON `activities` FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'activities is append-only';
DROP TRIGGER IF EXISTS `activities_no_delete`;
CREATE TRIGGER `activities_no_delete` BEFORE DELETE ON `activities` FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'activities is append-only';
DROP TRIGGER IF EXISTS `stage_history_no_update`;
CREATE TRIGGER `stage_history_no_update` BEFORE UPDATE ON `stage_history` FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stage_history is append-only';
DROP TRIGGER IF EXISTS `stage_history_no_delete`;
CREATE TRIGGER `stage_history_no_delete` BEFORE DELETE ON `stage_history` FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stage_history is append-only';
DROP TRIGGER IF EXISTS `proposal_versions_no_update`;
CREATE TRIGGER `proposal_versions_no_update` BEFORE UPDATE ON `proposal_versions` FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'proposal_versions is append-only';
DROP TRIGGER IF EXISTS `proposal_versions_no_delete`;
CREATE TRIGGER `proposal_versions_no_delete` BEFORE DELETE ON `proposal_versions` FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'proposal_versions is append-only';
DROP TRIGGER IF EXISTS `task_status_history_no_update`;
CREATE TRIGGER `task_status_history_no_update` BEFORE UPDATE ON `task_status_history` FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'task_status_history is append-only';
DROP TRIGGER IF EXISTS `task_status_history_no_delete`;
CREATE TRIGGER `task_status_history_no_delete` BEFORE DELETE ON `task_status_history` FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'task_status_history is append-only';
DROP TRIGGER IF EXISTS `reminder_log_no_update`;
CREATE TRIGGER `reminder_log_no_update` BEFORE UPDATE ON `reminder_log` FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'reminder_log is append-only';
DROP TRIGGER IF EXISTS `reminder_log_no_delete`;
CREATE TRIGGER `reminder_log_no_delete` BEFORE DELETE ON `reminder_log` FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'reminder_log is append-only';
DROP TRIGGER IF EXISTS `payments_no_delete`;
CREATE TRIGGER `payments_no_delete` BEFORE DELETE ON `payments` FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'rows of payments cannot be deleted';
DROP TRIGGER IF EXISTS `contracts_no_delete`;
CREATE TRIGGER `contracts_no_delete` BEFORE DELETE ON `contracts` FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'rows of contracts cannot be deleted';
DROP TRIGGER IF EXISTS `commissions_no_delete`;
CREATE TRIGGER `commissions_no_delete` BEFORE DELETE ON `commissions` FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'rows of commissions cannot be deleted';
DROP TRIGGER IF EXISTS `payroll_entries_no_delete`;
CREATE TRIGGER `payroll_entries_no_delete` BEFORE DELETE ON `payroll_entries` FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'rows of payroll_entries cannot be deleted';

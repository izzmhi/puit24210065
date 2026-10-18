-- Upgrade an existing database (created before October 2026) to the current schema.
-- Run once, e.g. in phpMyAdmin (Import) or: mysql login_auth < 002_student_role_backup_reports.sql

-- 1. Rename the role "user" to "student".
ALTER TABLE users MODIFY role ENUM('user','student','admin') NOT NULL DEFAULT 'student';
UPDATE users SET role = 'student' WHERE role = 'user';
ALTER TABLE users MODIFY role ENUM('student','admin') NOT NULL DEFAULT 'student';

-- 2. Accounts are marked as deleted instead of being erased.
ALTER TABLE users ADD COLUMN deleted_at DATETIME NULL AFTER last_login_at;

-- 3. Event log for reports, and restore points.
CREATE TABLE IF NOT EXISTS auth_events (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event      VARCHAR(40)  NOT NULL,
  user_id    INT UNSIGNED NULL,
  ip         VARCHAR(45)  NULL,
  detail     VARCHAR(255) NULL,
  created_at DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_events_created (created_at),
  KEY idx_events_user (user_id),
  CONSTRAINT fk_events_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS restore_points (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  label      VARCHAR(100) NOT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME     NOT NULL,
  user_count INT UNSIGNED NOT NULL,
  config     MEDIUMTEXT   NOT NULL,
  PRIMARY KEY (id),
  KEY idx_restore_created (created_at),
  CONSTRAINT fk_restore_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

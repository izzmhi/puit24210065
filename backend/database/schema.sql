-- Secure Login Authentication System: database schema (MySQL 5.7+ / MariaDB 10.4+)
--
-- Easiest way to install: run `php bin/setup-db.php` from the backend folder. It creates the
-- database named in .env (DB_NAME) and then runs this file.
--
-- Manual way (phpMyAdmin): create an empty database called login_auth with collation
-- utf8mb4_unicode_ci, select it in the left-hand tree, open the Import tab and upload this file.
--
-- All timestamps are UTC. The application supplies them, so results do not depend on the
-- database server's time zone.

CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name          VARCHAR(100)  NOT NULL,
  email         VARCHAR(190)  NOT NULL,
  -- Output of password_hash(): algorithm, parameters and the random salt are inside this string.
  -- It is a hash of HMAC(password, pepper); the pepper itself is NOT stored here.
  password_hash VARCHAR(255)  NOT NULL,
  role          ENUM('user','admin') NOT NULL DEFAULT 'user',
  created_at    DATETIME      NOT NULL,
  last_login_at DATETIME      NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per pending/used second-factor challenge.
CREATE TABLE IF NOT EXISTS otp_challenges (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id        INT UNSIGNED NOT NULL,
  -- SHA-256 of the random challenge id handed to the client (the id itself is not stored).
  challenge_hash CHAR(64)     NOT NULL,
  -- HMAC-SHA256 of the six-digit code, keyed with a secret derived from the pepper.
  otp_hash       CHAR(64)     NOT NULL,
  attempts       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  expires_at     DATETIME     NOT NULL,
  used_at        DATETIME     NULL,
  created_at     DATETIME     NOT NULL,
  ip             VARCHAR(45)  NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_otp_challenge_hash (challenge_hash),
  KEY idx_otp_user (user_id),
  KEY idx_otp_expires (expires_at),
  CONSTRAINT fk_otp_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Login sessions. Only the SHA-256 hash of the session token is stored.
CREATE TABLE IF NOT EXISTS sessions (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    INT UNSIGNED NOT NULL,
  token_hash CHAR(64)     NOT NULL,
  created_at DATETIME     NOT NULL,
  expires_at DATETIME     NOT NULL,
  revoked_at DATETIME     NULL,
  ip         VARCHAR(45)  NULL,
  user_agent VARCHAR(255) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sessions_token_hash (token_hash),
  KEY idx_sessions_user (user_id),
  KEY idx_sessions_expires (expires_at),
  CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Counters for login / OTP / registration rate limiting. Keys are stored hashed.
CREATE TABLE IF NOT EXISTS rate_limits (
  bucket       CHAR(64) NOT NULL,
  hits         INT UNSIGNED NOT NULL DEFAULT 0,
  window_start DATETIME NOT NULL,
  PRIMARY KEY (bucket),
  KEY idx_rate_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

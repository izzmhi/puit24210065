-- SQLite version of backend/database/schema.sql, used only by tools/wasm-runner.
CREATE TABLE users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  email TEXT NOT NULL UNIQUE,
  password_hash TEXT NOT NULL,
  role TEXT NOT NULL DEFAULT 'student' CHECK (role IN ('student','admin')),
  created_at TEXT NOT NULL,
  last_login_at TEXT NULL,
  deleted_at TEXT NULL
);
CREATE TABLE otp_challenges (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  challenge_hash TEXT NOT NULL UNIQUE,
  otp_hash TEXT NOT NULL,
  attempts INTEGER NOT NULL DEFAULT 0,
  expires_at TEXT NOT NULL,
  used_at TEXT NULL,
  created_at TEXT NOT NULL,
  ip TEXT NULL
);
CREATE TABLE sessions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  token_hash TEXT NOT NULL UNIQUE,
  created_at TEXT NOT NULL,
  expires_at TEXT NOT NULL,
  revoked_at TEXT NULL,
  ip TEXT NULL,
  user_agent TEXT NULL
);
CREATE TABLE rate_limits (
  bucket TEXT PRIMARY KEY,
  hits INTEGER NOT NULL DEFAULT 0,
  window_start TEXT NOT NULL
);
CREATE TABLE auth_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  event TEXT NOT NULL,
  user_id INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
  ip TEXT NULL,
  detail TEXT NULL,
  created_at TEXT NOT NULL
);
CREATE TABLE restore_points (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  label TEXT NOT NULL,
  created_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
  created_at TEXT NOT NULL,
  user_count INTEGER NOT NULL,
  config TEXT NOT NULL
);

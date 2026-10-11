# API reference

Base URL (local): `http://localhost:8000/api`. All requests and responses are JSON (`Content-Type: application/json`).

**Errors** always look like this:

```json
{ "error": { "code": "invalid_credentials", "message": "Invalid email or password.", "details": { } } }
```

`details` is optional (per-field messages for validation errors, `attempts_remaining`, `retry_after`).

## Authentication model

1. `POST /login` checks the password and returns a short-lived `challenge_id` (no session yet).
2. `POST /verify-otp` checks the six-digit code and, if correct, opens a session:
   * **Native app** (`X-Client-Type: native` or no header): the response contains `token`. Send it as
     `Authorization: Bearer <token>`.
   * **Browser** (`X-Client-Type: web`): the response contains **no token**. The session is an `HttpOnly` cookie named
     `sid`. The request must come from an origin listed in `CORS_ALLOWED_ORIGINS`.
3. Protected endpoints accept either the bearer token or the cookie. Session tokens are 256-bit random values; only their
   SHA-256 hash is stored.

CSRF protection for cookie sessions: state-changing requests authenticated by cookie (`POST /logout`) must send an
allowed `Origin` header and `X-Client-Type: web`; otherwise the server answers `403`.

## Endpoints

| Method | Path | Auth | Purpose |
| --- | --- | --- | --- |
| GET | `/health` | none | Liveness and database check |
| POST | `/register` | none | Create an account (role is always `user`) |
| POST | `/login` | none | Verify email + password, send one-time code |
| POST | `/verify-otp` | none | Verify the code, open a session |
| POST | `/resend-otp` | none | Issue a new code for a pending sign-in |
| GET | `/me` | session | Current user |
| POST | `/logout` | session | Revoke the session |
| GET | `/admin/users` | session, role `admin` | List users |

### GET `/health`
`200` `{ "status": "ok", "time": "2026-10-09T10:00:00+00:00" }` — `503` `{ "status": "degraded" }` if the database is unreachable.

### POST `/register`
Request: `{ "name": "Amina Yusuf", "email": "amina@example.com", "password": "Correct-Horse-9" }`

Rules: name 2–100 characters, no `<`/`>`/control characters; valid email (max 190, stored lower-case); password 12–128
characters with at least one letter and one digit, not on a small deny-list, not equal to the email.

* `201` `{ "message": "...", "user": { "id": 1, "name": "...", "email": "...", "role": "user" } }`
* `409 email_taken` — the email already exists (case-insensitive)
* `422 validation_failed` — `details` maps field name to message
* `429 rate_limited` — more than 10 registrations per hour from one IP

### POST `/login`
Request: `{ "email": "amina@example.com", "password": "..." }`

* `200` `{ "message", "challenge_id": "<64 hex>", "expires_in": 300, "email_hint": "a***@example.com", "delivery": "email" | "development", "dev_otp"?: "123456" }`
  `dev_otp` appears **only** when the server runs with `OTP_DELIVERY=dev` (refused when `APP_ENV=production`).
* `401 invalid_credentials` — identical for unknown email and wrong password
* `422 validation_failed` — missing fields
* `429 rate_limited` — 8 failed attempts per email per 15 minutes, or 30 requests per IP per 15 minutes (`Retry-After` header)
* `503 otp_delivery_failed` — the email could not be sent; no code remains valid

### POST `/verify-otp`
Request: `{ "challenge_id": "<64 hex>", "otp": "123456" }` (+ `X-Client-Type: web` and an allowed `Origin` for browsers)

Codes expire after 5 minutes, allow 5 wrong attempts, and can be used once.

* `200` native: `{ "message", "session": "token", "token": "<64 hex>", "user": {...}, "expires_at": "ISO-8601" }`
  browser: `{ "message", "session": "cookie", "user": {...}, "expires_at" }` plus `Set-Cookie: sid=...; HttpOnly; SameSite=...; Secure (when configured)`
* `401 incorrect_code` — `details.attempts_remaining`
* `401 invalid_code` — unknown, used or expired
* `403 origin_not_allowed` — browser request from a disallowed origin
* `422 validation_failed` — malformed challenge or code
* `429 too_many_attempts` / `429 rate_limited`

### POST `/resend-otp`
Request: `{ "challenge_id": "<64 hex>" }`. Replaces the pending code (the previous code stops working). Allowed after a
30-second cooldown (`429 resend_too_soon`). Response is the same shape as `/login` with a new `challenge_id`.

### GET `/me`
`200` `{ "user": { "id", "name", "email", "role", "created_at", "last_login_at" } }` — `401 unauthenticated`.

### POST `/logout`
`200` `{ "message": "You have been signed out." }`. Revokes the session in the database; the cookie (if any) is cleared.
Logging out with an already-invalid session is not an error. `403` when a cookie session lacks the CSRF headers.

### GET `/admin/users?limit=100&offset=0`
Requires a valid session whose user has the role `admin` (read from the database on every request).

`200` `{ "users": [ {id,name,email,role,created_at,last_login_at} ], "total": 3, "summary": { "admins": 1, "users": 2, "active_sessions": 1 } }`
— `401` without a session, `403 forbidden` for a normal user. Password hashes are never returned.

## Other behaviour

* Unknown path `404`, wrong method `405` (with `Allow`), invalid JSON `400`, body over 16 KB `413`, wrong content type `415`.
* Server misconfiguration: `500 server_misconfigured`; database unreachable: `503 service_unavailable`. Details go to the
  server log only.
* Every response sends `Cache-Control: no-store`, `X-Content-Type-Options: nosniff` and a restrictive
  `Content-Security-Policy`; `Strict-Transport-Security` is added when `APP_ENV=production`.
* CORS: only origins listed in `CORS_ALLOWED_ORIGINS` receive `Access-Control-Allow-Origin` (echoed exactly) with
  `Access-Control-Allow-Credentials: true`. There is no wildcard.

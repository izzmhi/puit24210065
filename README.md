# Secure Login Authentication System

A student project that implements:

1. **User identification and registration** (validated on the client and the server)
2. **Two-factor authentication (2FA)** with a six-digit, single-use, five-minute one-time code
3. **Role-based authorization** (`admin` and `user`, enforced by the server)
4. **Salt and pepper password protection** (`password_hash()` + a server-side pepper)

| Part | Technology |
| --- | --- |
| App (phone + browser) | Expo / React Native / TypeScript, Expo Router, Expo SecureStore |
| API | PHP 8.1+ REST API (no framework, no Composer) |
| Database | MySQL / MariaDB (XAMPP or Laragon) |
| Web hosting of the app | Vercel (static export) |

```
login-auth-assignment/
├─ backend/            PHP API
│  ├─ public/          web root (index.php front controller)
│  ├─ src/             application code
│  ├─ database/        schema.sql
│  ├─ bin/             setup-db.php, set-role.php, generate-secret.php
│  ├─ tests/           backend test suite (php tests/run.php)
│  └─ .env.example     configuration template
├─ mobile/             Expo app (phone + web)
│  ├─ app/             screens (Expo Router)
│  ├─ src/             API client, auth state, UI components
│  └─ tests/           client unit tests
├─ docs/               API.md, DEPLOYMENT.md
├─ tools/wasm-runner/  runs the PHP backend without installing PHP (see "No PHP on this computer?")
└─ REPORT.md           the written report for submission
```

> **Test status:** the backend suite (60 tests) passes when run with PHP 8.3 in WebAssembly against SQLite, and
> the web app passes an end-to-end browser test against that real PHP API (see [REPORT.md](REPORT.md), section 12).
> The online version, **https://puit24210065.vercel.app**, runs the same PHP code on real MySQL (Aiven) with emailed
> codes, and sign-in works there. The test suite has not yet been run against MySQL: run `php tests/run.php` with
> XAMPP or Laragon (Step 5).

---

## Part 1 — Run it on your own Windows computer

### Step 1. Install the tools

1. **XAMPP** (easiest) from <https://www.apachefriends.org> — choose a version with **PHP 8.1 or newer**.
   *(Laragon from <https://laragon.org> works too; see the Laragon notes below.)*
2. **Node.js LTS** from <https://nodejs.org> (needed for the Expo app).
3. Optional: **Git** and **VS Code**.

### Step 2. Start MySQL

Open the **XAMPP Control Panel** and click **Start** next to **MySQL**. (Apache is *not* required; we use PHP's built-in
server. If MySQL will not start, see Troubleshooting.)

Make PHP available in a terminal. In PowerShell, run this once per terminal window (adjust the path if you
installed XAMPP elsewhere):

```powershell
$env:Path = "C:\xampp\php;" + $env:Path
php -v        # should print PHP 8.1 or newer
```

> Laragon users: PHP is usually under `C:\laragon\bin\php\php-8.x...`. Use Laragon's *Terminal* button, which already
> has `php` on the path.

### Step 3. Configure the backend

```powershell
cd backend
copy .env.example .env
php bin/generate-secret.php
```

Open `backend\.env` in an editor and:

* paste the generated 64-character value after `APP_PEPPER=`;
* check `DB_USER=root` and `DB_PASS=` (XAMPP and Laragon default to user `root` with an empty password);
* leave `APP_ENV=development` and `OTP_DELIVERY=dev` for the local demo (see *Two-factor codes* below).

**Never commit `.env` and never share your pepper.** If you change the pepper later, existing passwords stop working.

### Step 4. Create the database and start the API

```powershell
php bin/setup-db.php          # creates database "login_auth" and its tables
php -S localhost:8000 -t public public/index.php
```

Leave that window open. In a browser, open <http://localhost:8000/api/health> — you should see `{"status":"ok",...}`.

*Prefer clicking?* Instead of `setup-db.php`, open <http://localhost/phpmyadmin>, create an empty database named
`login_auth` (collation `utf8mb4_unicode_ci`), select it, open **Import**, and upload `backend/database/schema.sql`.

### Step 5. Run the backend tests (do this and keep the output for your report)

In a **second** PowerShell window:

```powershell
cd backend
$env:Path = "C:\xampp\php;" + $env:Path
php tests/run.php
```

The tests use their own database called `login_auth_test` (created automatically with the credentials in `.env`) and
refuse to run against any database whose name does not contain "test". Every test prints `PASS` or `FAIL`, and the last
line shows the totals. If something fails, read the message under `FAIL` and fix the cause before continuing.

### Step 6. Start the Expo app

```powershell
cd mobile
npm install
npm run typecheck
npm test
npx expo start
```

* Press **`w`** to open it in the browser (<http://localhost:8081>). On the web the app talks to
  `http://localhost:8000/api` automatically. Use `localhost`, not `127.0.0.1`, in the address bar.
* **Phone:** install **Expo Go** from the app store, make sure the phone is on the *same Wi-Fi*, and scan the QR code.
  A phone cannot reach `localhost` on your computer, so set the API address first (next section).
* **Android emulator:** press **`a`**. The app uses `http://10.0.2.2:8000/api` automatically.

### Pointing the app at the API (`EXPO_PUBLIC_API_URL`)

| Where the app runs | API address |
| --- | --- |
| Browser on the same computer | `http://localhost:8000/api` (default, nothing to do) |
| iOS simulator | `http://localhost:8000/api` (default) |
| Android emulator | `http://10.0.2.2:8000/api` (default) |
| **Physical phone** | `http://<your computer's IPv4 address>:8000/api` |
| Deployed site | your HTTPS API, for example `https://api.example.com/api` |

For a physical phone: run `ipconfig`, copy the **IPv4 Address** of your Wi-Fi adapter (for example `192.168.1.50`), then

```powershell
cd mobile
copy .env.example .env      # then edit .env and set the line:
# EXPO_PUBLIC_API_URL=http://192.168.1.50:8000/api
```

and start the API so the phone can reach it (listen on all network interfaces instead of only localhost):

```powershell
cd backend
php -S 0.0.0.0:8000 -t public public/index.php
```

Allow PHP through Windows Firewall if prompted (private networks only). Restart `npx expo start` after editing `.env`
(add `--clear` if it still uses the old value). Everything starting with `EXPO_PUBLIC_` is **public** inside the built
app, so only ever put a URL there.

### Two-factor codes: development mode versus email

* **Local demo (default `.env.example`):** `OTP_DELIVERY=dev`. The API returns the code in its response and the app
  shows it in a yellow "Development mode" box, so you can demonstrate 2FA without an email server. The server **refuses
  to start** with `OTP_DELIVERY=dev` when `APP_ENV=production`, so this can never be active in a real deployment.
* **Real email:** set `OTP_DELIVERY=smtp` and fill in `SMTP_HOST`, `SMTP_PORT`, `SMTP_SECURE` (`tls` for port 587,
  `ssl` for port 465), `SMTP_USER`, `SMTP_PASS`, `SMTP_FROM`. For Gmail use an *App Password* (needs 2-Step
  Verification) with `smtp.gmail.com`, port 587, `tls`. Email delivery is implemented but has **not** been tested
  against a live SMTP server in this project.

### Creating the first administrator

Public registration only ever creates the `user` role (so nobody can make themselves an admin). To create an
administrator, register normally in the app, then promote that account from the command line on the server:

```powershell
cd backend
php bin/set-role.php you@example.com admin
```

Sign in again (or just reload the page) and the *Admin* menu link appears. To demote:
`php bin/set-role.php you@example.com user`. The role is read from the database on every request, so a change takes
effect immediately.

### Using Laragon instead of XAMPP

Start Laragon (**Start All**). Use *Terminal* from Laragon, then follow Steps 3–6 unchanged. If you would rather use
Laragon's Apache, point a virtual host's document root at `backend/public` and use that site's URL plus `/api` as the API
address; `public/.htaccess` is already prepared for Apache.

### No PHP on this computer? (Windows Smart App Control)

Windows 11 **Smart App Control** can block `php.exe` ("An Application Control policy has blocked this file"). That
happened on the computer this project was prepared on. `tools/wasm-runner` runs the same backend code with PHP 8.3
compiled to WebAssembly, inside Node.js, so nothing needs to be installed except Node:

```powershell
cd tools\wasm-runner
npm install
npm test                                          # runs backend/tests (60 tests)
npm run api -- --admin=you@example.com            # real PHP API on http://localhost:8000/api
```

Then start the app as in Step 6 (`npx expo start`, press `w`). Register `you@example.com` and it becomes an admin
automatically; other accounts are normal users. You can also type `admin someone@example.com` in the API window.

Limitations: it uses **SQLite instead of MySQL** (a small translation layer in `tools/wasm-runner/compat.php` handles
the few MySQL-only statements), it always runs in development mode with the code shown on screen, and the data is lost
when you stop it. It is for local testing and demonstrations only; your report should still include a run against
MySQL if you can get XAMPP or Laragon working.

---

## Troubleshooting

| Problem | Fix |
| --- | --- |
| `php` is not recognised | Add PHP to the path for this window: `$env:Path = "C:\xampp\php;" + $env:Path`. |
| `could not find driver` / `pdo_mysql` | Open `C:\xampp\php\php.ini`, make sure the lines `extension=pdo_mysql` and `extension=mbstring` have no leading `;`, then restart the terminal. |
| MySQL will not start (port 3306 busy) | Another MySQL is running. Stop that service in *Services*, or change the port in XAMPP (**Config → my.ini**) and set `DB_PORT` in `.env`. |
| `Access denied for user 'root'` | `DB_PASS` in `.env` is wrong. XAMPP's default is empty; MariaDB installed separately may have a password. |
| `Port 8000 is in use` | Use another port: `php -S localhost:8001 -t public public/index.php` and set `EXPO_PUBLIC_API_URL`, `CORS` accordingly. |
| `/api/health` says "server is not configured correctly" | `.env` is missing or invalid. The exact reason is written to the PHP console window (setting names only). Check `APP_PEPPER` (32+ chars, not the placeholder). |
| Browser: "blocked by CORS policy" | The app's address must appear in `CORS_ALLOWED_ORIGINS` in `.env` (exactly, e.g. `http://localhost:8081`). Restart `php -S` after editing. |
| App says "Cannot reach the server" on a phone | Wrong `EXPO_PUBLIC_API_URL`, different Wi-Fi, firewall, or the API was started with `localhost` instead of `0.0.0.0`. |
| Signed in on web but reload logs me out | Open the site as `http://localhost:8081` (not `127.0.0.1`); cookies are per host. |
| `Too many attempts` (429) | The rate limiter is working. Wait for the time shown, or clear it while developing: `DELETE FROM rate_limits;` in phpMyAdmin. |
| Tests: "Cannot prepare the test database" | MySQL is not running or `DB_USER`/`DB_PASS` in `.env` are wrong. |

## Security notes (read before deploying)

* **Use HTTPS** for the API and the website in any real deployment. Passwords, codes and sessions travel in requests;
  without HTTPS they can be read by others on the network. Set `APP_ENV=production` (which forces secure cookies) only
  when HTTPS is in place.
* `.env` holds the pepper, database password and SMTP password. Keep it out of Git and out of the web root
  (`backend/public` is the only folder that should be web-accessible).
* The web build keeps no session token in JavaScript-readable storage: it uses an `HttpOnly` cookie. The native app
  stores its token in Expo SecureStore (Keychain / Keystore).
* Known limitations are listed in [REPORT.md](REPORT.md), section 13.

## More documentation

* [docs/API.md](docs/API.md) — every endpoint, request, response and error
* [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) — Vercel settings and how to host the PHP API and MySQL online
* [REPORT.md](REPORT.md) — the university report

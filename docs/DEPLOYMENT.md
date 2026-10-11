# Deployment guide

The project has two halves that deploy differently:

| Half | Where it can run | Notes |
| --- | --- | --- |
| Expo web app (static files) | **Vercel** | Build output is plain HTML/JS. |
| PHP API + MySQL | **A PHP host + a MySQL database** | **Vercel does not run this PHP/MySQL backend** as a conventional, persistent PHP server. |

A public Vercel link on its own only shows the interface. **Sign-in works online only after the API and database are
deployed somewhere and the website is pointed at them.** Until you have done and tested that, do not claim the deployed
site works end to end.

## 0. What the project `puit24210065` uses: API on Vercel functions + Aiven MySQL

Vercel does not run PHP as a normal always-on server, but the community runtime
[`vercel-php`](https://github.com/vercel-community/php) runs PHP inside Vercel Functions. The repository-root
`vercel.json` uses it:

* `api/index.php` is a function (`vercel-php@0.7.4`, PHP 8.3). It just includes `backend/public/index.php`, so it is the
  same code as the local API. `includeFiles` bundles `backend/`; `excludeFiles` keeps the web app's `node_modules` out
  (otherwise the function is over Vercel's 250 MB limit).
* Rewrites send `/api/...` to that function and every other path to the web app's `index.html`. The website and the API
  are on the **same origin**, so the session cookie is first-party (`SameSite=Lax`) and the web build's default API
  address `/api` is correct (no `EXPO_PUBLIC_API_URL` needed).
* The database is an external **Aiven MySQL** service. Aiven requires TLS and signs its certificates with a per-project
  CA, so its `ca.pem` (public, not secret) is committed as `backend/certs/aiven-ca.pem` and `DB_SSL_CA=certs/aiven-ca.pem`.
* Settings are Vercel **Environment Variables** (Production), not a `.env` file: `APP_ENV=production`, `APP_PEPPER`
  (sensitive), `CORS_ALLOWED_ORIGINS=https://puit24210065.vercel.app`, `TRUST_PROXY=true` (Vercel sets
  `X-Forwarded-For`), `COOKIE_SECURE=true`, `COOKIE_SAMESITE=Lax`, `OTP_DELIVERY=smtp` with Gmail (`SMTP_HOST=smtp.gmail.com`,
  `SMTP_PORT=587`, `SMTP_SECURE=tls`, `SMTP_USER`, `SMTP_PASS` = a Gmail App Password, `SMTP_FROM`), and `DB_HOST`,
  `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`, `DB_SSL_CA` for Aiven.
* Limitations: each request may start a fresh function (a "cold start" of a second or two); rate-limit counters live in
  MySQL so they still work across function instances; the free database may pause when idle.

The sections below describe the alternative of hosting the API on ordinary PHP hosting.

## 1. Deploy the web app to Vercel

### Settings (Project → Settings → General / Build)

| Setting | Value |
| --- | --- |
| **Root Directory** | `mobile` |
| Framework Preset | **Other** |
| **Install Command** | `npm install` |
| **Build Command** | `npx expo export --platform web` |
| **Output Directory** | `dist` |
| Node.js version | 20.x or newer |

These are also in `mobile/vercel.json`, which additionally adds security headers and rewrites every path to
`index.html` (the app uses client-side routing, so a reload of `/dashboard` must still serve the app).

**If the project's Root Directory is left at `.` (the repository root)**, which is how the project
`puit24210065` is set up, the root-level `vercel.json` is used instead. It runs the same steps inside the `mobile`
folder: install `cd mobile && npm install`, build `cd mobile && npx expo export --platform web`, output
`mobile/dist`. Use one setup or the other, not both.

### Environment variable (Project → Settings → Environment Variables)

| Name | Value | Scope |
| --- | --- | --- |
| `EXPO_PUBLIC_API_URL` | your API address, e.g. `https://api.example.com/api` | Production (and Preview if you want) |

This is baked into the build, so **redeploy after changing it**. It is public: it must contain only a URL. Never put
database passwords, the pepper, SMTP credentials or any key in a Vercel *frontend* variable.

If `EXPO_PUBLIC_API_URL` is not set, the production build calls `/api` on its own domain — which only works with the proxy
option below.

### CLI alternative

```powershell
npm i -g vercel
cd mobile
vercel            # first run: answer the prompts, root = current folder
vercel --prod
```

## 2. Host the PHP API and MySQL

Pick one. All need PHP 8.1+ with `pdo_mysql` and `mbstring`, HTTPS, and MySQL 5.7+/MariaDB 10.4+.

* **Shared hosting with cPanel (PHP + MySQL included)** — simplest for a student project. Upload the `backend` folder
  *outside* the public web root if possible, and make the **document root (or a subdomain, e.g. `api.example.com`)
  point at `backend/public`**. Create the database and user in cPanel, import `backend/database/schema.sql`
  with phpMyAdmin, and create `backend/.env` on the server (do not upload your local one).
* **A container platform (Render, Railway, Fly.io, ...)** — package `backend` as a PHP 8.3 + Apache image
  (document root `backend/public`, extensions `pdo_mysql`, `mbstring`) and attach a managed MySQL database. A Dockerfile is
  *not* included in this repository.
* **A small VPS** — Apache or Nginx + PHP-FPM + MySQL, with a free Let's Encrypt certificate.

### Production `.env` checklist

```
APP_ENV=production
APP_PEPPER=<new 64-character secret; not the one from your laptop>
DB_HOST=... DB_PORT=3306 DB_NAME=... DB_USER=... DB_PASS=...
CORS_ALLOWED_ORIGINS=https://your-project.vercel.app
OTP_DELIVERY=smtp          # dev mode is refused in production
SMTP_HOST=... SMTP_PORT=587 SMTP_SECURE=tls SMTP_USER=... SMTP_PASS=... SMTP_FROM=...
COOKIE_SECURE=true
COOKIE_SAMESITE=Lax        # or None when the API is on a different site (see below)
TRUST_PROXY=false          # true only behind a proxy you control
```

If the configuration is unsafe (placeholder pepper, wildcard CORS, dev OTP in production, insecure cookies, ...) the API
refuses to start and returns a generic error; the exact reason is in the server's PHP error log.

Then:

1. `php bin/setup-db.php` (or import `schema.sql`), then register your account in the website.
2. Promote yourself: `php bin/set-role.php you@example.com admin` (needs shell access; on shared hosting without SSH, run
   `UPDATE users SET role='admin' WHERE email='you@example.com';` in phpMyAdmin).
3. Test: `https://api.example.com/api/health` returns `{"status":"ok"}`.

## 3. Connect the website to the API (choose one)

### Option A — Direct (simplest)

Set `EXPO_PUBLIC_API_URL=https://api.example.com/api` in Vercel and put `https://your-project.vercel.app` in
`CORS_ALLOWED_ORIGINS`. The browser then talks to the API on a different domain, so the session cookie is *cross-site*:

* set `COOKIE_SAMESITE=None` and `COOKIE_SECURE=true` on the API;
* some browsers (Safari, Chrome with third-party cookie blocking) refuse such cookies, and sign-in can fail there.

### Option B — Same-origin proxy through Vercel (more robust for cookies)

Make the browser think the API is part of the website. Add a rewrite **before** the catch-all in `mobile/vercel.json`:

```json
"rewrites": [
  { "source": "/api/:path*", "destination": "https://api.example.com/api/:path*" },
  { "source": "/(.*)", "destination": "/index.html" }
]
```

Do **not** set `EXPO_PUBLIC_API_URL` (the build then uses `/api`). Keep `COOKIE_SAMESITE=Lax`, `COOKIE_SECURE=true`, and
keep `https://your-project.vercel.app` in `CORS_ALLOWED_ORIGINS` (the server checks the `Origin` header of sign-in and
logout requests). The API address in this file is public, which is fine; it is not a secret.

## 4. After deployment: test end to end

Only say the deployment works after you have actually done all of these on the public URL:

1. Register an account; see it in the `users` table.
2. Sign in; receive the verification **email** (check spam); enter the code; reach the dashboard.
3. Reload the page: still signed in. Sign out: `/dashboard` redirects to sign-in.
4. Promote the account to admin and confirm the Admin page lists users; confirm a second normal account gets
   "Access denied".
5. Check in the browser tools (Application → Cookies) that `sid` is `HttpOnly` and `Secure`, and that nothing sensitive is in
   Local Storage.

## 5. Known deployment caveats

* The Expo web export places a few default navigation icons under `dist/assets/node_modules/...`. This app does not use
  them, but if a host strips `node_modules` folders from uploads you may see 404s for those files; they do not affect the app.
* Free database tiers often sleep when idle, so the first request after a pause can be slow or fail.
* Rate limiting uses the client IP (`REMOTE_ADDR`). Behind a proxy or load balancer, set `TRUST_PROXY=true` only if that proxy
  overwrites `X-Forwarded-For`; otherwise everyone shares the proxy's IP and can lock each other out.

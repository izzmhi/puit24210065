// Serves the real PHP API (backend/public/index.php) on http://localhost:8000/api using php-wasm and SQLite.
// LOCAL DEVELOPMENT ONLY: development OTP mode, data is lost when you stop it.
//
//   npm run api
//   npm run api -- --admin=you@example.com        accounts in this list become admin when they register
//
// While it runs you can also type in this window:
//   admin you@example.com     make an account an administrator (runs backend/bin/set-role.php)
//   student you@example.com   make it a normal student again
import crypto from 'node:crypto';
import fs from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import readline from 'node:readline';
import { prepareCopy, removeCliGuard, startPhp } from './common.mjs';

const arg = (name, fallback) => process.argv.find((a) => a.startsWith(`--${name}=`))?.split('=')[1] ?? fallback;
const port = Number(arg('port', '8000'));
const origins = arg('origins', 'http://localhost:8081,http://127.0.0.1:8081');
const autoAdmins = arg('admin', '').split(',').map((e) => e.trim().toLowerCase()).filter(Boolean);

const work = prepareCopy('api');
fs.writeFileSync(
  path.join(work, 'backend/src/Database.php'),
  `<?php
declare(strict_types=1);
namespace App;
use PDO;
final class Database
{
    public static function connect(Config $config): PDO
    {
        require_once '/work/compat.php';
        return \\wasm_open_db('/db/app.sqlite');
    }
}
`,
);
removeCliGuard(work, 'bin/set-role.php');
fs.writeFileSync(
  path.join(work, 'backend/.env'),
  [
    'APP_ENV=development',
    `APP_PEPPER=${crypto.randomBytes(32).toString('hex')}`,
    'DB_NAME=login_auth',
    'DB_USER=root',
    `CORS_ALLOWED_ORIGINS=${origins}`,
    'OTP_DELIVERY=dev',
    'COOKIE_SECURE=false',
    'COOKIE_SAMESITE=Lax',
  ].join('\n') + '\n',
);

const php = await startPhp(work);

// php-wasm runs one request at a time.
let queue = Promise.resolve();
const runPhp = (options) => {
  const next = queue.then(() => php.run(options));
  queue = next.catch(() => undefined);
  return next;
};

async function setRole(email, role) {
  const r = await runPhp({
    code: `<?php $argv = ['set-role.php', ${JSON.stringify(email)}, ${JSON.stringify(role)}]; require '/work/backend/bin/set-role.php';`,
  });
  return (r.text + r.errors).trim();
}

http
  .createServer((req, res) => {
    const chunks = [];
    req.on('data', (c) => chunks.push(c));
    req.on('end', async () => {
      const body = Buffer.concat(chunks).toString('utf8');
      try {
        const result = await runPhp({
          scriptPath: '/work/backend/public/index.php',
          relativeUri: req.url,
          method: req.method,
          headers: req.headers,
          body: body || undefined,
        });
        if (result.errors.trim()) console.error(`[php] ${req.method} ${req.url}: ${result.errors.trim()}`);

        if (req.url.startsWith('/api/register') && result.httpStatusCode === 201 && autoAdmins.length > 0) {
          const email = JSON.parse(result.text)?.user?.email;
          if (autoAdmins.includes(email)) console.log(await setRole(email, 'admin'));
        }

        res.writeHead(result.httpStatusCode, result.headers);
        res.end(Buffer.from(result.bytes));
      } catch (e) {
        console.error('Request failed:', e?.message ?? e);
        res.writeHead(500, { 'Content-Type': 'application/json' });
        res.end('{"error":{"code":"server_error","message":"Something went wrong on the server."}}');
      }
    });
  })
  .listen(port, () => {
    console.log(`PHP API (WebAssembly + SQLite, development only) running on http://localhost:${port}/api`);
    console.log('Type "admin <email>" or "student <email>" to change a role. Press Ctrl+C to stop.');
  });

readline.createInterface({ input: process.stdin }).on('line', async (line) => {
  const [role, email] = line.trim().split(/\s+/);
  if ((role === 'admin' || role === 'student') && email) console.log(await setRole(email, role));
  else if (line.trim()) console.log('Usage: admin <email>   or   student <email>');
});

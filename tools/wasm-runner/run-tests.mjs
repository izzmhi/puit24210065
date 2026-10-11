// Runs backend/tests with php-wasm and an in-memory SQLite database.
//   npm test              all tests
//   npm test -- "otp:"    only tests whose name contains "otp:"
import { patch, prepareCopy, removeCliGuard, startPhp } from './common.mjs';

const filter = process.argv[2] ?? '';
const work = prepareCopy('tests');

// Use SQLite instead of creating a MySQL test database.
const initStart = 'public static function init(): void';
const initEnd = 'public static function pdo(): PDO';
{
  const fs = await import('node:fs');
  const file = `${work}/backend/tests/support.php`;
  const text = fs.readFileSync(file, 'utf8');
  const a = text.indexOf(initStart);
  const b = text.indexOf(initEnd);
  if (a < 0 || b < 0) throw new Error('Cannot find Env::init() in tests/support.php');
  const replacement = `public static function init(): void
    {
        self::$db = ['DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306', 'DB_USER' => 'root', 'DB_PASS' => '', 'DB_NAME' => 'login_auth_test'];
        self::$pdo = \\wasm_open_db(':memory:');
    }

    `;
  fs.writeFileSync(file, text.slice(0, a) + replacement + text.slice(b));
}
removeCliGuard(work, 'tests/run.php');
patch(work, 'tests/run.php', "require dirname(__DIR__) . '/src/bootstrap.php';", "require '/work/compat.php';\nrequire dirname(__DIR__) . '/src/bootstrap.php';");

const php = await startPhp(work);
console.log('Backend tests: PHP 8.3 (WebAssembly) + SQLite\n');
try {
  const result = await php.run({
    code: `<?php $argv = ['run.php'${filter ? `, ${JSON.stringify(filter)}` : ''}]; chdir('/work/backend'); require '/work/backend/tests/run.php';`,
  });
  console.log(result.text);
  const failed = /(\d+) failed/.exec(result.text);
  process.exit(failed && failed[1] === '0' ? 0 : 1);
} catch (e) {
  console.log(e?.response?.text ?? '');
  console.error('Test run stopped:', e?.message ?? e);
  process.exit(1);
}

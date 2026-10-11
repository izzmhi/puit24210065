<?php

declare(strict_types=1);

/**
 * Backend test runner (no Composer or PHPUnit needed).
 *
 *   php tests/run.php            run everything
 *   php tests/run.php "otp:"     run only tests whose name contains "otp:"
 *
 * Needs MySQL running. Tests use their own database (default: login_auth_test), created
 * automatically with the credentials from .env (or TEST_DB_HOST / TEST_DB_PORT / TEST_DB_USER /
 * TEST_DB_PASS / TEST_DB_NAME environment variables).
 */

if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line.\n");
}

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/support.php';

function test(string $name, callable $fn): void
{
    \Tests\Harness::add($name, $fn);
}

try {
    \Tests\Env::init();
} catch (\Throwable $e) {
    fwrite(STDERR, "Cannot prepare the test database: " . $e->getMessage() . "\n"
        . "Is MySQL running? Check DB_USER / DB_PASS in backend/.env.\n");
    exit(2);
}

foreach (glob(__DIR__ . '/cases/*.php') ?: [] as $file) {
    require $file;
}

echo "Running backend tests against database '" . (getenv('TEST_DB_NAME') ?: 'login_auth_test') . "'\n\n";
exit(\Tests\Harness::run($argv));

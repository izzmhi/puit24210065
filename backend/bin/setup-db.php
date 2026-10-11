<?php

declare(strict_types=1);

/**
 * Creates the database named in .env (DB_NAME) if needed and installs database/schema.sql.
 * Safe to run more than once (all statements use IF NOT EXISTS).
 *
 *   php bin/setup-db.php
 */

if (PHP_SAPI !== 'cli') {
    exit("This script must be run from the command line.\n");
}

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Config;
use App\ConfigException;

try {
    $config = Config::fromEnvFile();
} catch (ConfigException $e) {
    fwrite(STDERR, "Configuration problem: " . $e->getMessage() . "\nFix backend/.env (see .env.example) and run again.\n");
    exit(1);
}

$dbName = $config->get('DB_NAME'); // already validated as letters, digits and underscores
try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $config->get('DB_HOST', '127.0.0.1'), $config->int('DB_PORT', 3306)),
        $config->get('DB_USER'),
        $config->get('DB_PASS'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$dbName`");

    // Strip "-- comment" lines, then run each statement.
    $sql = (string) file_get_contents(dirname(__DIR__) . '/database/schema.sql');
    $sql = (string) preg_replace('/^\s*--.*$/m', '', $sql);
    $count = 0;
    foreach (explode(';', $sql) as $statement) {
        if (trim($statement) !== '') {
            $pdo->exec($statement);
            $count++;
        }
    }
    echo "Database '$dbName' is ready ($count statements applied).\n";
} catch (PDOException $e) {
    fwrite(STDERR, "Database error: " . $e->getMessage() . "\n"
        . "Check that MySQL is running and that DB_HOST, DB_PORT, DB_USER and DB_PASS in backend/.env are correct.\n");
    exit(1);
}

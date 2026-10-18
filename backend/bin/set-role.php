<?php

declare(strict_types=1);

/**
 * Changes the role of an existing account. This is the ONLY way to create an administrator:
 * public registration always creates ordinary users, and the command line is only available
 * to someone with access to the server.
 *
 *   php bin/set-role.php you@example.com admin
 *   php bin/set-role.php you@example.com student
 */

if (PHP_SAPI !== 'cli') {
    exit("This script must be run from the command line.\n");
}

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Config;
use App\ConfigException;
use App\Database;
use App\Validation\Validator;

$email = Validator::normalizeEmail($argv[1] ?? null);
$role = $argv[2] ?? '';
if ($email === '' || !in_array($role, ['admin', 'student'], true)) {
    fwrite(STDERR, "Usage: php bin/set-role.php <email> <admin|student>\n");
    exit(2);
}

try {
    $pdo = Database::connect(Config::fromEnvFile());
    $stmt = $pdo->prepare('UPDATE users SET role = ? WHERE email = ?');
    $stmt->execute([$role, $email]);

    $check = $pdo->prepare('SELECT role FROM users WHERE email = ?');
    $check->execute([$email]);
    $current = $check->fetchColumn();
    if ($current === false) {
        fwrite(STDERR, "No account with that email exists. Register it in the app first, then run this command.\n");
        exit(1);
    }
    echo "Done: $email now has the role '$current'.\n";
    echo "Existing sessions keep working; the new role applies from the next request.\n";
} catch (ConfigException $e) {
    fwrite(STDERR, "Configuration problem: " . $e->getMessage() . "\n");
    exit(1);
} catch (PDOException $e) {
    fwrite(STDERR, "Database error: " . $e->getMessage() . "\n");
    exit(1);
}

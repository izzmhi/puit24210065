<?php

declare(strict_types=1);

namespace App;

use PDO;

final class Database
{
    /** Opens a PDO connection that throws exceptions and uses real prepared statements. */
    public static function connect(Config $config): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config->get('DB_HOST', '127.0.0.1'),
            $config->int('DB_PORT', 3306),
            $config->get('DB_NAME'),
        );
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        // Cloud databases require an encrypted connection. DB_SSL_CA is the CA certificate file, either an
        // absolute path or relative to the backend folder (e.g. certs/aiven-ca.pem). Not needed for local MySQL.
        $ca = $config->get('DB_SSL_CA');
        if ($ca !== '') {
            if (!str_starts_with($ca, '/') && preg_match('/^[A-Za-z]:[\\\\\/]/', $ca) !== 1) {
                $ca = dirname(__DIR__) . '/' . $ca;
            }
            $options[PDO::MYSQL_ATTR_SSL_CA] = $ca;
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
        }
        return new PDO($dsn, $config->get('DB_USER'), $config->get('DB_PASS'), $options);
    }
}

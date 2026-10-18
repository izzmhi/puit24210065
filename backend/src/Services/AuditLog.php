<?php

declare(strict_types=1);

namespace App\Services;

use App\Util;
use PDO;

/**
 * Records security events (table auth_events) for the admin reports.
 * Passwords and codes are never logged. A logging failure never stops a login.
 */
final class AuditLog
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function record(string $event, ?int $userId, string $ip, string $detail = ''): void
    {
        try {
            $this->pdo->prepare('INSERT INTO auth_events (event, user_id, ip, detail, created_at) VALUES (?, ?, ?, ?, ?)')
                ->execute([$event, $userId, $ip, $detail === '' ? null : mb_substr($detail, 0, 255), Util::fmt(Util::now())]);
        } catch (\Throwable $e) {
            error_log('[login-auth] could not write audit event: ' . $e->getMessage());
        }
    }
}

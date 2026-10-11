<?php

declare(strict_types=1);

namespace App\Security;

use App\Util;
use PDO;

/**
 * Fixed-window counters stored in MySQL (table rate_limits).
 *
 * Keys are hashed before storage, so email addresses and IP addresses are not kept in
 * readable form. A window starts at the first hit and lasts $window seconds.
 */
final class RateLimiter
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** Records one hit and returns the number of hits in the current window. */
    public function hit(string $key, int $window): int
    {
        $now = Util::now();
        $nowText = Util::fmt($now);
        $cutoff = Util::fmt($now - $window);
        $bucket = hash('sha256', $key);

        // Single atomic statement. In MySQL the assignments run left to right, so `hits` is
        // decided from the old window_start before window_start itself is reset.
        $stmt = $this->pdo->prepare(
            'INSERT INTO rate_limits (bucket, hits, window_start) VALUES (?, 1, ?)
             ON DUPLICATE KEY UPDATE
               hits = IF(window_start < ?, 1, hits + 1),
               window_start = IF(window_start < ?, ?, window_start)'
        );
        $stmt->execute([$bucket, $nowText, $cutoff, $cutoff, $nowText]);

        if (random_int(1, 100) === 1) {
            $this->pdo->prepare('DELETE FROM rate_limits WHERE window_start < ?')
                ->execute([Util::fmt($now - 86400)]);
        }

        return $this->count($key, $window);
    }

    /** Hits recorded in the current window (0 if the window has expired). */
    public function count(string $key, int $window): int
    {
        $stmt = $this->pdo->prepare('SELECT hits FROM rate_limits WHERE bucket = ? AND window_start >= ?');
        $stmt->execute([hash('sha256', $key), Util::fmt(Util::now() - $window)]);
        $hits = $stmt->fetchColumn();
        return $hits === false ? 0 : (int) $hits;
    }

    /** Seconds until the current window for $key ends (at least 1). */
    public function retryAfter(string $key, int $window): int
    {
        $stmt = $this->pdo->prepare('SELECT window_start FROM rate_limits WHERE bucket = ?');
        $stmt->execute([hash('sha256', $key)]);
        $start = $stmt->fetchColumn();
        if ($start === false) {
            return 1;
        }
        $end = (int) strtotime((string) $start . ' UTC') + $window;
        return max(1, $end - Util::now());
    }

    public function clear(string $key): void
    {
        $this->pdo->prepare('DELETE FROM rate_limits WHERE bucket = ?')->execute([hash('sha256', $key)]);
    }
}

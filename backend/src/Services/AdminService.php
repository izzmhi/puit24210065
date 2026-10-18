<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\HttpException;
use App\Security\Passwords;
use App\Security\RateLimiter;
use App\Util;
use PDO;

/**
 * Administrator features: user list, deleting and restoring accounts, restore points,
 * full database backup, reports and the salt-and-pepper demonstration.
 * The caller (Kernel) has already checked that the current user is an admin.
 */
final class AdminService
{
    /** Tables in the order they are written to a backup (parents before children). */
    private const BACKUP_TABLES = ['users', 'otp_challenges', 'sessions', 'rate_limits', 'auth_events', 'restore_points'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly Passwords $passwords,
        private readonly RateLimiter $limiter,
        private readonly AuditLog $audit,
    ) {
    }

    /* ------------------------------------------------------------------ users */

    /** @return array<string,mixed> */
    public function listUsers(int $limit, int $offset): array
    {
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);

        $stmt = $this->pdo->prepare(
            'SELECT id, name, email, role, created_at, last_login_at, deleted_at, password_hash
               FROM users ORDER BY id ASC LIMIT ? OFFSET ?'
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, PDO::PARAM_INT);
        $stmt->execute();
        // Admins also see how each password is stored (algorithm, settings, salt); the hash itself is cut off.
        $users = array_map(fn(array $row): array => $this->adminUser($row), $stmt->fetchAll());

        return ['users' => $users, 'total' => $this->count('SELECT COUNT(*) FROM users'), 'summary' => $this->summary()];
    }

    /** @return array<string,int> */
    private function summary(): array
    {
        $active = $this->pdo->prepare('SELECT COUNT(*) FROM sessions WHERE revoked_at IS NULL AND expires_at > ?');
        $active->execute([Util::fmt(Util::now())]);
        return [
            'admins' => $this->count("SELECT COUNT(*) FROM users WHERE role = 'admin' AND deleted_at IS NULL"),
            'students' => $this->count("SELECT COUNT(*) FROM users WHERE role = 'student' AND deleted_at IS NULL"),
            'deleted' => $this->count('SELECT COUNT(*) FROM users WHERE deleted_at IS NOT NULL'),
            'active_sessions' => (int) $active->fetchColumn(),
        ];
    }

    /**
     * Marks an account as deleted. The row stays in the database (shown as "deleted"), the person can
     * no longer log in, and their sessions and pending codes are cancelled.
     *
     * @param array<string,mixed> $admin
     * @return array<string,mixed>
     */
    public function deleteUser(array $admin, mixed $userId, string $ip): array
    {
        $user = $this->findUserForAdmin($userId);
        if ((int) $user['id'] === (int) $admin['id']) {
            throw new HttpException(422, 'cannot_delete_self', 'You cannot delete your own account.');
        }
        if ($user['deleted_at'] !== null) {
            throw new HttpException(409, 'already_deleted', 'This account is already deleted.');
        }

        $now = Util::fmt(Util::now());
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('UPDATE users SET deleted_at = ? WHERE id = ?')->execute([$now, $user['id']]);
            $this->pdo->prepare('UPDATE sessions SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL')->execute([$now, $user['id']]);
            $this->pdo->prepare('DELETE FROM otp_challenges WHERE user_id = ?')->execute([$user['id']]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $this->audit->record('user_deleted', (int) $admin['id'], $ip, 'deleted ' . $user['email']);
        return $this->adminUser($this->findUserForAdmin($user['id']));
    }

    /**
     * Undoes a delete: the account becomes active again with the same data and password.
     *
     * @param array<string,mixed> $admin
     * @return array<string,mixed>
     */
    public function restoreUser(array $admin, mixed $userId, string $ip): array
    {
        $user = $this->findUserForAdmin($userId);
        if ($user['deleted_at'] === null) {
            throw new HttpException(409, 'not_deleted', 'This account is not deleted.');
        }
        $this->pdo->prepare('UPDATE users SET deleted_at = NULL WHERE id = ?')->execute([$user['id']]);
        $this->audit->record('user_restored', (int) $admin['id'], $ip, 'restored ' . $user['email']);
        return $this->adminUser($this->findUserForAdmin($user['id']));
    }

    /* ------------------------------------------------------------ restore points */

    /**
     * Saves the current configuration: each account's role and whether it is deleted.
     *
     * @param array<string,mixed> $admin
     * @return array<string,mixed>
     */
    public function createRestorePoint(array $admin, mixed $label, string $ip): array
    {
        $label = is_string($label) ? trim((string) preg_replace('/\s+/', ' ', $label)) : '';
        if ($label === '') {
            $label = 'Restore point ' . gmdate('Y-m-d H:i') . ' UTC';
        }
        if (mb_strlen($label) > 100 || preg_match('/[\p{C}<>]/u', $label) === 1) {
            throw new HttpException(422, 'validation_failed', 'The name must be at most 100 characters, without < or >.', [
                'label' => 'At most 100 characters, without < or >.',
            ]);
        }

        $rows = $this->pdo->query('SELECT id, role, deleted_at FROM users ORDER BY id')->fetchAll();
        $config = array_map(fn(array $r): array => [
            'id' => (int) $r['id'],
            'role' => (string) $r['role'],
            'deleted_at' => $r['deleted_at'] === null ? null : (string) $r['deleted_at'],
        ], $rows);

        $this->pdo->prepare('INSERT INTO restore_points (label, created_by, created_at, user_count, config) VALUES (?, ?, ?, ?, ?)')
            ->execute([$label, $admin['id'], Util::fmt(Util::now()), count($config), json_encode($config, JSON_THROW_ON_ERROR)]);
        $id = (int) $this->pdo->lastInsertId();

        $this->audit->record('restore_point_created', (int) $admin['id'], $ip, '#' . $id . ' ' . $label);
        return $this->findRestorePoint($id);
    }

    /** @return list<array<string,mixed>> */
    public function listRestorePoints(): array
    {
        $rows = $this->pdo->query(
            'SELECT r.id, r.label, r.created_at, r.user_count, u.email AS created_by
               FROM restore_points r LEFT JOIN users u ON u.id = r.created_by
              ORDER BY r.id DESC LIMIT 50'
        )->fetchAll();
        return array_map(fn(array $r): array => $this->restorePointOut($r), $rows);
    }

    /**
     * Applies a restore point. Only the configuration (role and deleted status) of accounts that existed
     * when it was made is changed. Names, emails, passwords and accounts created later are not touched.
     *
     * @param array<string,mixed> $admin
     * @return array<string,mixed>
     */
    public function applyRestorePoint(array $admin, mixed $id, string $ip): array
    {
        $id = filter_var($id, FILTER_VALIDATE_INT);
        if ($id === false) {
            throw new HttpException(422, 'validation_failed', 'Choose a restore point.');
        }
        $stmt = $this->pdo->prepare('SELECT id, label, config FROM restore_points WHERE id = ?');
        $stmt->execute([$id]);
        $point = $stmt->fetch();
        if ($point === false) {
            throw new HttpException(404, 'not_found', 'Restore point not found.');
        }
        $saved = [];
        foreach (json_decode((string) $point['config'], true, 8, JSON_THROW_ON_ERROR) as $entry) {
            $saved[(int) $entry['id']] = $entry;
        }

        $current = $this->pdo->query('SELECT id, role, deleted_at FROM users')->fetchAll();
        $changes = [];
        $createdLater = 0;
        foreach ($current as $row) {
            $userId = (int) $row['id'];
            if (!isset($saved[$userId])) {
                $createdLater++; // created after the restore point: left as it is
                continue;
            }
            $role = in_array($saved[$userId]['role'], ['admin', 'student'], true) ? $saved[$userId]['role'] : 'student';
            $deletedAt = $saved[$userId]['deleted_at'];
            if ($role !== $row['role'] || ($deletedAt === null) !== ($row['deleted_at'] === null)) {
                $changes[$userId] = ['role' => $role, 'deleted_at' => $deletedAt, 'was_deleted' => $row['deleted_at'] !== null];
            }
        }

        // Never lock the current admin out.
        $mine = $changes[(int) $admin['id']] ?? null;
        if ($mine !== null && ($mine['role'] !== 'admin' || $mine['deleted_at'] !== null)) {
            throw new HttpException(409, 'would_remove_own_access', 'This restore point would remove your own admin access, so it was not applied.');
        }

        $now = Util::fmt(Util::now());
        $this->pdo->beginTransaction();
        try {
            $update = $this->pdo->prepare('UPDATE users SET role = ?, deleted_at = ? WHERE id = ?');
            $revoke = $this->pdo->prepare('UPDATE sessions SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL');
            foreach ($changes as $userId => $change) {
                $update->execute([$change['role'], $change['deleted_at'], $userId]);
                if ($change['deleted_at'] !== null && !$change['was_deleted']) {
                    $revoke->execute([$now, $userId]);
                }
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $this->audit->record('restore_point_applied', (int) $admin['id'], $ip, '#' . $point['id'] . ' changed ' . count($changes) . ' account(s)');
        return [
            'restore_point' => (int) $point['id'],
            'changed' => count($changes),
            'accounts_in_restore_point' => count($saved),
            'accounts_created_later' => $createdLater,
        ];
    }

    /* -------------------------------------------------------------------- backup */

    /**
     * Full database backup as an SQL file. Importing it (phpMyAdmin or the mysql command) recreates
     * every table exactly as it is now. Contains password hashes, so only admins can download it.
     *
     * @param array<string,mixed> $admin
     * @return array<string,mixed>
     */
    public function backup(array $admin, string $ip): array
    {
        $now = Util::now();
        $sql = [
            '-- Secure Login System: full database backup',
            '-- Created ' . gmdate('Y-m-d H:i:s', $now) . ' UTC by ' . $admin['email'],
            '-- Restore: import this file into the database (phpMyAdmin > Import, or: mysql <database> < file.sql).',
            '-- WARNING: restoring REPLACES all current data with the data in this file.',
            '',
            'SET FOREIGN_KEY_CHECKS = 0;',
        ];
        foreach (array_reverse(self::BACKUP_TABLES) as $table) {
            $sql[] = "DROP TABLE IF EXISTS `$table`;";
        }
        $schema = (string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql');
        $sql[] = trim((string) preg_replace('/^\s*--.*$\n?/m', '', $schema));
        $sql[] = '';

        $counts = [];
        foreach (self::BACKUP_TABLES as $table) {
            $rows = $this->pdo->query("SELECT * FROM `$table` ORDER BY 1")->fetchAll();
            $counts[$table] = count($rows);
            foreach (array_chunk($rows, 100) as $chunk) {
                $columns = '`' . implode('`, `', array_keys($chunk[0])) . '`';
                $values = array_map(fn(array $row): string => '(' . implode(', ', array_map(fn($v): string => $this->sqlValue($v), $row)) . ')', $chunk);
                $sql[] = "INSERT INTO `$table` ($columns) VALUES\n" . implode(",\n", $values) . ';';
            }
        }
        $sql[] = '';
        $sql[] = 'SET FOREIGN_KEY_CHECKS = 1;';

        $this->audit->record('backup_downloaded', (int) $admin['id'], $ip, json_encode($counts) ?: '');
        return [
            'filename' => 'secure-login-backup-' . gmdate('Ymd-His', $now) . '.sql',
            'tables' => $counts,
            'content' => implode("\n", $sql) . "\n",
        ];
    }

    private function sqlValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        return (string) $this->pdo->quote((string) $value);
    }

    /* ------------------------------------------------------------------- reports */

    /** @return array<string,mixed> */
    public function reports(): array
    {
        $since = Util::now() - 7 * 86400;
        $stmt = $this->pdo->prepare('SELECT event, created_at FROM auth_events WHERE created_at >= ? ORDER BY id');
        $stmt->execute([Util::fmt($since)]);

        $byEvent = [];
        $loginsPerDay = [];
        for ($i = 6; $i >= 0; $i--) {
            $loginsPerDay[gmdate('Y-m-d', Util::now() - $i * 86400)] = 0;
        }
        foreach ($stmt->fetchAll() as $row) {
            $event = (string) $row['event'];
            $byEvent[$event] = ($byEvent[$event] ?? 0) + 1;
            $day = substr((string) $row['created_at'], 0, 10);
            if ($event === 'login_success' && isset($loginsPerDay[$day])) {
                $loginsPerDay[$day]++;
            }
        }
        ksort($byEvent);

        $recent = $this->pdo->query(
            'SELECT e.created_at, e.event, e.detail, e.ip, u.email
               FROM auth_events e LEFT JOIN users u ON u.id = e.user_id
              ORDER BY e.id DESC LIMIT 25'
        )->fetchAll();

        $lastBackup = $this->pdo->query("SELECT MAX(created_at) FROM auth_events WHERE event = 'backup_downloaded'")->fetchColumn();

        return [
            'users' => $this->summary() + ['total' => $this->count('SELECT COUNT(*) FROM users')],
            'last_7_days' => [
                'events' => $byEvent,
                'logins_per_day' => array_map(fn(string $d, int $n): array => ['date' => $d, 'logins' => $n], array_keys($loginsPerDay), $loginsPerDay),
            ],
            'restore_points' => $this->count('SELECT COUNT(*) FROM restore_points'),
            'last_backup' => $lastBackup ? Util::iso((string) $lastBackup) : null,
            'recent_events' => array_map(fn(array $r): array => [
                'time' => Util::iso((string) $r['created_at']),
                'event' => (string) $r['event'],
                'email' => $r['email'] === null ? null : (string) $r['email'],
                'detail' => $r['detail'] === null ? null : (string) $r['detail'],
                'ip' => $r['ip'] === null ? null : (string) $r['ip'],
            ], $recent),
        ];
    }

    /* ---------------------------------------------------- salt and pepper demo */

    /**
     * @param array<string,mixed> $admin
     * @return array<string,mixed>
     */
    public function passwordDemo(array $admin, mixed $password): array
    {
        if (!is_string($password) || $password === '' || mb_strlen($password) > 128) {
            throw new HttpException(422, 'validation_failed', 'Type a sample password (1 to 128 characters).', [
                'password' => 'Type a sample password (1 to 128 characters).',
            ]);
        }
        $key = 'demo:user:' . $admin['id'];
        if ($this->limiter->hit($key, 900) > 30) {
            throw new HttpException(429, 'rate_limited', 'Too many demonstrations. Please wait a few minutes.');
        }
        return $this->passwords->demo($password);
    }

    /* ------------------------------------------------------------------- helpers */

    /** @return array<string,mixed> */
    private function findUserForAdmin(mixed $userId): array
    {
        $id = filter_var($userId, FILTER_VALIDATE_INT);
        if ($id === false) {
            throw new HttpException(422, 'validation_failed', 'Choose a user.');
        }
        $stmt = $this->pdo->prepare('SELECT id, name, email, role, created_at, last_login_at, deleted_at, password_hash FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new HttpException(404, 'not_found', 'User not found.');
        }
        return $row;
    }

    /** @return array<string,mixed> */
    private function findRestorePoint(int $id): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.id, r.label, r.created_at, r.user_count, u.email AS created_by
               FROM restore_points r LEFT JOIN users u ON u.id = r.created_by WHERE r.id = ?'
        );
        $stmt->execute([$id]);
        return $this->restorePointOut($stmt->fetch());
    }

    /** @return array<string,mixed> */
    private function restorePointOut(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'label' => (string) $r['label'],
            'created_at' => Util::iso((string) $r['created_at']),
            'created_by' => $r['created_by'] === null ? null : (string) $r['created_by'],
            'user_count' => (int) $r['user_count'],
        ];
    }

    /**
     * Fields shown to admins. The full password hash never leaves the server; only a shortened preview.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function adminUser(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'email' => (string) $row['email'],
            'role' => (string) $row['role'],
            'created_at' => Util::iso((string) $row['created_at']),
            'last_login_at' => isset($row['last_login_at']) ? Util::iso((string) $row['last_login_at']) : null,
            'status' => $row['deleted_at'] === null ? 'active' : 'deleted',
            'deleted_at' => $row['deleted_at'] === null ? null : Util::iso((string) $row['deleted_at']),
            'password_storage' => Passwords::preview((string) $row['password_hash']),
        ];
    }

    private function count(string $sql): int
    {
        return (int) $this->pdo->query($sql)->fetchColumn();
    }
}

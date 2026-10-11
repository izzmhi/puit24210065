<?php

declare(strict_types=1);

namespace App\Services;

use App\Config;
use App\Http\HttpException;
use App\Mail\OtpDelivery;
use App\Security\Passwords;
use App\Security\RateLimiter;
use App\Security\Tokens;
use App\Util;
use App\Validation\Validator;
use PDO;

/**
 * Registration, password check, one-time-code (2FA) check, sessions and role checks.
 * Every database access uses prepared statements.
 */
final class AuthService
{
    public const OTP_TTL = 300;          // one-time codes expire after 5 minutes
    public const OTP_MAX_ATTEMPTS = 5;   // wrong guesses allowed per code
    public const RESEND_COOLDOWN = 30;   // seconds before another code can be requested

    private const WINDOW = 900;          // 15 minutes, for the rate-limit counters below
    private const LOGIN_IP_MAX = 30;
    private const LOGIN_EMAIL_FAILURES_MAX = 8;
    private const OTP_IP_MAX = 30;
    private const OTP_USER_FAILURES_MAX = 10;
    private const CHALLENGES_PER_USER_MAX = 6;
    private const REGISTER_IP_MAX = 10;  // per hour

    private readonly string $otpKey;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Config $config,
        private readonly Passwords $passwords,
        private readonly RateLimiter $limiter,
        private readonly OtpDelivery $delivery,
    ) {
        // The pepper also keys the OTP hash, so a leaked database cannot be used to
        // brute-force the (small) six-digit code space offline.
        $this->otpKey = hash_hmac('sha256', 'otp-key-v1', $config->get('APP_PEPPER'));
    }

    /* ------------------------------------------------------------------ registration */

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed> the new user (public fields only)
     */
    public function register(array $input, string $ip): array
    {
        $this->throttle('register:ip:' . $ip, self::REGISTER_IP_MAX, 3600);

        [$errors, $clean] = Validator::registration($input);
        if ($errors !== []) {
            throw new HttpException(422, 'validation_failed', 'Please correct the highlighted fields.', $errors);
        }

        $exists = $this->pdo->prepare('SELECT 1 FROM users WHERE email = ?');
        $exists->execute([$clean['email']]);
        if ($exists->fetchColumn() !== false) {
            $this->emailTaken();
        }

        $hash = $this->passwords->hash($clean['password']);
        $now = Util::fmt(Util::now());
        try {
            // Public registration can only ever create the least-privileged role.
            // Administrators are promoted with bin/set-role.php (see README).
            $insert = $this->pdo->prepare(
                'INSERT INTO users (name, email, password_hash, role, created_at) VALUES (?, ?, ?, ?, ?)'
            );
            $insert->execute([$clean['name'], $clean['email'], $hash, 'user', $now]);
        } catch (\PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) { // duplicate key: lost a race with another request
                $this->emailTaken();
            }
            throw $e;
        }

        return [
            'id' => (int) $this->pdo->lastInsertId(),
            'name' => $clean['name'],
            'email' => $clean['email'],
            'role' => 'user',
        ];
    }

    private function emailTaken(): never
    {
        throw new HttpException(409, 'email_taken', 'An account with this email address already exists.', [
            'email' => 'An account with this email address already exists.',
        ]);
    }

    /* ------------------------------------------------------- step 1: password check */

    /**
     * Verifies the password and, only if it is correct, issues a one-time code.
     * No session exists yet: the user is not signed in until the code is verified.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function login(array $input, string $ip): array
    {
        $this->throttle('login:ip:' . $ip, self::LOGIN_IP_MAX, self::WINDOW);

        $email = Validator::normalizeEmail($input['email'] ?? null);
        $password = $input['password'] ?? null;
        if ($email === '' || !is_string($password) || $password === '' || strlen($password) > 1024) {
            throw new HttpException(422, 'validation_failed', 'Enter your email address and password.');
        }

        $emailKey = 'login:email:' . $email;
        $this->assertNotLocked($emailKey, self::LOGIN_EMAIL_FAILURES_MAX, self::WINDOW);

        $stmt = $this->pdo->prepare('SELECT id, name, email, password_hash FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user === false) {
            $this->passwords->burn($password); // keep response time similar for unknown emails
            $this->failLogin($emailKey);
        }
        if (!$this->passwords->verify($password, (string) $user['password_hash'])) {
            $this->failLogin($emailKey);
        }

        $this->limiter->clear($emailKey);
        if ($this->passwords->needsRehash((string) $user['password_hash'])) {
            $this->pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([$this->passwords->hash($password), $user['id']]);
        }

        $this->maybeCleanup();
        return $this->issueChallenge($user, $ip);
    }

    private function failLogin(string $emailKey): never
    {
        $this->limiter->hit($emailKey, self::WINDOW);
        // The same message and status for "unknown email" and "wrong password".
        throw new HttpException(401, 'invalid_credentials', 'Invalid email or password.');
    }

    /**
     * @param array<string,mixed> $user row with id, name, email
     * @return array<string,mixed>
     */
    private function issueChallenge(array $user, string $ip): array
    {
        $userId = (int) $user['id'];
        $this->throttle('challenge:user:' . $userId, self::CHALLENGES_PER_USER_MAX, self::WINDOW);

        $challengeId = Tokens::random();
        $otp = Tokens::otp();
        $now = Util::now();

        $this->pdo->prepare('DELETE FROM otp_challenges WHERE user_id = ?')->execute([$userId]);
        $this->pdo->prepare(
            'INSERT INTO otp_challenges (user_id, challenge_hash, otp_hash, attempts, expires_at, created_at, ip)
             VALUES (?, ?, ?, 0, ?, ?, ?)'
        )->execute([
            $userId,
            Tokens::hash($challengeId),
            $this->otpHash($challengeId, $otp),
            Util::fmt($now + self::OTP_TTL),
            Util::fmt($now),
            $ip,
        ]);

        try {
            $extra = $this->delivery->deliver((string) $user['email'], (string) $user['name'], $otp, self::OTP_TTL);
        } catch (\Throwable $e) {
            error_log('[login-auth] OTP delivery failed: ' . get_class($e) . ': ' . $e->getMessage());
            $this->pdo->prepare('DELETE FROM otp_challenges WHERE user_id = ?')->execute([$userId]);
            throw new HttpException(503, 'otp_delivery_failed', 'We could not send the verification code. Please try again later.');
        }

        return array_merge([
            'challenge_id' => $challengeId,
            'expires_in' => self::OTP_TTL,
            'email_hint' => Util::maskEmail((string) $user['email']),
        ], $extra);
    }

    /**
     * Sends a fresh code for a pending sign-in (the previous code stops working).
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function resendOtp(array $input, string $ip): array
    {
        $this->throttle('resend:ip:' . $ip, 20, self::WINDOW);

        $challengeId = $input['challenge_id'] ?? null;
        if (!Validator::isToken($challengeId)) {
            throw new HttpException(422, 'validation_failed', 'Missing or malformed challenge.');
        }

        $stmt = $this->pdo->prepare(
            'SELECT c.created_at, c.used_at, c.expires_at, u.id, u.name, u.email
               FROM otp_challenges c INNER JOIN users u ON u.id = c.user_id
              WHERE c.challenge_hash = ? LIMIT 1'
        );
        $stmt->execute([Tokens::hash((string) $challengeId)]);
        $row = $stmt->fetch();

        if ($row === false || $row['used_at'] !== null || (string) $row['expires_at'] <= Util::fmt(Util::now())) {
            throw new HttpException(401, 'invalid_challenge', 'This sign-in attempt has expired. Please sign in again.');
        }

        $age = Util::now() - (int) strtotime((string) $row['created_at'] . ' UTC');
        if ($age < self::RESEND_COOLDOWN) {
            $wait = self::RESEND_COOLDOWN - $age;
            throw new HttpException(429, 'resend_too_soon', 'Please wait a moment before requesting another code.', [
                'retry_after' => $wait,
            ], ['Retry-After' => (string) $wait]);
        }

        return $this->issueChallenge($row, $ip);
    }

    /* -------------------------------------------------- step 2: one-time code check */

    /**
     * Verifies the one-time code and opens a session.
     *
     * @param array<string,mixed> $input
     * @return array{token:string, expires_at:string, user:array<string,mixed>}
     */
    public function verifyOtp(array $input, string $ip, ?string $userAgent): array
    {
        $this->throttle('otp:ip:' . $ip, self::OTP_IP_MAX, self::WINDOW);

        $challengeId = $input['challenge_id'] ?? null;
        $otp = $input['otp'] ?? null;
        if (!Validator::isToken($challengeId) || !Validator::isOtp($otp)) {
            throw new HttpException(422, 'validation_failed', 'Enter the six-digit code.');
        }
        $challengeId = (string) $challengeId;
        $otp = (string) $otp;

        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, otp_hash, attempts, expires_at, used_at FROM otp_challenges WHERE challenge_hash = ? LIMIT 1'
        );
        $stmt->execute([Tokens::hash($challengeId)]);
        $row = $stmt->fetch();

        $now = Util::now();
        $invalid = new HttpException(401, 'invalid_code', 'Invalid or expired code.');
        if ($row === false || $row['used_at'] !== null || (string) $row['expires_at'] <= Util::fmt($now)) {
            throw $invalid;
        }

        $userKey = 'otp:user:' . $row['user_id'];
        $this->assertNotLocked($userKey, self::OTP_USER_FAILURES_MAX, self::WINDOW);
        if ((int) $row['attempts'] >= self::OTP_MAX_ATTEMPTS) {
            throw new HttpException(429, 'too_many_attempts', 'Too many incorrect codes. Please sign in again to get a new code.');
        }

        // Count this attempt BEFORE comparing, in one atomic statement, so parallel
        // requests cannot squeeze in extra guesses.
        $claim = $this->pdo->prepare(
            'UPDATE otp_challenges SET attempts = attempts + 1
              WHERE id = ? AND attempts < ? AND used_at IS NULL AND expires_at > ?'
        );
        $claim->execute([$row['id'], self::OTP_MAX_ATTEMPTS, Util::fmt($now)]);
        if ($claim->rowCount() !== 1) {
            throw $invalid;
        }

        if (!hash_equals((string) $row['otp_hash'], $this->otpHash($challengeId, $otp))) {
            $this->limiter->hit($userKey, self::WINDOW);
            $remaining = max(0, self::OTP_MAX_ATTEMPTS - ((int) $row['attempts'] + 1));
            throw new HttpException(
                401,
                'incorrect_code',
                $remaining > 0 ? 'Incorrect code. Please check it and try again.' : 'Incorrect code. No attempts are left; please sign in again.',
                ['attempts_remaining' => $remaining]
            );
        }

        // Single use: only the request that flips used_at from NULL succeeds.
        $this->pdo->beginTransaction();
        try {
            $consume = $this->pdo->prepare('UPDATE otp_challenges SET used_at = ? WHERE id = ? AND used_at IS NULL');
            $consume->execute([Util::fmt($now), $row['id']]);
            if ($consume->rowCount() !== 1) {
                $this->pdo->rollBack();
                throw $invalid;
            }

            $token = Tokens::random();
            $expires = $now + $this->config->sessionTtlSeconds();
            $this->pdo->prepare(
                'INSERT INTO sessions (user_id, token_hash, created_at, expires_at, ip, user_agent) VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([
                $row['user_id'],
                Tokens::hash($token),
                Util::fmt($now),
                Util::fmt($expires),
                $ip,
                $userAgent === null ? null : substr((string) preg_replace('/[^\x20-\x7E]/', '', $userAgent), 0, 255),
            ]);
            $this->pdo->prepare('UPDATE users SET last_login_at = ? WHERE id = ?')->execute([Util::fmt($now), $row['user_id']]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $this->limiter->clear($userKey);
        $this->promoteListedAdmin((int) $row['user_id']);
        $user = $this->findUser((int) $row['user_id']);

        return ['token' => $token, 'expires_at' => (string) Util::iso(Util::fmt($expires)), 'user' => $user];
    }

    private function otpHash(string $challengeId, string $otp): string
    {
        return hash_hmac('sha256', $challengeId . ':' . $otp, $this->otpKey);
    }

    /**
     * Emails listed in the server setting ADMIN_EMAILS become admin, but only here: after the
     * password AND the emailed code were correct, which proves the person controls that inbox.
     * Registering with a listed email (or any name) never gives admin by itself.
     */
    private function promoteListedAdmin(int $userId): void
    {
        $admins = array_map('strtolower', $this->config->list('ADMIN_EMAILS'));
        if ($admins === []) {
            return;
        }
        $stmt = $this->pdo->prepare('SELECT email FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $email = $stmt->fetchColumn();
        if (is_string($email) && in_array(strtolower($email), $admins, true)) {
            $this->pdo->prepare("UPDATE users SET role = 'admin' WHERE id = ? AND role <> 'admin'")->execute([$userId]);
        }
    }

    /* --------------------------------------------------------- sessions and roles */

    /**
     * Resolves a session token to the user. The role always comes from the database,
     * never from the client.
     *
     * @return array{user:array<string,mixed>, session_id:int}
     */
    public function authenticate(?string $token): array
    {
        if (!Validator::isToken($token)) {
            throw new HttpException(401, 'unauthenticated', 'Authentication required.');
        }
        $stmt = $this->pdo->prepare(
            'SELECT s.id AS session_id, u.id, u.name, u.email, u.role, u.created_at, u.last_login_at
               FROM sessions s INNER JOIN users u ON u.id = s.user_id
              WHERE s.token_hash = ? AND s.revoked_at IS NULL AND s.expires_at > ? LIMIT 1'
        );
        $stmt->execute([Tokens::hash((string) $token), Util::fmt(Util::now())]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new HttpException(401, 'unauthenticated', 'Your session has expired. Please sign in again.');
        }
        return ['user' => $this->publicUser($row), 'session_id' => (int) $row['session_id']];
    }

    /** @param array<string,mixed> $user */
    public function requireRole(array $user, string $role): void
    {
        if (($user['role'] ?? null) !== $role) {
            throw new HttpException(403, 'forbidden', 'You do not have permission to perform this action.');
        }
    }

    public function logout(int $sessionId): void
    {
        $this->pdo->prepare('UPDATE sessions SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL')
            ->execute([Util::fmt(Util::now()), $sessionId]);
    }

    /* ------------------------------------------------------------------- admin */

    /** @return array<string,mixed> */
    public function listUsers(int $limit, int $offset): array
    {
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);

        $stmt = $this->pdo->prepare(
            'SELECT id, name, email, role, created_at, last_login_at FROM users ORDER BY id ASC LIMIT ? OFFSET ?'
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $users = array_map(fn(array $row): array => $this->publicUser($row), $stmt->fetchAll());

        $counts = $this->pdo->query('SELECT role, COUNT(*) AS n FROM users GROUP BY role')->fetchAll();
        $byRole = ['admin' => 0, 'user' => 0];
        foreach ($counts as $count) {
            $byRole[(string) $count['role']] = (int) $count['n'];
        }
        $active = $this->pdo->prepare('SELECT COUNT(*) FROM sessions WHERE revoked_at IS NULL AND expires_at > ?');
        $active->execute([Util::fmt(Util::now())]);

        return [
            'users' => $users,
            'total' => $byRole['admin'] + $byRole['user'],
            'summary' => [
                'admins' => $byRole['admin'],
                'users' => $byRole['user'],
                'active_sessions' => (int) $active->fetchColumn(),
            ],
        ];
    }

    /* ----------------------------------------------------------------- helpers */

    /** @return array<string,mixed> */
    private function findUser(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT id, name, email, role, created_at, last_login_at FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new HttpException(401, 'unauthenticated', 'Authentication required.');
        }
        return $this->publicUser($row);
    }

    /**
     * Only these fields ever leave the server. password_hash is never selected for output.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function publicUser(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'email' => (string) $row['email'],
            'role' => (string) $row['role'],
            'created_at' => Util::iso((string) $row['created_at']),
            'last_login_at' => isset($row['last_login_at']) ? Util::iso((string) $row['last_login_at']) : null,
        ];
    }

    private function throttle(string $key, int $max, int $window): void
    {
        if ($this->limiter->hit($key, $window) > $max) {
            $this->tooMany($key, $window);
        }
    }

    private function assertNotLocked(string $key, int $max, int $window): void
    {
        if ($this->limiter->count($key, $window) >= $max) {
            $this->tooMany($key, $window);
        }
    }

    private function tooMany(string $key, int $window): never
    {
        $retry = $this->limiter->retryAfter($key, $window);
        throw new HttpException(
            429,
            'rate_limited',
            'Too many attempts. Please wait a few minutes and try again.',
            ['retry_after' => $retry],
            ['Retry-After' => (string) $retry]
        );
    }

    /** Occasionally removes long-expired sessions and codes so the tables stay small. */
    private function maybeCleanup(): void
    {
        if (random_int(1, 50) !== 1) {
            return;
        }
        $cutoff = Util::fmt(Util::now() - 86400);
        $this->pdo->prepare('DELETE FROM sessions WHERE expires_at < ?')->execute([$cutoff]);
        $this->pdo->prepare('DELETE FROM otp_challenges WHERE expires_at < ?')->execute([$cutoff]);
    }
}

<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Password hashing with a per-password salt and a server-side pepper.
 *
 *  - Salt:   password_hash() generates a unique random salt for every hash and stores it
 *            inside the resulting hash string.
 *  - Pepper: a secret from the server configuration. The password is first run through
 *            HMAC-SHA256 keyed with the pepper, and the result is what gets hashed. The
 *            pepper is never stored in the database, so a stolen database alone is not
 *            enough to test password guesses offline.
 *
 * Pre-hashing with HMAC also avoids bcrypt's 72-byte input limit and NUL-byte problems.
 */
final class Passwords
{
    public function __construct(private readonly string $pepper)
    {
        if (strlen($pepper) < 32) {
            throw new \InvalidArgumentException('The pepper must be at least 32 characters long.');
        }
    }

    public function hash(string $password): string
    {
        $hash = password_hash($this->prehash($password), self::algorithm(), self::options());
        if (!is_string($hash)) {
            throw new \RuntimeException('Password hashing failed.');
        }
        return $hash;
    }

    public function verify(string $password, string $hash): bool
    {
        return password_verify($this->prehash($password), $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, self::algorithm(), self::options());
    }

    /**
     * Spends roughly the same time as a real verification. Used when the email is unknown so
     * that response time does not reveal whether an account exists.
     */
    public function burn(string $password): void
    {
        $this->hash($password);
    }

    /** Argon2id when this PHP build supports it, otherwise bcrypt. */
    public static function algorithm(): string
    {
        return in_array('argon2id', password_algos(), true) ? 'argon2id' : PASSWORD_BCRYPT;
    }

    /** @return array<string,int> */
    private static function options(): array
    {
        return self::algorithm() === 'argon2id' ? [] : ['cost' => 12];
    }

    private function prehash(string $password): string
    {
        return base64_encode(hash_hmac('sha256', $password, $this->pepper, true));
    }

    /**
     * Step-by-step demonstration for the admin "Password demo" page. Nothing is stored.
     * The pepper is never returned, and the peppered value is shortened so it cannot be reused.
     *
     * @return array<string,mixed>
     */
    public function demo(string $password): array
    {
        $first = $this->hash($password);
        $second = $this->hash($password);
        return [
            'algorithm' => self::algorithm() === 'argon2id' ? 'argon2id' : 'bcrypt',
            'peppered_preview' => substr($this->prehash($password), 0, 12) . '…',
            'hash_1' => $first,
            'salt_1' => self::saltOf($first),
            'hash_2' => $second,
            'salt_2' => self::saltOf($second),
            'same_hash' => $first === $second,
            'verify_correct_password' => $this->verify($password, $first),
            'verify_wrong_password' => $this->verify($password . 'x', $first),
            'verify_without_pepper' => password_verify($password, $first),
        ];
    }

    /** The random salt inside a password_hash() result. */
    public static function saltOf(string $hash): string
    {
        if (str_starts_with($hash, '$argon2')) {
            return explode('$', $hash)[4] ?? '';
        }
        return str_starts_with($hash, '$2') ? substr($hash, 7, 22) : '';
    }

    /**
     * How a password is stored, for the admin user list: algorithm, settings and salt, with the hash cut off.
     * "$argon2id$v=19$m=65536,t=4,p=1$<salt>$<hash>" -> "$argon2id$v=19$m=65536,t=4,p=1$<salt>$…"
     */
    public static function preview(string $hash): string
    {
        if (preg_match('/^(\$argon2[a-z]*\$v=\d+\$[^$]+\$[^$]+)\$/', $hash, $m) === 1) {
            return $m[1] . '$…';
        }
        if (preg_match('/^(\$2[aby]\$\d{2}\$.{22})/', $hash, $m) === 1) {
            return $m[1] . '…';
        }
        return '(hidden)';
    }
}

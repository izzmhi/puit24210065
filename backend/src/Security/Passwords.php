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
}

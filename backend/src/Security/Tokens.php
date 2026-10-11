<?php

declare(strict_types=1);

namespace App\Security;

/** Cryptographically secure random values: session tokens, challenge ids and OTP codes. */
final class Tokens
{
    /** 256 random bits as 64 hex characters. */
    public static function random(): string
    {
        return bin2hex(random_bytes(32));
    }

    /** Only this hash is stored in the database; a database leak does not reveal usable tokens. */
    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Six-digit one-time code from the OS CSPRNG (random_int), zero-padded. */
    public static function otp(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }
}

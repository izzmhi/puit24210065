<?php

declare(strict_types=1);

namespace App\Validation;

/**
 * Server-side input validation. The mobile app repeats these rules for fast feedback,
 * but only these checks are trusted.
 */
final class Validator
{
    public const PASSWORD_MIN = 12;
    public const PASSWORD_MAX = 128;

    /** A deliberately small deny-list of very common passwords (compared case-insensitively). */
    private const COMMON_PASSWORDS = [
        'password1234', 'password12345', 'password123456', 'passw0rd1234', 'qwerty123456',
        'qwertyuiop12', '123456789012', 'letmein12345', 'welcome12345', 'admin1234567',
        'iloveyou1234', 'abc123456789',
    ];

    public static function normalizeEmail(mixed $value): string
    {
        return is_string($value) ? strtolower(trim($value)) : '';
    }

    /**
     * @param array<string,mixed> $input
     * @return array{0: array<string,string>, 1: array{name:string,email:string,password:string}}
     */
    public static function registration(array $input): array
    {
        $errors = [];

        $name = is_string($input['name'] ?? null) ? $input['name'] : '';
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        $nameLen = mb_strlen($name);
        if (!mb_check_encoding($name, 'UTF-8') || $nameLen < 2 || $nameLen > 100) {
            $errors['name'] = 'Enter your full name (2 to 100 characters).';
        } elseif (preg_match('/[\p{C}<>]/u', $name) === 1) {
            $errors['name'] = 'Name contains characters that are not allowed.';
        }

        $email = self::normalizeEmail($input['email'] ?? null);
        if ($email === '' || strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Enter a valid email address.';
        }

        $password = is_string($input['password'] ?? null) ? $input['password'] : '';
        $passwordError = self::passwordError($password, $email);
        if ($passwordError !== null) {
            $errors['password'] = $passwordError;
        }

        return [$errors, ['name' => $name, 'email' => $email, 'password' => $password]];
    }

    public static function passwordError(string $password, string $email = ''): ?string
    {
        $length = mb_strlen($password);
        if ($length < self::PASSWORD_MIN) {
            return 'Password must be at least ' . self::PASSWORD_MIN . ' characters long.';
        }
        if ($length > self::PASSWORD_MAX) {
            return 'Password must be at most ' . self::PASSWORD_MAX . ' characters long.';
        }
        if (preg_match('/\p{L}/u', $password) !== 1 || preg_match('/\d/', $password) !== 1) {
            return 'Password must contain at least one letter and one number.';
        }
        if (in_array(strtolower($password), self::COMMON_PASSWORDS, true)
            || ($email !== '' && strtolower($password) === $email)) {
            return 'That password is too easy to guess. Choose a different one.';
        }
        return null;
    }

    public static function isOtp(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d{6}$/', $value) === 1;
    }

    public static function isToken(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/', $value) === 1;
    }
}

<?php

declare(strict_types=1);

namespace App;

/**
 * Server configuration, loaded from backend/.env and/or real environment variables.
 *
 * Secrets (database password, pepper, SMTP password) only ever live here, on the
 * server. They are never sent to the client and never stored in the database.
 */
final class Config
{
    /** Settings that may be supplied through real environment variables. */
    private const KEYS = [
        'APP_ENV', 'APP_PEPPER',
        'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS',
        'CORS_ALLOWED_ORIGINS',
        'OTP_DELIVERY',
        'SMTP_HOST', 'SMTP_PORT', 'SMTP_SECURE', 'SMTP_USER', 'SMTP_PASS', 'SMTP_FROM',
        'SESSION_TTL_MINUTES', 'COOKIE_SECURE', 'COOKIE_SAMESITE', 'TRUST_PROXY',
    ];

    /** @param array<string,string> $values */
    private function __construct(private array $values)
    {
    }

    /** @param array<string,mixed> $values */
    public static function fromArray(array $values): self
    {
        $clean = [];
        foreach ($values as $key => $value) {
            $clean[(string) $key] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }
        $config = new self($clean);
        $config->validate();
        return $config;
    }

    /** Loads backend/.env (if present) and lets real environment variables override it. */
    public static function fromEnvFile(?string $file = null): self
    {
        $file ??= dirname(__DIR__) . '/.env';
        $values = is_file($file) ? self::parseEnvFile($file) : [];
        foreach (self::KEYS as $key) {
            $fromEnv = getenv($key);
            if ($fromEnv !== false && $fromEnv !== '') {
                $values[$key] = $fromEnv;
            }
        }
        return self::fromArray($values);
    }

    /** @return array<string,string> */
    private static function parseEnvFile(string $file): array
    {
        $values = [];
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            $lines = [];
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            $len = strlen($value);
            if ($len >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[$len - 1] === $value[0]) {
                $value = substr($value, 1, -1);
            }
            $values[$key] = $value;
        }
        return $values;
    }

    public function get(string $key, string $default = ''): string
    {
        $value = $this->values[$key] ?? '';
        return $value !== '' ? $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = strtolower($this->get($key));
        return $value === '' ? $default : in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    public function int(string $key, int $default): int
    {
        $value = $this->get($key);
        return ctype_digit($value) ? (int) $value : $default;
    }

    /** @return list<string> */
    public function list(string $key): array
    {
        $items = array_map('trim', explode(',', $this->get($key)));
        return array_values(array_filter($items, static fn(string $v): bool => $v !== ''));
    }

    /** Defaults to "production" so a forgotten setting fails safe. */
    public function env(): string
    {
        return strtolower($this->get('APP_ENV', 'production'));
    }

    public function isProduction(): bool
    {
        return $this->env() === 'production';
    }

    public function otpDelivery(): string
    {
        return strtolower($this->get('OTP_DELIVERY', 'smtp'));
    }

    public function cookieSecure(): bool
    {
        return $this->bool('COOKIE_SECURE', $this->isProduction());
    }

    public function cookieSameSite(): string
    {
        return ucfirst(strtolower($this->get('COOKIE_SAMESITE', 'Lax')));
    }

    public function sessionTtlSeconds(): int
    {
        return $this->int('SESSION_TTL_MINUTES', 480) * 60;
    }

    /** @return list<string> */
    public function allowedOrigins(): array
    {
        return $this->list('CORS_ALLOWED_ORIGINS');
    }

    private function validate(): void
    {
        $errors = [];

        if (!in_array($this->env(), ['development', 'testing', 'production'], true)) {
            $errors[] = 'APP_ENV must be development, testing or production';
        }

        $pepper = $this->get('APP_PEPPER');
        if ($pepper === '') {
            $errors[] = 'APP_PEPPER is required';
        } elseif (strlen($pepper) < 32) {
            $errors[] = 'APP_PEPPER must be at least 32 characters long';
        } elseif (str_starts_with($pepper, 'REPLACE_')) {
            $errors[] = 'APP_PEPPER still contains the example placeholder';
        }

        foreach (['DB_NAME', 'DB_USER'] as $required) {
            if ($this->get($required) === '') {
                $errors[] = $required . ' is required';
            }
        }
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $this->get('DB_NAME', 'x'))) {
            $errors[] = 'DB_NAME may only contain letters, digits and underscores';
        }

        foreach ($this->allowedOrigins() as $origin) {
            if (!preg_match('#^https?://[A-Za-z0-9.\-]+(:[0-9]{1,5})?$#', $origin)) {
                $errors[] = 'CORS_ALLOWED_ORIGINS must be a comma-separated list of exact origins (no wildcards, no paths)';
                break;
            }
        }

        $delivery = $this->otpDelivery();
        if (!in_array($delivery, ['smtp', 'dev'], true)) {
            $errors[] = 'OTP_DELIVERY must be smtp or dev';
        } elseif ($delivery === 'dev' && $this->isProduction()) {
            $errors[] = 'OTP_DELIVERY=dev (OTP shown in the API response) is not allowed when APP_ENV=production';
        } elseif ($delivery === 'smtp') {
            if ($this->get('SMTP_HOST') === '') {
                $errors[] = 'SMTP_HOST is required when OTP_DELIVERY=smtp';
            }
            if (!filter_var($this->get('SMTP_FROM'), FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'SMTP_FROM must be a valid email address when OTP_DELIVERY=smtp';
            }
            if (!in_array($this->smtpSecure(), ['tls', 'ssl', 'none'], true)) {
                $errors[] = 'SMTP_SECURE must be tls, ssl or none';
            } elseif ($this->smtpSecure() === 'none' && $this->isProduction()) {
                $errors[] = 'SMTP_SECURE=none is not allowed when APP_ENV=production';
            }
        }

        if (!in_array($this->cookieSameSite(), ['Lax', 'Strict', 'None'], true)) {
            $errors[] = 'COOKIE_SAMESITE must be Lax, Strict or None';
        }
        if ($this->cookieSameSite() === 'None' && !$this->cookieSecure()) {
            $errors[] = 'COOKIE_SAMESITE=None requires COOKIE_SECURE=true';
        }
        if ($this->isProduction() && !$this->cookieSecure()) {
            $errors[] = 'COOKIE_SECURE must be true when APP_ENV=production (serve the API over HTTPS)';
        }

        $ttl = $this->get('SESSION_TTL_MINUTES', '480');
        if (!ctype_digit($ttl) || (int) $ttl < 5 || (int) $ttl > 10080) {
            $errors[] = 'SESSION_TTL_MINUTES must be a number between 5 and 10080';
        }

        if ($errors !== []) {
            throw new ConfigException(implode('; ', $errors));
        }
    }

    public function smtpSecure(): string
    {
        return strtolower($this->get('SMTP_SECURE', 'tls'));
    }

    public function smtpPort(): int
    {
        return $this->int('SMTP_PORT', $this->smtpSecure() === 'ssl' ? 465 : 587);
    }
}

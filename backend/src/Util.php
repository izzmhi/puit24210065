<?php

declare(strict_types=1);

namespace App;

final class Util
{
    public static function now(): int
    {
        return time();
    }

    /** All timestamps are stored as UTC DATETIME strings, so they sort and compare as text. */
    public static function fmt(int $timestamp): string
    {
        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    /** Converts a stored UTC DATETIME into an ISO-8601 string for the API. */
    public static function iso(?string $datetime): ?string
    {
        return $datetime === null ? null : str_replace(' ', 'T', $datetime) . 'Z';
    }

    /** "jane.doe@example.com" -> "j***@example.com" (shown on the verification screen). */
    public static function maskEmail(string $email): string
    {
        $at = strrpos($email, '@');
        if ($at === false || $at < 1) {
            return '***';
        }
        return substr($email, 0, 1) . '***' . substr($email, $at);
    }
}

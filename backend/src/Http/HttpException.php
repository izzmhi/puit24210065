<?php

declare(strict_types=1);

namespace App\Http;

/**
 * An expected failure that maps directly to an HTTP error response.
 * The message is shown to the API client, so it must never contain secrets.
 */
final class HttpException extends \RuntimeException
{
    /**
     * @param array<string,mixed>  $details Extra machine-readable data, e.g. per-field validation messages.
     * @param array<string,string> $headers Extra response headers, e.g. Retry-After.
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }
}

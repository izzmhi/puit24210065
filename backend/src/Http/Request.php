<?php

declare(strict_types=1);

namespace App\Http;

final class Request
{
    private const MAX_BODY_BYTES = 16384;

    /** @var array<string,string> lower-cased header name => value */
    private array $headers;

    /**
     * @param array<string,string> $headers
     * @param array<string,string> $cookies
     * @param array<string,mixed>  $query
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        array $headers = [],
        public readonly array $cookies = [],
        public readonly string $rawBody = '',
        public readonly string $ip = '127.0.0.1',
        public readonly array $query = [],
    ) {
        $this->headers = array_change_key_case($headers, CASE_LOWER);
    }

    public static function fromGlobals(bool $trustProxy = false): self
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (is_string($value) && str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
            }
        }
        if (!isset($headers['authorization']) && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headers['authorization'] = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        if ($trustProxy && isset($headers['x-forwarded-for'])) {
            // The right-most entry is the one appended by our own (trusted) proxy.
            $parts = array_map('trim', explode(',', $headers['x-forwarded-for']));
            $candidate = end($parts);
            if ($candidate !== false && filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                $ip = $candidate;
            }
        }

        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $body = (string) file_get_contents('php://input', false, null, 0, self::MAX_BODY_BYTES + 1);

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            $path,
            $headers,
            $_COOKIE,
            $body,
            $ip,
            $_GET,
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** The path below "/api", e.g. "/api/admin/users" -> "/admin/users". Works under a sub-folder too. */
    public function routePath(): string
    {
        if (preg_match('#/api(/.*)?$#', $this->path, $m) !== 1) {
            return '/';
        }
        $route = rtrim($m[1] ?? '/', '/');
        return $route === '' ? '/' : $route;
    }

    /** @return array<string,mixed> */
    public function json(): array
    {
        if ($this->rawBody === '') {
            return [];
        }
        if (strlen($this->rawBody) > self::MAX_BODY_BYTES) {
            throw new HttpException(413, 'payload_too_large', 'Request body is too large.');
        }
        $contentType = strtolower($this->header('content-type') ?? '');
        if (!str_starts_with($contentType, 'application/json')) {
            throw new HttpException(415, 'unsupported_media_type', 'Content-Type must be application/json.');
        }
        try {
            $data = json_decode($this->rawBody, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpException(400, 'invalid_json', 'Request body is not valid JSON.');
        }
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new HttpException(400, 'invalid_json', 'A JSON object was expected.');
        }
        return $data;
    }
}

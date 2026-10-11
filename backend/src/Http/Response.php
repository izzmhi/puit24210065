<?php

declare(strict_types=1);

namespace App\Http;

final class Response
{
    /** @var array<string,string> */
    public array $headers = [];

    /** @var list<array{name:string,value:string,options:array<string,mixed>}> */
    public array $cookies = [];

    /** @param array<string,mixed>|null $body */
    public function __construct(public int $status = 200, public ?array $body = null)
    {
    }

    /** @param array<string,mixed> $data */
    public static function json(array $data, int $status = 200): self
    {
        return new self($status, $data);
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    /** @param array<string,mixed> $options Options accepted by setcookie() */
    public function withCookie(string $name, string $value, array $options): self
    {
        $this->cookies[] = ['name' => $name, 'value' => $value, 'options' => $options];
        return $this;
    }

    public function emit(): void
    {
        http_response_code($this->status);
        header('Content-Type: application/json; charset=utf-8');
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value, true);
        }
        foreach ($this->cookies as $cookie) {
            setcookie($cookie['name'], $cookie['value'], $cookie['options']);
        }
        if ($this->status !== 204 && $this->body !== null) {
            echo json_encode($this->body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
    }
}

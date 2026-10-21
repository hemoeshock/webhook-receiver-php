<?php

declare(strict_types=1);

namespace App\Webhook;

/**
 * A tiny HTTP response object, so the receiver can be tested without a web
 * server in front of it.
 */
final class Response
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $body
     */
    public function __construct(
        public readonly int $status,
        public readonly array $body = [],
        public readonly array $headers = [],
    ) {
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    public static function json(int $status, array $body, array $headers = []): self
    {
        return new self($status, $body, $headers + ['Content-Type' => 'application/json']);
    }

    public function withHeader(string $name, string $value): self
    {
        return new self($this->status, $this->body, [$name => $value] + $this->headers);
    }

    public function body(): string
    {
        return (string) json_encode($this->body, JSON_UNESCAPED_SLASHES);
    }
}

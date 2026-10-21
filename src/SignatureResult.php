<?php

declare(strict_types=1);

namespace App\Webhook;

/**
 * Result of a signature check.
 */
final class SignatureResult
{
    private function __construct(
        public readonly bool $valid,
        public readonly string $reason,
    ) {
    }

    public static function ok(): self
    {
        return new self(true, 'ok');
    }

    public static function fail(string $reason): self
    {
        return new self(false, $reason);
    }
}

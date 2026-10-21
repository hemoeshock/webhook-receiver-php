<?php

declare(strict_types=1);

namespace App\Webhook;

/**
 * A clock that only moves when you tell it to. Useful in tests.
 */
final class FixedClock implements Clock
{
    public function __construct(private int $now)
    {
    }

    public function now(): int
    {
        return $this->now;
    }

    public function advance(int $seconds): void
    {
        $this->now += $seconds;
    }
}

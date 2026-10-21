<?php

declare(strict_types=1);

namespace App\Webhook;

/**
 * The real clock, used in production.
 */
final class SystemClock implements Clock
{
    public function now(): int
    {
        return time();
    }
}

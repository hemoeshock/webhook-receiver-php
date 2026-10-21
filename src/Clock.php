<?php

declare(strict_types=1);

namespace App\Webhook;

/**
 * A source of "now" in unix seconds.
 *
 * Everything that needs the current time takes a Clock, so the tests can move
 * time forward instead of sleeping.
 */
interface Clock
{
    public function now(): int;
}

<?php

declare(strict_types=1);

namespace App\Webhook;

/**
 * The outcome of trying to claim an event for processing.
 */
final class ClaimResult
{
    /** The event was seen for the first time; this worker owns it. */
    public const CLAIMED = 'claimed';

    /** The event was already processed successfully. Do nothing, return 2xx. */
    public const DUPLICATE = 'duplicate';

    /** The event failed permanently before. Do nothing, return 2xx. */
    public const DUPLICATE_FAILED = 'duplicate_failed';

    /** Another worker holds a live lease on it. Do nothing, return 2xx. */
    public const IN_FLIGHT = 'in_flight';

    /** A previous attempt failed with a retryable error; this worker takes over. */
    public const RETRIED = 'retried';

    /** A previous worker died mid-flight and its lease expired; this worker takes over. */
    public const TAKEN_OVER = 'taken_over';

    public function __construct(
        public readonly string $status,
        public readonly int $attempts = 0,
    ) {
    }

    /** True when this worker should run the handler. */
    public function shouldProcess(): bool
    {
        return in_array($this->status, [self::CLAIMED, self::RETRIED, self::TAKEN_OVER], true);
    }

    /** True when the event was already handled and nothing more should happen. */
    public function isDuplicate(): bool
    {
        return in_array($this->status, [self::DUPLICATE, self::DUPLICATE_FAILED, self::IN_FLIGHT], true);
    }
}

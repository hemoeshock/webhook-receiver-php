<?php

declare(strict_types=1);

namespace App\Webhook;

use RuntimeException;

/**
 * Thrown when an event can never succeed, no matter how many times it is
 * delivered: an unknown event type, a payload that is missing a field, a
 * reference to a customer that does not exist.
 *
 * A permanent failure must not be retried. Answering it with 5xx is how you
 * build a retry storm that takes down your own database.
 */
final class PermanentFailure extends RuntimeException
{
}

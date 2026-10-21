<?php

declare(strict_types=1);

namespace App\Webhook;

use JsonException;
use Throwable;

/**
 * The webhook endpoint, without the HTTP plumbing.
 *
 * The receiver is the piece that decides *what happens to an event*, and it
 * answers the same three questions every time:
 *
 *   1. Is this really from the provider?      -> verify the signature
 *   2. Have I already handled this event?     -> claim it in the event store
 *   3. Did the work succeed?                  -> 2xx, 4xx or 5xx
 *
 * The status code is the contract with the provider:
 *
 *   200  the event is done (or was already done). Stop retrying.
 *   400  the payload is broken. Stop retrying, a retry cannot fix it.
 *   401  the signature did not match. Stop retrying.
 *   422  the event is permanently unacceptable. Stop retrying.
 *   500  something broke on our side. Retry, and the retry must be safe.
 */
final class WebhookReceiver
{
    /**
     * @param callable(array<string, mixed>): void $handler
     */
    public function __construct(
        private readonly SignatureVerifier $verifier,
        private readonly EventStore $store,
        private readonly mixed $handler,
        private readonly int $leaseSeconds = 30,
    ) {
    }

    /**
     * @param array<string, mixed> $headers
     */
    public function handle(string $rawBody, array $headers): Response
    {
        // 1. Authenticate before you look at the payload. Everything after
        //    this line is attacker-controlled input.
        $verification = $this->verifier->verify($rawBody, $headers);

        if (!$verification->valid) {
            return Response::json(401, [
                'status' => 'rejected',
                'reason' => $verification->reason,
            ]);
        }

        // 2. Parse the raw body. We verified the exact bytes we received, so
        //    anything we decode here is authentic.
        try {
            $event = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return Response::json(400, [
                'status' => 'rejected',
                'reason' => 'invalid_json',
            ]);
        }

        if (!is_array($event) || array_is_list($event)) {
            return Response::json(400, [
                'status' => 'rejected',
                'reason' => 'invalid_payload',
            ]);
        }

        $eventId = isset($event['id']) && is_string($event['id']) ? trim($event['id']) : '';

        if ($eventId === '') {
            return Response::json(400, [
                'status' => 'rejected',
                'reason' => 'missing_event_id',
            ]);
        }

        $type = isset($event['type']) && is_string($event['type']) ? $event['type'] : 'unknown';

        // 3. Claim the event. This is the idempotency barrier.
        $claim = $this->store->claim($eventId, $type, $this->leaseSeconds);

        if ($claim->isDuplicate()) {
            // Already handled, already failed permanently, or being handled by
            // another worker right now. Either way: acknowledge, do nothing.
            return Response::json(200, [
                'status' => 'duplicate',
                'event_id' => $eventId,
                'state' => $claim->status,
            ]);
        }

        // 4. Do the work.
        try {
            ($this->handler)($event);
        } catch (PermanentFailure $e) {
            $this->store->markFailed($eventId, $e->getMessage(), true);

            return Response::json(422, [
                'status' => 'failed',
                'event_id' => $eventId,
                'reason' => 'permanent_failure',
            ]);
        } catch (Throwable $e) {
            // Transient: mark the event as retryable and tell the provider to
            // come back. The next delivery will re-claim it.
            $this->store->markFailed($eventId, $e->getMessage(), false);

            return Response::json(500, [
                'status' => 'failed',
                'event_id' => $eventId,
                'reason' => 'transient_failure',
            ]);
        }

        $this->store->markDone($eventId);

        return Response::json(200, [
            'status' => 'processed',
            'event_id' => $eventId,
            'attempts' => $claim->attempts,
        ]);
    }
}

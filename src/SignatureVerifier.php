<?php

declare(strict_types=1);

namespace App\Webhook;

/**
 * Verifies the signature a payment provider puts on every webhook.
 *
 * The provider signs the *raw* request body:
 *
 *     signature = "sha256=" . hash_hmac('sha256', "{timestamp}.{rawBody}", $secret)
 *
 * Three rules that this class exists to enforce:
 *
 *   1. Verify the raw bytes. Re-encoding the JSON before hashing changes the
 *      bytes (key order, whitespace, unicode escaping) and the signature will
 *      never match.
 *   2. Compare with hash_equals(), never with ==. A byte-by-byte comparison
 *      leaks the correct signature one character at a time.
 *   3. Reject stale timestamps. Without a time window, a signature captured
 *      from your logs can be replayed against your endpoint forever.
 */
final class SignatureVerifier
{
    public const HEADER_SIGNATURE = 'X-Webhook-Signature';
    public const HEADER_TIMESTAMP = 'X-Webhook-Timestamp';

    private const ALGORITHM = 'sha256';

    public function __construct(
        private readonly string $secret,
        private readonly int $toleranceSeconds = 300,
        private readonly Clock $clock = new SystemClock(),
    ) {
    }

    /**
     * Produce the signature for a body. The provider does the same thing on
     * its side; this is here so the tests (and the example script) can sign
     * payloads without duplicating the format.
     */
    public function sign(string $rawBody, int $timestamp): string
    {
        return self::ALGORITHM . '=' . hash_hmac(self::ALGORITHM, $timestamp . '.' . $rawBody, $this->secret);
    }

    /**
     * @param array<string, mixed> $headers
     */
    public function verify(string $rawBody, array $headers): SignatureResult
    {
        $provided = Headers::get($headers, self::HEADER_SIGNATURE);
        if ($provided === null || $provided === '') {
            return SignatureResult::fail('missing_signature');
        }

        $rawTimestamp = Headers::get($headers, self::HEADER_TIMESTAMP);
        if ($rawTimestamp === null || $rawTimestamp === '') {
            return SignatureResult::fail('missing_timestamp');
        }

        if (!preg_match('/^-?\d+$/', trim($rawTimestamp))) {
            return SignatureResult::fail('invalid_timestamp');
        }

        $timestamp = (int) trim($rawTimestamp);

        $skew = abs($this->clock->now() - $timestamp);
        if ($skew > $this->toleranceSeconds) {
            return SignatureResult::fail('timestamp_outside_tolerance');
        }

        if (!str_starts_with($provided, self::ALGORITHM . '=')) {
            // Refuse anything that is not the algorithm we negotiated. This
            // blocks "algorithm confusion" attacks, e.g. an attacker sending a
            // sha1= signature and hoping you compare it loosely.
            return SignatureResult::fail('unsupported_algorithm');
        }

        $expected = $this->sign($rawBody, $timestamp);

        if (!hash_equals($expected, $provided)) {
            return SignatureResult::fail('signature_mismatch');
        }

        return SignatureResult::ok();
    }
}

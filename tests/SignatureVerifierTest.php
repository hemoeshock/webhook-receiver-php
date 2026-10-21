<?php

declare(strict_types=1);

use App\Webhook\FixedClock;
use App\Webhook\Headers;
use App\Webhook\SignatureVerifier;

function signatureVerifierTests(): void
{
    echo "SignatureVerifier\n";

    $secret = 'whsec_test_secret';
    $clock = new FixedClock(1_700_000_000);
    $verifier = new SignatureVerifier($secret, 300, $clock);
    $body = '{"id":"evt_1","type":"order.paid"}';

    $headersFor = static function (string $body, int $timestamp) use ($verifier): array {
        return [
            'X-Webhook-Signature' => $verifier->sign($body, $timestamp),
            'X-Webhook-Timestamp' => (string) $timestamp,
        ];
    };

    Harness::test('accepts a correctly signed body', function () use ($verifier, $body, $headersFor, $clock) {
        $result = $verifier->verify($body, $headersFor($body, $clock->now()));

        Harness::assertTrue($result->valid);
        Harness::assertSame('ok', $result->reason);
    });

    Harness::test('rejects a body that was changed after signing', function () use ($verifier, $body, $headersFor, $clock) {
        $headers = $headersFor($body, $clock->now());
        $tampered = str_replace('order.paid', 'order.refunded', $body);

        $result = $verifier->verify($tampered, $headers);

        Harness::assertFalse($result->valid);
        Harness::assertSame('signature_mismatch', $result->reason);
    });

    Harness::test('rejects a signature made with another secret', function () use ($body, $clock, $secret) {
        $attacker = new SignatureVerifier('whsec_other_secret', 300, $clock);
        $headers = [
            'X-Webhook-Signature' => $attacker->sign($body, $clock->now()),
            'X-Webhook-Timestamp' => (string) $clock->now(),
        ];

        $result = (new SignatureVerifier($secret, 300, $clock))->verify($body, $headers);

        Harness::assertFalse($result->valid);
        Harness::assertSame('signature_mismatch', $result->reason);
    });

    Harness::test('rejects a request without a signature header', function () use ($verifier, $body, $clock) {
        $result = $verifier->verify($body, ['X-Webhook-Timestamp' => (string) $clock->now()]);

        Harness::assertFalse($result->valid);
        Harness::assertSame('missing_signature', $result->reason);
    });

    Harness::test('rejects a request without a timestamp header', function () use ($verifier, $body, $clock) {
        $headers = ['X-Webhook-Signature' => $verifier->sign($body, $clock->now())];

        $result = $verifier->verify($body, $headers);

        Harness::assertFalse($result->valid);
        Harness::assertSame('missing_timestamp', $result->reason);
    });

    Harness::test('rejects a non-numeric timestamp', function () use ($verifier, $body) {
        $headers = [
            'X-Webhook-Signature' => $verifier->sign($body, 1_700_000_000),
            'X-Webhook-Timestamp' => 'not-a-number',
        ];

        $result = $verifier->verify($body, $headers);

        Harness::assertFalse($result->valid);
        Harness::assertSame('invalid_timestamp', $result->reason);
    });

    Harness::test('rejects a timestamp that is too old', function () use ($verifier, $body, $clock) {
        $old = $clock->now() - 301;
        $headers = [
            'X-Webhook-Signature' => $verifier->sign($body, $old),
            'X-Webhook-Timestamp' => (string) $old,
        ];

        $result = $verifier->verify($body, $headers);

        Harness::assertFalse($result->valid);
        Harness::assertSame('timestamp_outside_tolerance', $result->reason);
    });

    Harness::test('rejects a timestamp from the future', function () use ($verifier, $body, $clock) {
        $future = $clock->now() + 3600;
        $headers = [
            'X-Webhook-Signature' => $verifier->sign($body, $future),
            'X-Webhook-Timestamp' => (string) $future,
        ];

        $result = $verifier->verify($body, $headers);

        Harness::assertFalse($result->valid);
        Harness::assertSame('timestamp_outside_tolerance', $result->reason);
    });

    Harness::test('accepts a timestamp exactly on the tolerance edge', function () use ($verifier, $body, $clock) {
        $edge = $clock->now() - 300;
        $headers = [
            'X-Webhook-Signature' => $verifier->sign($body, $edge),
            'X-Webhook-Timestamp' => (string) $edge,
        ];

        $result = $verifier->verify($body, $headers);

        Harness::assertTrue($result->valid, '300 seconds old is still inside the window');
    });

    Harness::test('rejects a signature that uses another algorithm', function () use ($body, $clock) {
        $headers = [
            'X-Webhook-Signature' => 'sha1=' . hash_hmac('sha1', $clock->now() . '.' . $body, 'whsec_test_secret'),
            'X-Webhook-Timestamp' => (string) $clock->now(),
        ];

        $result = (new SignatureVerifier('whsec_test_secret', 300, $clock))->verify($body, $headers);

        Harness::assertFalse($result->valid);
        Harness::assertSame('unsupported_algorithm', $result->reason);
    });

    Harness::test('looks headers up without caring about case', function () use ($verifier, $body, $clock) {
        $headers = [
            'x-webhook-signature' => $verifier->sign($body, $clock->now()),
            'X-WEBHOOK-TIMESTAMP' => (string) $clock->now(),
        ];

        $result = $verifier->verify($body, $headers);

        Harness::assertTrue($result->valid);
    });

    Harness::test('handles unicode and quotes in the body', function () use ($verifier, $clock) {
        $body = json_encode([
            'id' => 'evt_unicode',
            'type' => 'order.paid',
            'data' => ['customer' => 'Ünïcödé "quoted" \\ backslash'],
        ], JSON_UNESCAPED_UNICODE);

        $result = $verifier->verify($body, [
            'X-Webhook-Signature' => $verifier->sign($body, $clock->now()),
            'X-Webhook-Timestamp' => (string) $clock->now(),
        ]);

        Harness::assertTrue($result->valid);
    });

    echo "\n";
}

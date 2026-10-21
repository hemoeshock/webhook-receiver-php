<?php

declare(strict_types=1);

use App\Webhook\EventStore;
use App\Webhook\FixedClock;
use App\Webhook\HttpKernel;
use App\Webhook\OrderHandler;
use App\Webhook\SignatureVerifier;
use App\Webhook\WebhookReceiver;

function httpKernelTests(): void
{
    echo "HttpKernel\n";

    $secret = 'whsec_test_secret';

    $build = static function () use ($secret): HttpKernel {
        $db = testDatabase();
        $clock = new FixedClock(1_700_000_000);
        $store = new EventStore($db, $clock);
        $store->migrate();

        $handler = new OrderHandler($db, $clock);
        $handler->migrate();
        $handler->createOrder('ord_1');

        $receiver = new WebhookReceiver(
            new SignatureVerifier($secret, 300, $clock),
            $store,
            $handler,
            30
        );

        return new HttpKernel($receiver, '/webhooks/orders');
    };

    $body = (string) json_encode([
        'id' => 'evt_1',
        'type' => 'order.paid',
        'data' => ['order_id' => 'ord_1', 'version' => 1, 'amount_cents' => 2500],
    ]);

    $timestamp = 1_700_000_000;
    $signature = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);

    Harness::test('a POST to the webhook route is handled', function () use ($build, $body, $signature, $timestamp) {
        $kernel = $build();

        $response = $kernel->handle(
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/webhooks/orders'],
            $body,
            ['X-Webhook-Signature' => $signature, 'X-Webhook-Timestamp' => (string) $timestamp]
        );

        Harness::assertSame(200, $response->status);
        Harness::assertSame('processed', $response->body['status']);
    });

    Harness::test('a GET to the webhook route is refused', function () use ($build) {
        $kernel = $build();

        $response = $kernel->handle(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/webhooks/orders'], '');

        Harness::assertSame(405, $response->status);
        Harness::assertSame('POST', $response->headers['Allow']);
    });

    Harness::test('an unknown path is a 404', function () use ($build) {
        $kernel = $build();

        $response = $kernel->handle(['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/nope'], '');

        Harness::assertSame(404, $response->status);
    });

    Harness::test('headers are read from the server array when none are passed', function () use ($build, $body, $signature, $timestamp) {
        $kernel = $build();

        $response = $kernel->handle(
            [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/webhooks/orders',
                'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
                'HTTP_X_WEBHOOK_TIMESTAMP' => (string) $timestamp,
            ],
            $body
        );

        Harness::assertSame(200, $response->status);
        Harness::assertSame('processed', $response->body['status']);
    });

    Harness::test('a forged signature is refused at the HTTP layer too', function () use ($build, $body, $timestamp) {
        $kernel = $build();

        $response = $kernel->handle(
            [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/webhooks/orders',
                'HTTP_X_WEBHOOK_SIGNATURE' => 'sha256=' . str_repeat('a', 64),
                'HTTP_X_WEBHOOK_TIMESTAMP' => (string) $timestamp,
            ],
            $body
        );

        Harness::assertSame(401, $response->status);
    });

    Harness::test('a query string does not break the route match', function () use ($build, $body, $signature, $timestamp) {
        $kernel = $build();

        $response = $kernel->handle(
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/webhooks/orders?source=provider'],
            $body,
            ['X-Webhook-Signature' => $signature, 'X-Webhook-Timestamp' => (string) $timestamp]
        );

        Harness::assertSame(200, $response->status);
    });

    echo "\n";
}

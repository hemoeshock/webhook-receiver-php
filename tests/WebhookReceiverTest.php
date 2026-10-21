<?php

declare(strict_types=1);

use App\Webhook\EventStore;
use App\Webhook\FixedClock;
use App\Webhook\OrderHandler;
use App\Webhook\SignatureVerifier;
use App\Webhook\WebhookReceiver;

function webhookReceiverTests(): void
{
    echo "WebhookReceiver\n";

    $secret = 'whsec_test_secret';

    /**
     * @return array{0: PDO, 1: FixedClock, 2: WebhookReceiver, 3: EventStore, 4: OrderHandler, 5: callable}
     */
    $build = static function () use ($secret): array {
        $db = testDatabase();
        $clock = new FixedClock(1_700_000_000);
        $store = new EventStore($db, $clock);
        $store->migrate();

        $handler = new OrderHandler($db, $clock);
        $handler->migrate();
        $handler->createOrder('ord_1');

        $calls = 0;
        $receiver = new WebhookReceiver(
            new SignatureVerifier($secret, 300, $clock),
            $store,
            static function (array $event) use (&$calls, $handler): void {
                $calls++;
                $handler($event);
            },
            30
        );

        return [$db, $clock, $receiver, $store, $handler, static function () use (&$calls): int {
            return $calls;
        }];
    };

    $signedHeaders = static function (string $body, int $timestamp) use ($secret): array {
        return [
            'X-Webhook-Signature' => 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret),
            'X-Webhook-Timestamp' => (string) $timestamp,
        ];
    };

    $event = static function (string $id, string $type = 'order.paid', array $data = []): string {
        return (string) json_encode([
            'id' => $id,
            'type' => $type,
            'data' => $data + ['order_id' => 'ord_1', 'version' => 1, 'amount_cents' => 2500],
        ]);
    };

    Harness::test('a valid event is processed', function () use ($build, $signedHeaders, $event) {
        [, $clock, $receiver, , , $calls] = $build();
        $body = $event('evt_1');

        $response = $receiver->handle($body, $signedHeaders($body, $clock->now()));

        Harness::assertSame(200, $response->status);
        Harness::assertSame('processed', $response->body['status']);
        Harness::assertSame(1, $calls());
    });

    Harness::test('the same event twice runs the handler once', function () use ($build, $signedHeaders, $event) {
        [, $clock, $receiver, , , $calls] = $build();
        $body = $event('evt_1');

        $receiver->handle($body, $signedHeaders($body, $clock->now()));
        $second = $receiver->handle($body, $signedHeaders($body, $clock->now()));

        Harness::assertSame(200, $second->status);
        Harness::assertSame('duplicate', $second->body['status']);
        Harness::assertSame(1, $calls(), 'the handler must not run twice');
    });

    Harness::test('a duplicate delivery does not create a second payment', function () use ($build, $signedHeaders, $event) {
        [$db, $clock, $receiver, , , ] = $build();
        $body = $event('evt_1');

        $receiver->handle($body, $signedHeaders($body, $clock->now()));
        $receiver->handle($body, $signedHeaders($body, $clock->now()));

        $payments = $db->query('SELECT COUNT(*) FROM payments')->fetchColumn();

        Harness::assertSame(1, (int) $payments);
    });

    Harness::test('a bad signature is rejected and nothing is stored', function () use ($build, $signedHeaders, $event) {
        [, $clock, $receiver, $store, , $calls] = $build();
        $body = $event('evt_1');

        $response = $receiver->handle($body, [
            'X-Webhook-Signature' => 'sha256=' . str_repeat('0', 64),
            'X-Webhook-Timestamp' => (string) $clock->now(),
        ]);

        Harness::assertSame(401, $response->status);
        Harness::assertSame('signature_mismatch', $response->body['reason']);
        Harness::assertSame(null, $store->find('evt_1'));
        Harness::assertSame(0, $calls());
    });

    Harness::test('a body that is not JSON is rejected', function () use ($build, $signedHeaders) {
        [, $clock, $receiver, , , $calls] = $build();
        $body = 'this is not json';

        $response = $receiver->handle($body, $signedHeaders($body, $clock->now()));

        Harness::assertSame(400, $response->status);
        Harness::assertSame('invalid_json', $response->body['reason']);
        Harness::assertSame(0, $calls());
    });

    Harness::test('a payload without an event id is rejected', function () use ($build, $signedHeaders) {
        [, $clock, $receiver, , , $calls] = $build();
        $body = '{"type":"order.paid"}';

        $response = $receiver->handle($body, $signedHeaders($body, $clock->now()));

        Harness::assertSame(400, $response->status);
        Harness::assertSame('missing_event_id', $response->body['reason']);
        Harness::assertSame(0, $calls());
    });

    Harness::test('an unknown event type fails permanently', function () use ($build, $signedHeaders, $event) {
        [, $clock, $receiver, $store, , $calls] = $build();
        $body = $event('evt_1', 'invoice.sent');

        $response = $receiver->handle($body, $signedHeaders($body, $clock->now()));

        Harness::assertSame(422, $response->status);
        Harness::assertSame('permanent_failure', $response->body['reason']);
        Harness::assertSame(EventStore::STATUS_FAILED_PERMANENT, $store->find('evt_1')['status']);
        Harness::assertSame(1, $calls());
    });

    Harness::test('a permanently failed event is not processed again', function () use ($build, $signedHeaders, $event) {
        [, $clock, $receiver, , , $calls] = $build();
        $body = $event('evt_1', 'invoice.sent');

        $receiver->handle($body, $signedHeaders($body, $clock->now()));
        $second = $receiver->handle($body, $signedHeaders($body, $clock->now()));

        Harness::assertSame(200, $second->status);
        Harness::assertSame('duplicate', $second->body['status']);
        Harness::assertSame('duplicate_failed', $second->body['state']);
        Harness::assertSame(1, $calls(), 'a permanent failure must not be retried');
    });

    Harness::test('a transient failure asks the provider to retry', function () use ($build, $signedHeaders, $event) {
        [, $clock, $receiver, $store, , ] = $build();
        $body = $event('evt_1', 'order.paid', ['simulate_failure' => 'transient']);

        $response = $receiver->handle($body, $signedHeaders($body, $clock->now()));

        Harness::assertSame(500, $response->status);
        Harness::assertSame('transient_failure', $response->body['reason']);
        Harness::assertSame(EventStore::STATUS_FAILED, $store->find('evt_1')['status']);
    });

    Harness::test('the retry after a transient failure is processed', function () use ($build, $signedHeaders, $event) {
        [, $clock, $receiver, , , $calls] = $build();
        $body = $event('evt_1', 'order.paid', ['simulate_failure' => 'transient']);

        $receiver->handle($body, $signedHeaders($body, $clock->now()));
        $retry = $receiver->handle($body, $signedHeaders($body, $clock->now()));

        Harness::assertSame(500, $retry->status, 'the same broken payload still fails');
        Harness::assertSame(2, $calls());
    });

    Harness::test('a retry after a transient failure succeeds and applies the payment once', function () use ($build, $signedHeaders, $event) {
        [$db, $clock, $receiver, , , $calls] = $build();

        $broken = $event('evt_1', 'order.paid', ['simulate_failure' => 'transient']);
        $receiver->handle($broken, $signedHeaders($broken, $clock->now()));

        // The provider redelivers the same event id with a payload that works.
        $good = $event('evt_1');
        $response = $receiver->handle($good, $signedHeaders($good, $clock->now()));

        Harness::assertSame(200, $response->status);
        Harness::assertSame('processed', $response->body['status']);

        $payments = $db->query('SELECT COUNT(*) FROM payments')->fetchColumn();
        Harness::assertSame(1, (int) $payments);
        Harness::assertSame(2, $calls(), 'the handler ran twice: once failing, once succeeding');
    });

    Harness::test('an event that is still running is reported as in flight', function () use ($build, $signedHeaders, $event) {
        [, $clock, $receiver, $store, , ] = $build();
        $body = $event('evt_1');

        // Another worker picked the event up first and is still busy with it.
        $store->claim('evt_1', 'order.paid', 30);

        $response = $receiver->handle($body, $signedHeaders($body, $clock->now()));

        Harness::assertSame(200, $response->status);
        Harness::assertSame('duplicate', $response->body['status']);
        Harness::assertSame('in_flight', $response->body['state']);
    });

    Harness::test('a late event with an old version does not overwrite a newer one', function () use ($build, $signedHeaders, $event) {
        [$db, $clock, $receiver, , , ] = $build();

        $newer = $event('evt_2', 'order.paid', ['version' => 5]);
        $receiver->handle($newer, $signedHeaders($newer, $clock->now()));

        $older = $event('evt_3', 'order.paid', ['version' => 2]);
        $receiver->handle($older, $signedHeaders($older, $clock->now()));

        $version = $db->query('SELECT version FROM orders WHERE id = \'ord_1\'')->fetchColumn();

        Harness::assertSame(5, (int) $version, 'the newest version wins');
    });

    echo "\n";
}

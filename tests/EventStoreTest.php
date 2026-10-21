<?php

declare(strict_types=1);

use App\Webhook\ClaimResult;
use App\Webhook\EventStore;
use App\Webhook\FixedClock;

function eventStoreTests(): void
{
    echo "EventStore\n";

    $build = static function (): array {
        $db = testDatabase();
        $clock = new FixedClock(1_700_000_000);
        $store = new EventStore($db, $clock);
        $store->migrate();

        return [$db, $clock, $store];
    };

    Harness::test('the first delivery claims the event', function () use ($build) {
        [, , $store] = $build();

        $claim = $store->claim('evt_1', 'order.paid', 30);

        Harness::assertSame(ClaimResult::CLAIMED, $claim->status);
        Harness::assertTrue($claim->shouldProcess());
        Harness::assertSame(1, $claim->attempts);
    });

    Harness::test('a second delivery while the first is running is not a duplicate to process', function () use ($build) {
        [, , $store] = $build();

        $store->claim('evt_1', 'order.paid', 30);
        $second = $store->claim('evt_1', 'order.paid', 30);

        Harness::assertSame(ClaimResult::IN_FLIGHT, $second->status);
        Harness::assertTrue($second->isDuplicate());
        Harness::assertFalse($second->shouldProcess());
    });

    Harness::test('the event id is stored exactly once', function () use ($build) {
        [$db, , $store] = $build();

        $store->claim('evt_1', 'order.paid', 30);
        $store->claim('evt_1', 'order.paid', 30);
        $store->markDone('evt_1');

        $count = $db->query('SELECT COUNT(*) FROM webhook_events WHERE id = \'evt_1\'')->fetchColumn();

        Harness::assertSame(1, (int) $count);
    });

    Harness::test('a finished event is reported as a duplicate', function () use ($build) {
        [, , $store] = $build();

        $store->claim('evt_1', 'order.paid', 30);
        $store->markDone('evt_1');

        $claim = $store->claim('evt_1', 'order.paid', 30);

        Harness::assertSame(ClaimResult::DUPLICATE, $claim->status);
        Harness::assertFalse($claim->shouldProcess());
    });

    Harness::test('a permanently failed event is not retried', function () use ($build) {
        [, , $store] = $build();

        $store->claim('evt_1', 'order.paid', 30);
        $store->markFailed('evt_1', 'unknown event type', true);

        $claim = $store->claim('evt_1', 'order.paid', 30);

        Harness::assertSame(ClaimResult::DUPLICATE_FAILED, $claim->status);
        Harness::assertFalse($claim->shouldProcess());
    });

    Harness::test('a transiently failed event can be claimed again', function () use ($build) {
        [, , $store] = $build();

        $store->claim('evt_1', 'order.paid', 30);
        $store->markFailed('evt_1', 'database is down', false);

        $claim = $store->claim('evt_1', 'order.paid', 30);

        Harness::assertSame(ClaimResult::RETRIED, $claim->status);
        Harness::assertTrue($claim->shouldProcess());
        Harness::assertSame(2, $claim->attempts);
    });

    Harness::test('an expired lease can be taken over', function () use ($build) {
        [, , $store] = $build();

        // A lease of zero seconds is already expired when the next worker looks.
        $store->claim('evt_1', 'order.paid', 0);
        $claim = $store->claim('evt_1', 'order.paid', 30);

        Harness::assertSame(ClaimResult::TAKEN_OVER, $claim->status);
        Harness::assertTrue($claim->shouldProcess());
    });

    Harness::test('the sweeper frees events whose worker died', function () use ($build) {
        [, , $store] = $build();

        $store->claim('evt_1', 'order.paid', 0);
        $recovered = $store->recoverExpiredLeases();

        Harness::assertSame(['evt_1'], $recovered);
        Harness::assertSame(EventStore::STATUS_FAILED, $store->find('evt_1')['status']);
    });

    Harness::test('a recovered event can be claimed again', function () use ($build) {
        [, , $store] = $build();

        $store->claim('evt_1', 'order.paid', 0);
        $store->recoverExpiredLeases();

        $claim = $store->claim('evt_1', 'order.paid', 30);

        Harness::assertSame(ClaimResult::RETRIED, $claim->status);
        Harness::assertTrue($claim->shouldProcess());
    });

    Harness::test('the sweeper leaves live events alone', function () use ($build) {
        [, , $store] = $build();

        $store->claim('evt_1', 'order.paid', 30);

        Harness::assertSame([], $store->recoverExpiredLeases());
        Harness::assertSame(EventStore::STATUS_PROCESSING, $store->find('evt_1')['status']);
    });

    Harness::test('pruning removes old finished events only', function () use ($build) {
        [$db, $clock, $store] = $build();

        $store->claim('old_done', 'order.paid', 30);
        $store->markDone('old_done');
        $store->claim('old_failed', 'order.paid', 30);
        $store->markFailed('old_failed', 'nope', true);
        $store->claim('recent_done', 'order.paid', 30);
        $store->markDone('recent_done');

        // Pretend the two old events are a week old.
        $db->exec('UPDATE webhook_events SET received_at = ' . ($clock->now() - 604800) . ' WHERE id IN (\'old_done\', \'old_failed\')');

        $deleted = $store->prune($clock->now() - 86400);

        Harness::assertSame(2, $deleted);
        Harness::assertSame(1, count($store->all()));
        Harness::assertSame('recent_done', $store->all()[0]['id']);
    });

    Harness::test('an unknown event id is not found', function () use ($build) {
        [, , $store] = $build();

        Harness::assertSame(null, $store->find('evt_missing'));
    });

    echo "\n";
}

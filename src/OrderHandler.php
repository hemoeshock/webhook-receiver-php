<?php

declare(strict_types=1);

namespace App\Webhook;

use PDO;
use RuntimeException;

/**
 * An example application handler for "order.paid" events.
 *
 * Two rules make it safe to run twice:
 *
 *   1. The payment row is keyed by the event id, so a second run cannot
 *      insert a second payment.
 *   2. The order update is guarded by a version number, so an event that
 *      arrives late (out of order) cannot overwrite a newer state.
 *
 * The "simulate_failure" field is a demo/testing hook. Delete it before you
 * copy this into production.
 */
final class OrderHandler
{
    public function __construct(
        private readonly PDO $db,
        private readonly Clock $clock = new SystemClock(),
    ) {
    }

    public function migrate(): void
    {
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS orders (
                id          TEXT    PRIMARY KEY,
                status      TEXT    NOT NULL DEFAULT \'pending\',
                version     INTEGER NOT NULL DEFAULT 0,
                paid_at     INTEGER
            )'
        );

        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS payments (
                event_id    TEXT    PRIMARY KEY,
                order_id    TEXT    NOT NULL,
                amount_cents INTEGER NOT NULL,
                created_at  INTEGER NOT NULL
            )'
        );
    }

    /**
     * @param array<string, mixed> $event
     */
    public function __invoke(array $event): void
    {
        $type = isset($event['type']) && is_string($event['type']) ? $event['type'] : '';
        $data = isset($event['data']) && is_array($event['data']) ? $event['data'] : [];

        if (isset($data['simulate_failure']) && is_string($data['simulate_failure'])) {
            if ($data['simulate_failure'] === 'permanent') {
                throw new PermanentFailure('simulated permanent failure');
            }

            throw new RuntimeException('simulated transient failure');
        }

        if ($type !== 'order.paid') {
            throw new PermanentFailure('unsupported event type: ' . $type);
        }

        $orderId = isset($data['order_id']) && is_string($data['order_id']) ? $data['order_id'] : '';

        if ($orderId === '') {
            throw new PermanentFailure('order_id missing');
        }

        $version = isset($data['version']) ? (int) $data['version'] : 0;
        $amount = isset($data['amount_cents']) ? (int) $data['amount_cents'] : 0;

        $this->applyPayment((string) $event['id'], $orderId, $version, $amount);
    }

    public function applyPayment(string $eventId, string $orderId, int $version, int $amount): void
    {
        $now = $this->clock->now();

        // The payment is keyed by the event id: running the handler twice
        // inserts one row, not two.
        $insert = $this->db->prepare(
            'INSERT OR IGNORE INTO payments (event_id, order_id, amount_cents, created_at)
             VALUES (:event_id, :order_id, :amount, :now)'
        );
        $insert->execute([
            ':event_id' => $eventId,
            ':order_id' => $orderId,
            ':amount' => $amount,
            ':now' => $now,
        ]);

        // The version guard makes the order update order-insensitive: an
        // event with an old version is simply ignored.
        $update = $this->db->prepare(
            'UPDATE orders
                SET status = \'paid\', paid_at = :now, version = :version
              WHERE id = :id AND version < :version'
        );
        $update->execute([
            ':now' => $now,
            ':version' => $version,
            ':id' => $orderId,
        ]);
    }

    /**
     * Helper for the demo and the tests.
     */
    public function createOrder(string $orderId, string $status = 'pending', int $version = 0): void
    {
        $statement = $this->db->prepare(
            'INSERT OR REPLACE INTO orders (id, status, version) VALUES (:id, :status, :version)'
        );
        $statement->execute([
            ':id' => $orderId,
            ':status' => $status,
            ':version' => $version,
        ]);
    }
}

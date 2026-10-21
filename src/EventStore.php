<?php

declare(strict_types=1);

namespace App\Webhook;

use PDO;
use Throwable;

/**
 * Durable record of every webhook event we have seen.
 *
 * The whole point of this class is that "have I seen this event?" is answered
 * by the *database*, not by a `SELECT` followed by an `INSERT`. Two requests
 * for the same event can arrive at the same time on two workers; only a
 * UNIQUE constraint can decide the winner atomically.
 *
 * State machine
 * -------------
 *
 *   (new) --claim--> processing --ok--------> done
 *                      |  |
 *                      |  +--transient error--> failed --claim--> processing
 *                      |
 *                      +----permanent error--> failed_permanent
 *
 *   done and failed_permanent are terminal: a later delivery of the same event
 *   is acknowledged and ignored.
 *
 *   failed is *not* terminal: the provider will retry, and the retry must be
 *   able to run the handler again. Treating "failed" like "done" is the single
 *   most common way to lose an event.
 *
 *   processing holds a lease (lease_until). If the worker dies, the lease
 *   expires and another delivery (or the sweeper) can take the event over.
 */
final class EventStore
{
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';
    public const STATUS_FAILED_PERMANENT = 'failed_permanent';

    public function __construct(
        private readonly PDO $db,
        private readonly Clock $clock = new SystemClock(),
    ) {
    }

    /**
     * Create the tables. Safe to call on every boot.
     */
    public function migrate(): void
    {
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS webhook_events (
                id           TEXT    PRIMARY KEY,
                type         TEXT    NOT NULL,
                status       TEXT    NOT NULL,
                attempts     INTEGER NOT NULL DEFAULT 0,
                lease_until  INTEGER,
                last_error   TEXT,
                received_at  INTEGER NOT NULL,
                updated_at   INTEGER NOT NULL
            )'
        );

        $this->db->exec(
            'CREATE INDEX IF NOT EXISTS idx_webhook_events_status_lease
             ON webhook_events (status, lease_until)'
        );
    }

    /**
     * Try to take ownership of an event.
     *
     * Returns a ClaimResult; the caller should only run the handler when
     * $result->shouldProcess() is true.
     */
    public function claim(string $eventId, string $type, int $leaseSeconds = 30): ClaimResult
    {
        $now = $this->clock->now();
        $lease = $now + $leaseSeconds;

        // BEGIN IMMEDIATE takes the write lock straight away, so two workers
        // racing on the same event cannot both read "not there yet".
        $this->db->exec('BEGIN IMMEDIATE');

        try {
            $insert = $this->db->prepare(
                'INSERT OR IGNORE INTO webhook_events
                    (id, type, status, attempts, lease_until, received_at, updated_at)
                 VALUES (:id, :type, :status, 1, :lease, :now, :now)'
            );
            $insert->execute([
                ':id' => $eventId,
                ':type' => $type,
                ':status' => self::STATUS_PROCESSING,
                ':lease' => $lease,
                ':now' => $now,
            ]);

            if ($insert->rowCount() === 1) {
                $this->db->exec('COMMIT');

                return new ClaimResult(ClaimResult::CLAIMED, 1);
            }

            $existing = $this->find($eventId);

            if ($existing === null) {
                // The row vanished between the INSERT and the SELECT. Treat it
                // as "somebody else owns it" rather than risking a double run.
                $this->db->exec('COMMIT');

                return new ClaimResult(ClaimResult::IN_FLIGHT);
            }

            $status = (string) $existing['status'];
            $attempts = (int) $existing['attempts'];

            if ($status === self::STATUS_DONE) {
                $this->db->exec('COMMIT');

                return new ClaimResult(ClaimResult::DUPLICATE, $attempts);
            }

            if ($status === self::STATUS_FAILED_PERMANENT) {
                $this->db->exec('COMMIT');

                return new ClaimResult(ClaimResult::DUPLICATE_FAILED, $attempts);
            }

            if ($status === self::STATUS_PROCESSING) {
                $leaseUntil = (int) ($existing['lease_until'] ?? 0);

                if ($leaseUntil > $now) {
                    // Someone else is working on it right now.
                    $this->db->exec('COMMIT');

                    return new ClaimResult(ClaimResult::IN_FLIGHT, $attempts);
                }

                // The lease expired: the previous worker died. Take over.
                $takeOver = $this->db->prepare(
                    'UPDATE webhook_events
                        SET attempts = attempts + 1, lease_until = :lease, updated_at = :now
                      WHERE id = :id AND status = :status AND lease_until <= :now'
                );
                $takeOver->execute([
                    ':lease' => $lease,
                    ':now' => $now,
                    ':id' => $eventId,
                    ':status' => self::STATUS_PROCESSING,
                ]);
                $taken = $takeOver->rowCount() === 1;

                $this->db->exec('COMMIT');

                return new ClaimResult(
                    $taken ? ClaimResult::TAKEN_OVER : ClaimResult::IN_FLIGHT,
                    $attempts + 1
                );
            }

            if ($status === self::STATUS_FAILED) {
                // The provider retried after a transient failure: take it again.
                $retry = $this->db->prepare(
                    'UPDATE webhook_events
                        SET status = :processing, attempts = attempts + 1,
                            lease_until = :lease, updated_at = :now, last_error = NULL
                      WHERE id = :id AND status = :failed'
                );
                $retry->execute([
                    ':processing' => self::STATUS_PROCESSING,
                    ':lease' => $lease,
                    ':now' => $now,
                    ':id' => $eventId,
                    ':failed' => self::STATUS_FAILED,
                ]);
                $retried = $retry->rowCount() === 1;

                $this->db->exec('COMMIT');

                return new ClaimResult(
                    $retried ? ClaimResult::RETRIED : ClaimResult::DUPLICATE,
                    $attempts + 1
                );
            }

            $this->db->exec('COMMIT');

            return new ClaimResult(ClaimResult::DUPLICATE, $attempts);
        } catch (Throwable $e) {
            try {
                $this->db->exec('ROLLBACK');
            } catch (Throwable) {
                // The transaction was already rolled back by SQLite.
            }

            throw $e;
        }
    }

    public function markDone(string $eventId): void
    {
        $statement = $this->db->prepare(
            'UPDATE webhook_events
                SET status = :done, lease_until = NULL, updated_at = :now, last_error = NULL
              WHERE id = :id'
        );
        $statement->execute([
            ':done' => self::STATUS_DONE,
            ':now' => $this->clock->now(),
            ':id' => $eventId,
        ]);
    }

    public function markFailed(string $eventId, string $error, bool $permanent = false): void
    {
        $statement = $this->db->prepare(
            'UPDATE webhook_events
                SET status = :status, lease_until = NULL, updated_at = :now, last_error = :error
              WHERE id = :id'
        );
        $statement->execute([
            ':status' => $permanent ? self::STATUS_FAILED_PERMANENT : self::STATUS_FAILED,
            ':now' => $this->clock->now(),
            ':error' => substr($error, 0, 500),
            ':id' => $eventId,
        ]);
    }

    /**
     * Free events whose worker died mid-flight.
     *
     * Call this from a cron job every minute. Each returned id should be put
     * back on your queue, because the provider already got a 2xx for it and
     * will not deliver it again.
     *
     * @return list<string>
     */
    public function recoverExpiredLeases(): array
    {
        $now = $this->clock->now();

        $expired = $this->db->query(
            'SELECT id FROM webhook_events WHERE status = ' . $this->db->quote(self::STATUS_PROCESSING)
            . ' AND lease_until <= ' . $now
        )->fetchAll(PDO::FETCH_COLUMN);

        if ($expired === []) {
            return [];
        }

        $statement = $this->db->prepare(
            'UPDATE webhook_events
                SET status = :failed, lease_until = NULL, updated_at = :now, last_error = :error
              WHERE id = :id AND status = :processing'
        );

        foreach ($expired as $id) {
            $statement->execute([
                ':failed' => self::STATUS_FAILED,
                ':now' => $now,
                ':error' => 'lease expired, worker did not finish',
                ':id' => (string) $id,
                ':processing' => self::STATUS_PROCESSING,
            ]);
        }

        return array_map('strval', $expired);
    }

    /**
     * Delete terminal events older than $olderThan (unix seconds).
     */
    public function prune(int $olderThan): int
    {
        $statement = $this->db->prepare(
            'DELETE FROM webhook_events
              WHERE status IN (:done, :permanent) AND received_at < :before'
        );
        $statement->execute([
            ':done' => self::STATUS_DONE,
            ':permanent' => self::STATUS_FAILED_PERMANENT,
            ':before' => $olderThan,
        ]);

        return $statement->rowCount();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $eventId): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM webhook_events WHERE id = :id');
        $statement->execute([':id' => $eventId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->db->query('SELECT * FROM webhook_events ORDER BY received_at, id')->fetchAll(PDO::FETCH_ASSOC);
    }
}

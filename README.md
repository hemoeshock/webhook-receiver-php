# Idempotent webhook receiver (PHP)

A webhook endpoint that survives duplicate deliveries, retries and half-finished
work. No framework, no Composer install: PHP 8.1+ and the `pdo_sqlite`
extension are all you need.

The full tutorial that explains the design is in `../draft.md`.

## Requirements

- PHP 8.1 or newer (the code uses `readonly` properties and enums-free
  constructor promotion)
- The `pdo_sqlite` extension (`php -m | grep sqlite`)
- Optional: `curl` and `sqlite3` on the command line for the demo

## Run the tests

```
php tests/run.php
```

Exit code 0 means every test passed. The harness in `tests/harness.php` is
plain PHP - there is no PHPUnit to install.

## Run the receiver

```
export WEBHOOK_SECRET='whsec_dev_secret'
php -S 127.0.0.1:8000 -t public
```

The database is created on first request at `var/webhooks.sqlite`.

## Send a demo event

In a second terminal:

```
php examples/send_webhook.php                 # one delivery
php examples/send_webhook.php --repeat=3      # the same event three times
php examples/send_webhook.php --simulate=transient
php examples/send_webhook.php --type=invoice.sent
```

The first delivery comes back as `{"status":"processed"}`, the later ones as
`{"status":"duplicate"}`. The handler ran once.

## See what was recorded

```
php examples/dump_events.php
```

## Layout

```
src/
  bootstrap.php          zero-dependency PSR-4 autoloader
  Clock.php              interface for "now"
  SystemClock.php        the real clock
  FixedClock.php         a clock you can move forward (tests)
  Headers.php            normalises $_SERVER into header names
  SignatureVerifier.php  HMAC-SHA256 check, timestamp window, constant-time compare
  SignatureResult.php    the result of a signature check
  ClaimResult.php        the result of trying to claim an event
  EventStore.php         SQLite table, atomic claim, leases, sweeper, pruning
  WebhookReceiver.php    verify -> claim -> process -> answer
  HttpKernel.php         routing and the method check
  OrderHandler.php       example business handler (order.paid)
  PermanentFailure.php   "this will never work, stop retrying"
  Response.php           tiny response object
public/
  index.php              the only file your web server points at
examples/
  send_webhook.php       signs an event and POSTs it
  dump_events.php        prints the event table
tests/
  harness.php            80-line test harness
  run.php                runs everything
```

## The state machine

```
(new) --claim--> processing --ok--------------> done
                   |  |
                   |  +--transient failure--> failed --claim--> processing
                   |
                   +----permanent failure--> failed_permanent
```

- `done` and `failed_permanent` are terminal: a redelivery is acknowledged and
  ignored.
- `failed` is retryable: the provider's next delivery runs the handler again.
- `processing` holds a lease. If the worker dies, the lease expires and
  `EventStore::recoverExpiredLeases()` hands the event back to a queue.

## Before you put this in production

- Swap SQLite for MySQL or Postgres. Keep the `UNIQUE` constraint on the event
  id - it is the thing that makes the claim atomic.
- Answer the provider quickly. Claim the event, put it on a queue, return 200.
  The code here does the work inline because it is easier to follow.
- Run `recoverExpiredLeases()` from a cron job and re-enqueue whatever it
  returns.
- Prune old rows (`EventStore::prune()`) and keep the retention window longer
  than the provider's longest retry schedule.
- Rotate the signing secret and accept both the old and the new one for a
  while.
- Log the event id, the status and the attempt count on every delivery.

## License

MIT.

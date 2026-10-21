<?php

declare(strict_types=1);

/**
 * The webhook endpoint.
 *
 * Point your web server at this file. There is nothing clever in here on
 * purpose: every decision lives in src/, where it can be tested without a
 * web server.
 *
 *   php -S 127.0.0.1:8000 -t public
 *
 * The shared secret comes from the environment, never from the code:
 *
 *   export WEBHOOK_SECRET='whsec_...'
 */

require __DIR__ . '/../src/bootstrap.php';

use App\Webhook\EventStore;
use App\Webhook\HttpKernel;
use App\Webhook\OrderHandler;
use App\Webhook\SignatureVerifier;
use App\Webhook\WebhookReceiver;

$secret = getenv('WEBHOOK_SECRET');

if ($secret === false || $secret === '') {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'reason' => 'missing_secret']);

    exit;
}

$directory = dirname(__DIR__) . '/var';

if (!is_dir($directory)) {
    mkdir($directory, 0775, true);
}

$db = new PDO('sqlite:' . $directory . '/webhooks.sqlite', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// WAL lets the sweeper write while the receiver reads.
$db->exec('PRAGMA journal_mode = WAL');
$db->exec('PRAGMA busy_timeout = 5000');

$store = new EventStore($db);
$store->migrate();

$handler = new OrderHandler($db);
$handler->migrate();

$receiver = new WebhookReceiver(
    new SignatureVerifier($secret),
    $store,
    $handler
);

$kernel = new HttpKernel($receiver, '/webhooks/orders');

// The raw bytes, exactly as they arrived. The signature is checked against
// these bytes, so they must not be re-encoded on the way in.
$rawBody = file_get_contents('php://input');

$response = $kernel->handle($_SERVER, $rawBody === false ? '' : $rawBody);

http_response_code($response->status);

foreach ($response->headers as $name => $value) {
    header($name . ': ' . $value);
}

echo $response->body();

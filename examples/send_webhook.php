<?php

declare(strict_types=1);

/**
 * Stand in for the payment provider: sign an event and POST it to the
 * receiver.
 *
 *   php examples/send_webhook.php
 *   php examples/send_webhook.php --repeat=3
 *   php examples/send_webhook.php --url=http://127.0.0.1:8000/webhooks/orders
 *   php examples/send_webhook.php --secret=whsec_dev --type=invoice.sent
 *   php examples/send_webhook.php --simulate=transient
 *
 * With --repeat the *same* event id is sent several times, which is the
 * easiest way to see the idempotency guard work: the first delivery is
 * "processed", every later one is a "duplicate".
 */

require __DIR__ . '/../src/bootstrap.php';

use App\Webhook\SignatureVerifier;

$options = getopt('', [
    'url:',
    'secret:',
    'event:',
    'type:',
    'order:',
    'version:',
    'amount:',
    'simulate:',
    'repeat:',
]);

$url = $options['url'] ?? 'http://127.0.0.1:8000/webhooks/orders';
$secret = $options['secret'] ?? (getenv('WEBHOOK_SECRET') ?: 'whsec_dev_secret');
$eventId = $options['event'] ?? ('evt_' . bin2hex(random_bytes(4)));
$type = $options['type'] ?? 'order.paid';
$orderId = $options['order'] ?? 'ord_1';
$version = (int) ($options['version'] ?? 1);
$amount = (int) ($options['amount'] ?? 2500);
$simulate = $options['simulate'] ?? null;
$repeat = max(1, (int) ($options['repeat'] ?? 1));

$data = [
    'order_id' => $orderId,
    'version' => $version,
    'amount_cents' => $amount,
];

if ($simulate !== null) {
    $data['simulate_failure'] = $simulate;
}

$payload = (string) json_encode([
    'id' => $eventId,
    'type' => $type,
    'data' => $data,
], JSON_UNESCAPED_SLASHES);

$timestamp = time();
$signature = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $payload, $secret);

echo "POST {$url}\n";
echo "event id : {$eventId}\n";
echo "payload  : {$payload}\n";
echo "signature: {$signature}\n\n";

for ($attempt = 1; $attempt <= $repeat; $attempt++) {
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", [
                'Content-Type: application/json',
                'X-Webhook-Signature: ' . $signature,
                'X-Webhook-Timestamp: ' . $timestamp,
            ]) . "\r\n",
            'content' => $payload,
            'timeout' => 10,
            'ignore_errors' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    $status = 'no response';

    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $line) {
            if (stripos($line, 'HTTP/') === 0) {
                $status = trim($line);
            }
        }
    }

    echo "delivery {$attempt}: {$status}\n";
    echo '  ' . ($body === false ? 'request failed' : $body) . "\n\n";

    unset($http_response_header);
}

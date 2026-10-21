<?php

declare(strict_types=1);

/**
 * Show what the receiver recorded.
 *
 *   php examples/dump_events.php
 */

require __DIR__ . '/../src/bootstrap.php';

use App\Webhook\EventStore;

$file = dirname(__DIR__) . '/var/webhooks.sqlite';

if (!is_file($file)) {
    echo "No database yet at {$file}\n";
    echo "Start the receiver first: php -S 127.0.0.1:8000 -t public\n";

    exit(1);
}

$db = new PDO('sqlite:' . $file, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$store = new EventStore($db);
$events = $store->all();

if ($events === []) {
    echo "No events recorded yet.\n";

    exit(0);
}

printf("%-22s %-14s %-18s %8s  %s\n", 'EVENT ID', 'TYPE', 'STATUS', 'ATTEMPTS', 'ERROR');
echo str_repeat('-', 100) . "\n";

foreach ($events as $event) {
    printf(
        "%-22s %-14s %-18s %8d  %s\n",
        $event['id'],
        $event['type'],
        $event['status'],
        (int) $event['attempts'],
        $event['last_error'] === null ? '' : $event['last_error']
    );
}

echo "\n" . count($events) . " event(s) in {$file}\n";

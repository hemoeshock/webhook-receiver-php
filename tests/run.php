<?php

declare(strict_types=1);

/**
 * Run every test in this tutorial:
 *
 *     php tests/run.php
 *
 * Exit code 0 means everything passed.
 */

require __DIR__ . '/harness.php';
require __DIR__ . '/../src/bootstrap.php';

require __DIR__ . '/SignatureVerifierTest.php';
require __DIR__ . '/EventStoreTest.php';
require __DIR__ . '/WebhookReceiverTest.php';
require __DIR__ . '/HttpKernelTest.php';

echo "Webhook receiver test suite\n";
echo str_repeat('-', 60) . "\n\n";

signatureVerifierTests();
eventStoreTests();
webhookReceiverTests();
httpKernelTests();

exit(Harness::summary('Total'));

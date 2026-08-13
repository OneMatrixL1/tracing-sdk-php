<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Tracing\Sdk\SendOptions;
use Tracing\Sdk\TracingSDK;

// Send a record, then look the anchor up again by its hash via
// GET /api/anchors?hash=...
$sdk = new TracingSDK([
    'endpoint' => 'http://localhost:3000', // replace with your provided indexer endpoint
    'options'  => SendOptions::dataType('json'), // default for every send/sendBatch
    'auth'     => [
        'type'  => 'apiToken',
        'token' => 'your-api-token', // replace with your provided API token
    ],
]);

try {
    $result = $sdk->send(json_encode(['orderId' => 1]), time());
    printf("[sent] %s -> HTTP %d\n", $result['hash'], $result['response']['statusCode']);

    // Hashing is deterministic, so you can also query a hash you stored
    // earlier — or re-derived from the original record — without sending again.
    // A record can be anchored more than once, so the lookup returns every
    // transaction the hash appears in.
    $anchor = $sdk->queryByHash($result['hash']);
    printf(
        "[found] %s anchored in %d tx: %s\n",
        $anchor['hash'],
        count($anchor['txHashes']),
        implode(', ', $anchor['txHashes'])
    );
} catch (\Throwable $e) {
    fwrite(STDERR, '[error] ' . $e->getMessage() . "\n");
}

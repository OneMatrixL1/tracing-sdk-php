<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Tracing\Sdk\SendOptions;
use Tracing\Sdk\TracingSDK;

// The SDK canonicalizes + hashes (Keccak-256) and sends in one call — you
// decide when and how (single record vs. batch).
$sdk = new TracingSDK([
    'endpoint' => 'http://localhost:3000', // replace with your provided indexer endpoint
    'options'  => new SendOptions('json', 5000), // dataType + request timeout in ms,
                                                 // defaults for every send/sendBatch
    'auth'     => [
        'type'  => 'apiToken',
        'token' => 'your-api-token', // replace with your provided API token
    ],
]);

// Send a batch of records together via POST /api/anchors/batch.
try {
    $results = $sdk->sendBatch([
        ['rawData' => json_encode(['orderId' => 1, 'amount' => 10]), 'signingTime' => time()],
        ['rawData' => json_encode(['orderId' => 2, 'amount' => 20]), 'signingTime' => time()],
        ['rawData' => json_encode(['orderId' => 3, 'amount' => 30]), 'signingTime' => time()],
    ], SendOptions::timeoutMs(30000)); // a batch gets more headroom than the 5s default

    foreach ($results as $result) {
        printf(
            "[sent] %s -> HTTP %d, %d record(s)%s\n",
            $result['hash'],
            $result['response']['statusCode'],
            $result['response']['recordCount'],
            is_array($result['response']['body']) ? ' -> ' . json_encode($result['response']['body']) : ''
        );
    }
} catch (\Throwable $e) {
    fwrite(STDERR, '[error] ' . $e->getMessage() . "\n");
}

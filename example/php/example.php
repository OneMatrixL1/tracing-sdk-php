<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Tracing\Sdk\TracingSDK;

// The SDK only canonicalizes + hashes (Keccak-256). Sending is an explicit,
// separate step — you decide when and how (single record vs. batch).
$sdk = new TracingSDK([
    'endpoint' => 'http://localhost:3000',
    'dataType' => 'json',
    'auth'     => [
        'type'  => 'apiToken',
        'token' => 'your-api-token',
    ],
]);

// Hash a batch of records, then send them together via POST /api/anchors/batch.
$entries = $sdk->hashBatch([
    ['rawData' => json_encode(['orderId' => 1, 'amount' => 10]), 'signingTime' => time()],
    ['rawData' => json_encode(['orderId' => 2, 'amount' => 20]), 'signingTime' => time()],
    ['rawData' => json_encode(['orderId' => 3, 'amount' => 30]), 'signingTime' => time()],
]);

try {
    $result = $sdk->sendBatch($entries);
    printf(
        "[sent] HTTP %d, %d record(s)%s\n",
        $result['statusCode'],
        $result['recordCount'],
        is_array($result['body']) ? ' -> ' . json_encode($result['body']) : ''
    );
} catch (\Throwable $e) {
    fwrite(STDERR, '[error] ' . $e->getMessage() . "\n");
}

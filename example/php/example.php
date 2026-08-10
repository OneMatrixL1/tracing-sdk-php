<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Tracing\Sdk\TracingSDK;

$sdk = new TracingSDK([
    'endpoint'      => 'http://localhost:3000',
    'batchSize'     => 5,            // buffer up to 5 records, then POST /api/anchors/batch
    'flushInterval' => 5000,         // ...or flush after 5s even if the buffer isn't full
    'dataType'      => 'json',
    'auth'          => [
        'type'  => 'apiToken',
        'token' => 'your-api-token',
    ],
]);

$sdk->on('sent', function (array $result): void {
    printf(
        "[sent] HTTP %d, %d record(s)%s\n",
        $result['statusCode'],
        $result['recordCount'],
        is_array($result['body']) ? ' -> ' . json_encode($result['body']) : ''
    );
});

$sdk->on('error', function (\Throwable $e): void {
    fwrite(STDERR, '[error] ' . $e->getMessage() . "\n");
});

// Index a few records one at a time — they sit in the buffer until batchSize
// or flushInterval is reached.
for ($i = 1; $i <= 3; $i++) {
    $sdk->index(
        json_encode(['orderId' => $i, 'amount' => $i * 10]),
        time()
    );
    echo "indexed record #$i (pending: {$sdk->pendingCount()})\n";
}

// Or index several records in a single call.
$sdk->index([
    ['rawData' => json_encode(['orderId' => 100]), 'signingTime' => time()],
    ['rawData' => json_encode(['orderId' => 101]), 'signingTime' => time()],
]);

echo "pending before flush: {$sdk->pendingCount()}\n";

// Force everything currently buffered out now instead of waiting for
// batchSize/flushInterval.
$sdk->flush();

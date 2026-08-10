<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Tracing\Sdk\TracingSDK;

$sdk = new TracingSDK([
    'endpoint' => 'http://localhost:3000',
    'dataType' => 'xml',
    'auth'     => [
        'type'  => 'apiToken',
        'token' => 'your-api-token',
    ],
]);

function orderXml(int $orderId, int $amount): string
{
    return sprintf('<order><orderId>%d</orderId><amount>%d</amount></order>', $orderId, $amount);
}

// Hash a batch of records, then send them together via POST /api/anchors/batch.
$entries = $sdk->hashBatch([
    ['rawData' => orderXml(1, 10), 'signingTime' => time()],
    ['rawData' => orderXml(2, 20), 'signingTime' => time()],
    ['rawData' => orderXml(3, 30), 'signingTime' => time()],
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

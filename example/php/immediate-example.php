<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Tracing\Sdk\TracingSDK;

// batchSize 0 (or 1) disables buffering entirely: every record is sent as
// soon as it's indexed, via POST /api/anchors instead of /api/anchors/batch.
$sdk = new TracingSDK([
    'endpoint'  => 'http://localhost:3000',
    'batchSize' => 0,
    'dataType'  => 'json',
    'auth'      => [
        'type'  => 'apiToken',
        'token' => 'your-api-token',
    ],
]);

$sdk->on('sent', function (array $result): void {
    printf("[sent] HTTP %d%s\n", $result['statusCode'], is_array($result['body']) ? ' -> ' . json_encode($result['body']) : '');
});

$sdk->on('error', function (\Throwable $e): void {
    fwrite(STDERR, '[error] ' . $e->getMessage() . "\n");
});

$sdk->index(json_encode(['orderId' => 1]), time());
echo "pending after index: {$sdk->pendingCount()}\n"; // always 0 in immediate mode

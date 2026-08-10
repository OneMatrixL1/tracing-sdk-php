<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Tracing\Sdk\TracingSDK;

// Hashing and sending are decoupled — nothing stops you from hashing one
// record and sending it right away via POST /api/anchors instead of batching.
$sdk = new TracingSDK([
    'endpoint' => 'http://localhost:3000',
    'dataType' => 'json',
    'auth'     => [
        'type'  => 'apiToken',
        'token' => 'your-api-token',
    ],
]);

$entry = $sdk->hash(json_encode(['orderId' => 1]), time());

try {
    $result = $sdk->send($entry);
    printf("[sent] HTTP %d%s\n", $result['statusCode'], is_array($result['body']) ? ' -> ' . json_encode($result['body']) : '');
} catch (\Throwable $e) {
    fwrite(STDERR, '[error] ' . $e->getMessage() . "\n");
}

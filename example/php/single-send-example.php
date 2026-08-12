<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Tracing\Sdk\TracingSDK;

// Send a single record right away via POST /api/anchors instead of batching.
$sdk = new TracingSDK([
    'endpoint' => 'http://localhost:3000',
    'dataType' => 'json',
    'auth'     => [
        'type'  => 'apiToken',
        'token' => 'your-api-token',
    ],
]);

try {
    $result = $sdk->send(json_encode(['orderId' => 1]), time());
    printf(
        "[sent] %s -> HTTP %d%s\n",
        $result['hash'],
        $result['response']['statusCode'],
        is_array($result['response']['body']) ? ' -> ' . json_encode($result['response']['body']) : ''
    );
} catch (\Throwable $e) {
    fwrite(STDERR, '[error] ' . $e->getMessage() . "\n");
}

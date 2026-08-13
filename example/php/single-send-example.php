<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Tracing\Sdk\SendOptions;
use Tracing\Sdk\TracingSDK;

// Send a single record right away via POST /api/anchors instead of batching.
$sdk = new TracingSDK([
    'endpoint' => 'http://localhost:3000', // replace with your provided indexer endpoint
    'options'  => SendOptions::dataType('raw'), // default for every send/sendBatch
    'auth'     => [
        'type'  => 'apiToken',
        'token' => 'your-api-token', // replace with your provided API token
    ],
]);

try {
    $result = $sdk->send(json_encode(['orderId' => 1]), time(), SendOptions::dataType('json')); // override default data type for this send
    printf(
        "[sent] %s -> HTTP %d%s\n",
        $result['hash'],
        $result['response']['statusCode'],
        is_array($result['response']['body']) ? ' -> ' . json_encode($result['response']['body']) : ''
    );
} catch (\Throwable $e) {
    fwrite(STDERR, '[error] ' . $e->getMessage() . "\n");
}

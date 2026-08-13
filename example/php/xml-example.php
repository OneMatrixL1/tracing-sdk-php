<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Tracing\Sdk\SendOptions;
use Tracing\Sdk\TracingSDK;

$sdk = new TracingSDK([
    'endpoint' => 'http://localhost:3000', // replace with your provided indexer endpoint
    'options'  => SendOptions::dataType('xml'), // default for every send/sendBatch
    'auth'     => [
        'type'  => 'apiToken',
        'token' => 'your-api-token', // replace with your provided API token
    ],
]);

function orderXml(int $orderId, int $amount): string
{
    return sprintf('<order><orderId>%d</orderId><amount>%d</amount></order>', $orderId, $amount);
}

// Send a batch of records together via POST /api/anchors/batch.
try {
    $results = $sdk->sendBatch([
        ['rawData' => orderXml(1, 10), 'signingTime' => time()],
        ['rawData' => orderXml(2, 20), 'signingTime' => time()],
        ['rawData' => orderXml(3, 30), 'signingTime' => time()],
    ]);

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

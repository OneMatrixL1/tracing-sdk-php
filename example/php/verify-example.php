<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Tracing\Sdk\SendOptions;
use Tracing\Sdk\TracingSDK;

// Send a record, ask the Indexer which transactions anchored it, then check
// that answer against the chain itself over your own RPC endpoint.
$sdk = new TracingSDK([
    'endpoint' => 'http://localhost:3000', // replace with your provided indexer endpoint
    'options'  => new SendOptions(
        'json',                            // dataType — default for every send/sendBatch
        5000,                              // timeoutMs
        'http://localhost:8545'            // rpcUrl — replace with your chain node
    ),
    'auth'     => [
        'type'  => 'apiToken',
        'token' => 'your-api-token', // replace with your provided API token
    ],
]);

try {
    $result = $sdk->send(json_encode(['orderId' => 1]), time());
    printf("[sent] %s -> HTTP %d\n", $result['hash'], $result['response']['statusCode']);

    // What the Indexer claims: the transactions this hash was anchored in.
    $anchor = $sdk->queryByHash($result['hash']);
    printf("[found] %d tx: %s\n", count($anchor['proof']), implode(', ', $anchor['proof']));

    // What the chain says. verify() reads the transaction receipt over RPC and
    // looks for an Anchored(bytes32,uint64) log carrying this exact hash, so a
    // wrong or dishonest Indexer answer cannot pass.
    foreach ($anchor['proof'] as $proof) {
        $verified = $sdk->verify($anchor['hash'], $proof, $anchor['proofType']);
        printf("[verify] %s -> %s\n", $proof, $verified ? 'VERIFIED' : 'no matching anchor event');
    }
} catch (\Throwable $e) {
    // A TransportException here can also mean the transaction is not mined yet,
    // or the RPC node does not have it — retry rather than concluding anything.
    fwrite(STDERR, '[error] ' . $e->getMessage() . "\n");
}

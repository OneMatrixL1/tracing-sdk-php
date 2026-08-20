<?php

declare(strict_types=1);

namespace Tracing\Sdk\Rpc;

interface RpcTransportInterface
{
    /**
     * Fetch a transaction receipt via the JSON-RPC method
     * eth_getTransactionReceipt.
     *
     * @param string $rpcUrl the JSON-RPC endpoint to call
     * @param string $txHash 0x-prefixed transaction hash
     * @param int|null $timeoutMs per-request timeout; null uses the transport default
     * @return array<string, mixed>|null the receipt, or null when the node
     *         does not know the transaction (JSON-RPC result: null)
     * @throws \Tracing\Sdk\Exception\TransportException if the request fails or
     *         the node answers with a JSON-RPC error
     */
    public function getTransactionReceipt(string $rpcUrl, string $txHash, ?int $timeoutMs = null): ?array;
}

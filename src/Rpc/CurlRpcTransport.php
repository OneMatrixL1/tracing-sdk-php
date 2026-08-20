<?php

declare(strict_types=1);

namespace Tracing\Sdk\Rpc;

use Tracing\Sdk\Exception\TransportException;

/**
 * Minimal JSON-RPC 2.0 client over cURL, used to read anchor events straight
 * from a chain node. Deliberately separate from the Indexer transport: the RPC
 * endpoint is a different service and the Indexer's auth must not leak to it.
 */
class CurlRpcTransport implements RpcTransportInterface
{
    /** Timeout applied when neither the config nor the call supplies one. */
    public const DEFAULT_TIMEOUT_MS = 10000;

    /** @var int */
    private $timeoutMs;

    public function __construct(int $timeoutMs = self::DEFAULT_TIMEOUT_MS)
    {
        $this->timeoutMs = $timeoutMs;
    }

    public function getTransactionReceipt(string $rpcUrl, string $txHash, ?int $timeoutMs = null): ?array
    {
        $result = $this->call($rpcUrl, 'eth_getTransactionReceipt', [$txHash], $timeoutMs);

        if ($result === null) {
            return null;
        }

        if (!\is_array($result)) {
            throw new TransportException('Unexpected eth_getTransactionReceipt result, expected an object');
        }

        return $result;
    }

    /**
     * @param array<int, mixed> $params
     * @return mixed the JSON-RPC "result" member
     * @throws TransportException
     */
    private function call(string $rpcUrl, string $method, array $params, ?int $timeoutMs)
    {
        $payload = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => $params,
        ], JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            throw new TransportException('Failed to encode JSON-RPC payload: ' . json_last_error_msg());
        }

        $ch = curl_init();

        if ($ch === false) {
            throw new TransportException('Failed to initialize cURL handle');
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $rpcUrl,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => $timeoutMs ?? $this->timeoutMs,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $responseBody = curl_exec($ch);

        if ($responseBody === false) {
            $error = curl_error($ch);
            $errno = curl_errno($ch);
            curl_close($ch);

            throw new TransportException(\sprintf('JSON-RPC request to %s failed (errno %d): %s', $rpcUrl, $errno, $error));
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new TransportException(\sprintf('RPC endpoint returned HTTP %d: %s', $statusCode, $responseBody));
        }

        $decoded = json_decode($responseBody, true);

        if (!\is_array($decoded)) {
            throw new TransportException('RPC endpoint returned a non-JSON body: ' . $responseBody);
        }

        if (isset($decoded['error'])) {
            $message = \is_array($decoded['error']) && isset($decoded['error']['message'])
                ? (string) $decoded['error']['message']
                : json_encode($decoded['error']);

            throw new TransportException(\sprintf('JSON-RPC error from %s: %s', $method, (string) $message));
        }

        if (!\array_key_exists('result', $decoded)) {
            throw new TransportException(\sprintf('JSON-RPC response for %s has no "result" member', $method));
        }

        return $decoded['result'];
    }
}

<?php

declare(strict_types=1);

namespace Tracing\Sdk\Transport;

use Tracing\Sdk\Auth\AuthenticatorInterface;
use Tracing\Sdk\Exception\TransportException;

/**
 * The SDK's single point of contact with the outside world: one POST per
 * flush (or per record, when batching is disabled) to the configured
 * Indexer endpoint — POST /api/anchors for a single record, POST
 * /api/anchors/batch for a batch.
 */
class CurlHttpTransport implements HttpTransportInterface
{
    /** @var string */
    private $singleUrl;

    /** @var string */
    private $batchUrl;

    /** @var AuthenticatorInterface */
    private $authenticator;

    /** @var int */
    private $timeoutMs;

    public function __construct(string $baseEndpoint, AuthenticatorInterface $authenticator, int $timeoutMs = 10000)
    {
        $base = rtrim($baseEndpoint, '/');
        $this->singleUrl = $base . '/api/anchors';
        $this->batchUrl = $base . '/api/anchors/batch';
        $this->authenticator = $authenticator;
        $this->timeoutMs = $timeoutMs;
    }

    public function sendSingle(array $record): array
    {
        return $this->request($this->singleUrl, $record, 1);
    }

    public function sendBatch(array $records): array
    {
        return $this->request($this->batchUrl, array_values($records), count($records));
    }

    /**
     * GET /api/anchors?hash=... — the Indexer responds with
     * { "hash": "<hash hex>", "txHash": "<tx hash hex>" }.
     */
    public function queryByHash(string $hash): array
    {
        $url = $this->singleUrl . '?' . http_build_query(['hash' => $hash]);

        return $this->execute($url, [CURLOPT_HTTPGET => true], ['Accept: application/json']);
    }

    /**
     * @param mixed $body
     */
    private function request(string $url, $body, int $recordCount): array
    {
        $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($payload === false) {
            throw new TransportException('Failed to encode request payload: ' . json_last_error_msg());
        }

        $response = $this->execute(
            $url,
            [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload],
            ['Content-Type: application/json', 'Accept: application/json']
        );
        $response['recordCount'] = $recordCount;

        return $response;
    }

    /**
     * @param array<int, mixed> $methodOptions
     * @param array<int, string> $headers
     * @return array{statusCode: int, body: mixed}
     */
    private function execute(string $url, array $methodOptions, array $headers): array
    {
        $ch = curl_init();

        if ($ch === false) {
            throw new TransportException('Failed to initialize cURL handle');
        }

        $this->authenticator->apply($ch, $headers);

        curl_setopt_array($ch, $methodOptions + [
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => $this->timeoutMs,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $responseBody = curl_exec($ch);

        if ($responseBody === false) {
            $error = curl_error($ch);
            $errno = curl_errno($ch);
            curl_close($ch);

            throw new TransportException(\sprintf('HTTP request to Indexer failed (errno %d): %s', $errno, $error));
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new TransportException(\sprintf('Indexer returned HTTP %d: %s', $statusCode, $responseBody));
        }

        $decodedBody = json_decode($responseBody, true);

        return [
            'statusCode' => $statusCode,
            'body' => json_last_error() === JSON_ERROR_NONE ? $decodedBody : $responseBody,
        ];
    }
}

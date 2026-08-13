<?php

declare(strict_types=1);

namespace Tracing\Sdk\Transport;

interface HttpTransportInterface
{
    /**
     * Send a single { hash, signingTime } record to the Indexer.
     *
     * @param array{hash: string, signingTime: mixed} $record
     * @param int|null $timeoutMs per-request timeout; null uses the transport default
     * @return array{statusCode: int, body: mixed, recordCount: int}
     * @throws \Tracing\Sdk\Exception\TransportException
     */
    public function sendSingle(array $record, ?int $timeoutMs = null): array;

    /**
     * Send a batch of { hash, signingTime } records to the Indexer.
     *
     * @param array<int, array{hash: string, signingTime: mixed}> $records
     * @param int|null $timeoutMs per-request timeout; null uses the transport default
     * @return array{statusCode: int, body: mixed, recordCount: int}
     * @throws \Tracing\Sdk\Exception\TransportException
     */
    public function sendBatch(array $records, ?int $timeoutMs = null): array;

    /**
     * Query anchors by hash from the Indexer.
     *
     * @param string $hash
     * @param int|null $timeoutMs per-request timeout; null uses the transport default
     * @return array{statusCode: int, body: mixed}
     * @throws \Tracing\Sdk\Exception\TransportException
     */
    public function queryByHash(string $hash, ?int $timeoutMs = null): array;
}

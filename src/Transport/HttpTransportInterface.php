<?php

declare(strict_types=1);

namespace Tracing\Sdk\Transport;

interface HttpTransportInterface
{
    /**
     * Send a single { hash, signingTime } record to the Indexer.
     * Used when batching is disabled (batchSize 0 or 1).
     *
     * @param array{hash: string, signingTime: mixed} $record
     * @return array{statusCode: int, body: mixed, recordCount: int}
     * @throws \Tracing\Sdk\Exception\TransportException
     */
    public function sendSingle(array $record): array;

    /**
     * Send a batch of { hash, signingTime } records to the Indexer.
     *
     * @param array<int, array{hash: string, signingTime: mixed}> $records
     * @return array{statusCode: int, body: mixed, recordCount: int}
     * @throws \Tracing\Sdk\Exception\TransportException
     */
    public function sendBatch(array $records): array;
}

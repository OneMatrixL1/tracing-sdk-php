<?php

declare(strict_types=1);

namespace Tracing\Sdk\Tests;

use Tracing\Sdk\Exception\TransportException;
use Tracing\Sdk\Transport\HttpTransportInterface;

final class FakeTransport implements HttpTransportInterface
{
    /** @var array<int, array{hash: string, signingTime: mixed}> */
    public $singleCalls = [];

    /** @var array<int, array<int, array{hash: string, signingTime: mixed}>> */
    public $batchCalls = [];

    /** @var array<int, string> */
    public $queryCalls = [];

    /** @var array{statusCode: int, body: mixed}|null */
    public $queryResponse = null;

    /** @var bool */
    public $throw = false;

    /** Timeout passed to the most recent call, in call order. @var array<int, int|null> */
    public $timeoutCalls = [];

    public function sendSingle(array $record, ?int $timeoutMs = null): array
    {
        if ($this->throw) {
            throw new TransportException('boom');
        }

        $this->singleCalls[] = $record;
        $this->timeoutCalls[] = $timeoutMs;

        return ['statusCode' => 200, 'body' => null, 'recordCount' => 1];
    }

    public function sendBatch(array $records, ?int $timeoutMs = null): array
    {
        if ($this->throw) {
            throw new TransportException('boom');
        }

        $this->batchCalls[] = $records;
        $this->timeoutCalls[] = $timeoutMs;

        return ['statusCode' => 200, 'body' => null, 'recordCount' => count($records)];
    }

    public function queryByHash(string $hash, ?int $timeoutMs = null): array
    {
        if ($this->throw) {
            throw new TransportException('boom');
        }

        $this->queryCalls[] = $hash;
        $this->timeoutCalls[] = $timeoutMs;

        if ($this->queryResponse !== null) {
            return $this->queryResponse;
        }

        return [
            'statusCode' => 200,
            'body' => ['hash' => $hash, 'proof' => ['0xabc', '0xdef'], 'proofType' => 'transactionHash'],
        ];
    }
}

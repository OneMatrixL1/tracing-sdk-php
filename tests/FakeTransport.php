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

    /** @var bool */
    public $throw = false;

    public function sendSingle(array $record): array
    {
        if ($this->throw) {
            throw new TransportException('boom');
        }

        $this->singleCalls[] = $record;

        return ['statusCode' => 200, 'body' => null, 'recordCount' => 1];
    }

    public function sendBatch(array $records): array
    {
        if ($this->throw) {
            throw new TransportException('boom');
        }

        $this->batchCalls[] = $records;

        return ['statusCode' => 200, 'body' => null, 'recordCount' => count($records)];
    }
}

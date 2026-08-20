<?php

declare(strict_types=1);

namespace Tracing\Sdk\Tests;

use Tracing\Sdk\Exception\TransportException;
use Tracing\Sdk\Rpc\RpcTransportInterface;

final class FakeRpcTransport implements RpcTransportInterface
{
    /** @var array<int, array{rpcUrl: string, txHash: string, timeoutMs: int|null}> */
    public $calls = [];

    /** The receipt to answer with; null means "transaction unknown". @var array<string, mixed>|null */
    public $receipt = null;

    /** @var bool */
    public $throw = false;

    public function getTransactionReceipt(string $rpcUrl, string $txHash, ?int $timeoutMs = null): ?array
    {
        if ($this->throw) {
            throw new TransportException('boom');
        }

        $this->calls[] = ['rpcUrl' => $rpcUrl, 'txHash' => $txHash, 'timeoutMs' => $timeoutMs];

        return $this->receipt;
    }
}

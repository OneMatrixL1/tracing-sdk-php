<?php

declare(strict_types=1);

namespace Tracing\Sdk\Tests;

use PHPUnit\Framework\TestCase;
use Tracing\Sdk\Exception\ConfigException;
use Tracing\Sdk\Exception\TransportException;
use Tracing\Sdk\Hash\Keccak256Hasher;
use Tracing\Sdk\SendOptions;
use Tracing\Sdk\TracingSDK;
use Tracing\Sdk\Verify\AnchoredEventDecoder;

class VerifyTest extends TestCase
{
    private const DATA_HASH = '0x1c8aff950685c2ed4bc3174f3472287b56d9517b9c948127319a09a7a36deac8';
    private const TX_HASH = '0x9f42bb1c5b1e2b7f2c1d8e3a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e';

    /** @var FakeRpcTransport */
    private $rpc;

    /** @var TracingSDK */
    private $sdk;

    protected function setUp(): void
    {
        $this->rpc = new FakeRpcTransport();
        $this->sdk = new TracingSDK([
            'endpoint' => 'https://indexer.example.com',
            'options' => new SendOptions('json', null, 'https://rpc.example.com'),
            'auth' => ['type' => 'apiToken', 'token' => 'test-token'],
        ]);
        $this->sdk->setTransportForTesting(new FakeTransport());
        $this->sdk->setRpcTransportForTesting($this->rpc);
    }

    private function topic(): string
    {
        return (new AnchoredEventDecoder(new Keccak256Hasher()))->topic();
    }

    /** A 32-byte word holding a uint64, right-aligned as the ABI encodes it. */
    private function word(int $value): string
    {
        return str_pad(dechex($value), 64, '0', STR_PAD_LEFT);
    }

    public function testVerifiesIndexedDataHashFromTopics(): void
    {
        $this->rpc->receipt = [
            'blockNumber' => '0x10',
            'logs' => [[
                'address' => '0xAbC0000000000000000000000000000000000001',
                'topics' => [$this->topic(), self::DATA_HASH],
                'data' => '0x' . $this->word(1700000000),
                'logIndex' => '0x2',
            ]],
        ];

        $this->assertTrue($this->sdk->verify(self::DATA_HASH, self::TX_HASH));
    }

    public function testVerifiesNonIndexedDataHashFromLogData(): void
    {
        $this->rpc->receipt = [
            'logs' => [[
                'topics' => [$this->topic()],
                'data' => '0x' . substr(self::DATA_HASH, 2) . $this->word(1700000000),
            ]],
        ];

        $this->assertTrue($this->sdk->verify(self::DATA_HASH, self::TX_HASH));
    }

    public function testAcceptsUppercaseAndUnprefixedHashes(): void
    {
        $this->rpc->receipt = [
            'logs' => [[
                'topics' => [strtoupper($this->topic()), strtoupper(self::DATA_HASH)],
                'data' => '0x' . $this->word(1),
            ]],
        ];

        $this->assertTrue($this->sdk->verify(strtoupper(substr(self::DATA_HASH, 2)), self::TX_HASH));
    }

    public function testFindsTheAnchorAmongUnrelatedLogs(): void
    {
        $otherHash = '0x' . str_repeat('ab', 32);

        $this->rpc->receipt = [
            'logs' => [
                ['topics' => ['0x' . str_repeat('11', 32), self::DATA_HASH], 'data' => '0x'],
                ['topics' => [$this->topic(), $otherHash], 'data' => '0x' . $this->word(5)],
                ['topics' => [$this->topic(), self::DATA_HASH], 'data' => '0x' . $this->word(7)],
            ],
        ];

        $this->assertTrue($this->sdk->verify(self::DATA_HASH, self::TX_HASH));
    }

    public function testReturnsFalseWhenAnchoredHashDiffers(): void
    {
        $this->rpc->receipt = [
            'logs' => [[
                'topics' => [$this->topic(), '0x' . str_repeat('cd', 32)],
                'data' => '0x' . $this->word(1700000000),
            ]],
        ];

        $this->assertFalse($this->sdk->verify(self::DATA_HASH, self::TX_HASH));
    }

    public function testReturnsFalseWhenTransactionHasNoAnchoredEvent(): void
    {
        $this->rpc->receipt = ['logs' => []];

        $this->assertFalse($this->sdk->verify(self::DATA_HASH, self::TX_HASH));
    }

    public function testReturnsFalseWhenTheEventIsTruncated(): void
    {
        // topics[0] matches but the uint64 argument is missing entirely.
        $this->rpc->receipt = [
            'logs' => [['topics' => [$this->topic(), self::DATA_HASH], 'data' => '0x']],
        ];

        $this->assertFalse($this->sdk->verify(self::DATA_HASH, self::TX_HASH));
    }

    public function testPassesNormalizedTxHashAndConfiguredRpcUrlToTheTransport(): void
    {
        $this->rpc->receipt = ['logs' => []];

        $this->sdk->verify(self::DATA_HASH, strtoupper(self::TX_HASH));

        $this->assertSame(
            [['rpcUrl' => 'https://rpc.example.com', 'txHash' => self::TX_HASH, 'timeoutMs' => null]],
            $this->rpc->calls
        );
    }

    public function testPerCallOptionsOverrideRpcUrlAndTimeout(): void
    {
        $this->rpc->receipt = ['logs' => []];

        $this->sdk->verify(
            self::DATA_HASH,
            self::TX_HASH,
            TracingSDK::MODE_TRANSACTION_HASH,
            new SendOptions(null, 250, 'https://other-rpc.example.com')
        );

        $this->assertSame(
            [['rpcUrl' => 'https://other-rpc.example.com', 'txHash' => self::TX_HASH, 'timeoutMs' => 250]],
            $this->rpc->calls
        );
    }

    public function testThrowsWhenNoRpcUrlIsConfigured(): void
    {
        $sdk = new TracingSDK([
            'endpoint' => 'https://indexer.example.com',
            'options' => SendOptions::dataType('json'),
            'auth' => ['type' => 'apiToken', 'token' => 'test-token'],
        ]);
        $sdk->setRpcTransportForTesting($this->rpc);

        $this->expectException(ConfigException::class);

        $sdk->verify(self::DATA_HASH, self::TX_HASH);
    }

    public function testThrowsOnUnsupportedMode(): void
    {
        $this->expectException(ConfigException::class);

        $this->sdk->verify(self::DATA_HASH, self::TX_HASH, 'blockNumber');
    }

    public function testThrowsOnEmptyDataHash(): void
    {
        $this->expectException(ConfigException::class);

        $this->sdk->verify('', self::TX_HASH);
    }

    public function testThrowsOnMalformedProof(): void
    {
        $this->expectException(ConfigException::class);

        $this->sdk->verify(self::DATA_HASH, '0xnot-a-hash');
    }

    public function testThrowsWhenTheTransactionIsUnknownToTheNode(): void
    {
        $this->rpc->receipt = null;

        $this->expectException(TransportException::class);

        $this->sdk->verify(self::DATA_HASH, self::TX_HASH);
    }

    public function testPropagatesRpcTransportFailures(): void
    {
        $this->rpc->throw = true;

        $this->expectException(TransportException::class);

        $this->sdk->verify(self::DATA_HASH, self::TX_HASH);
    }

    public function testEventTopicMatchesTheSignatureHash(): void
    {
        $this->assertSame(
            (new Keccak256Hasher())->hash('Anchored(bytes32,uint64)'),
            $this->topic()
        );
    }
}

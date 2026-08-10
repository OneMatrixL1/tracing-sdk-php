<?php

declare(strict_types=1);

namespace Tracing\Sdk\Tests;

use PHPUnit\Framework\TestCase;
use Tracing\Sdk\Exception\ConfigException;
use Tracing\Sdk\Exception\TransportException;
use Tracing\Sdk\Hash\Keccak256Hasher;
use Tracing\Sdk\TracingSDK;

class TracingSDKTest extends TestCase
{
    private function makeSdk(array $overrides = []): TracingSDK
    {
        return new TracingSDK(array_merge([
            'endpoint' => 'https://indexer.example.com',
            'dataType' => 'json',
            'batchSize' => 3,
            'flushInterval' => 0,
            'flushOnShutdown' => false,
            'auth' => ['type' => 'apiToken', 'token' => 'test-token'],
        ], $overrides));
    }

    public function testFlushesOnceBatchSizeIsReached(): void
    {
        $sdk = $this->makeSdk(['batchSize' => 2]);
        $transport = new FakeTransport();
        $sdk->setTransportForTesting($transport);

        $result = null;
        $sdk->on('sent', function ($r) use (&$result) {
            $result = $r;
        });

        $sdk->index('{"a":1}', 1000);
        $this->assertSame(1, $sdk->pendingCount());
        $this->assertNull($result);

        $sdk->index('{"a":2}', 1001);

        $this->assertSame(0, $sdk->pendingCount());
        $this->assertCount(1, $transport->batchCalls);
        $this->assertCount(2, $transport->batchCalls[0]);
        $this->assertSame([], $transport->singleCalls);
        $this->assertNotNull($result);
    }

    public function testFlushSendsRemainingRecordsImmediately(): void
    {
        $sdk = $this->makeSdk(['batchSize' => 10]);
        $transport = new FakeTransport();
        $sdk->setTransportForTesting($transport);

        $sdk->index('{"a":1}', 1000);
        $this->assertSame(1, $sdk->pendingCount());

        $sdk->flush();

        $this->assertSame(0, $sdk->pendingCount());
        $this->assertCount(1, $transport->batchCalls);
    }

    public function testTransportErrorEmitsErrorEventInsteadOfThrowing(): void
    {
        $sdk = $this->makeSdk(['batchSize' => 10]);
        $transport = new FakeTransport();
        $transport->throw = true;
        $sdk->setTransportForTesting($transport);

        $caught = null;
        $sdk->on('error', function ($e) use (&$caught) {
            $caught = $e;
        });

        $sdk->index('{"a":1}', 1000);
        $sdk->flush();

        $this->assertInstanceOf(TransportException::class, $caught);
    }

    public function testBufferOnlyStoresHashAndSigningTimeNotRawData(): void
    {
        $sdk = $this->makeSdk(['batchSize' => 10]);
        $transport = new FakeTransport();
        $sdk->setTransportForTesting($transport);

        $sdk->index('{"secret":"do-not-leak"}', 1234);
        $sdk->flush();

        $sentRecord = $transport->batchCalls[0][0];
        $this->assertSame(['hash', 'signingTime'], array_keys($sentRecord));
        $this->assertSame(1234, $sentRecord['signingTime']);
        $this->assertStringStartsWith('0x', $sentRecord['hash']);
    }

    public function testMissingSigningTimeThrows(): void
    {
        $sdk = $this->makeSdk();

        $this->expectException(ConfigException::class);

        $sdk->index('{"a":1}');
    }

    public function testUnsupportedDataTypeThrows(): void
    {
        $this->expectException(ConfigException::class);

        $this->makeSdk(['dataType' => 'yaml']);
    }

    public function testUnsupportedAuthTypeThrows(): void
    {
        $this->expectException(ConfigException::class);

        $this->makeSdk(['auth' => ['type' => 'oauth2']]);
    }

    public function testIndexingSameContentTwiceProducesSameHash(): void
    {
        $sdk = $this->makeSdk(['batchSize' => 10]);
        $transport = new FakeTransport();
        $sdk->setTransportForTesting($transport);

        $sdk->index('{"a":1,"b":2}', 1);
        $sdk->index('{"b":2,"a":1}', 2);
        $sdk->flush();

        $records = $transport->batchCalls[0];
        $this->assertSame($records[0]['hash'], $records[1]['hash']);
    }

    public function testRawDataTypeHashesInputByteForByteWithoutCanonicalizing(): void
    {
        $sdk = $this->makeSdk(['dataType' => 'raw', 'batchSize' => 10]);
        $transport = new FakeTransport();
        $sdk->setTransportForTesting($transport);

        // Deliberately not valid JSON/XML — 'raw' must not try to parse it.
        $rawData = '  not json, not xml, just bytes  ';
        $sdk->index($rawData, 1000);
        $sdk->flush();

        $expectedHash = (new Keccak256Hasher())->hash($rawData);
        $this->assertSame($expectedHash, $transport->batchCalls[0][0]['hash']);
    }

    /**
     * @dataProvider immediateBatchSizeProvider
     */
    public function testBatchSizeZeroOrOneSendsImmediatelyWithoutBuffering(int $batchSize): void
    {
        $sdk = $this->makeSdk(['batchSize' => $batchSize, 'flushInterval' => 0]);
        $transport = new FakeTransport();
        $sdk->setTransportForTesting($transport);

        $sent = [];
        $sdk->on('sent', function ($r) use (&$sent) {
            $sent[] = $r;
        });

        $sdk->index('{"a":1}', 1000);

        $this->assertSame(0, $sdk->pendingCount(), 'record should never sit in the buffer');
        $this->assertCount(1, $transport->singleCalls);
        $this->assertSame([], $transport->batchCalls);
        $this->assertCount(1, $sent);

        $sdk->index('{"a":2}', 1001);

        $this->assertCount(2, $transport->singleCalls);
        $this->assertSame([], $transport->batchCalls);
    }

    public static function immediateBatchSizeProvider(): array
    {
        return [
            'batchSize 0' => [0],
            'batchSize 1' => [1],
        ];
    }

    public function testFlushIsNoopInImmediateMode(): void
    {
        $sdk = $this->makeSdk(['batchSize' => 1]);
        $transport = new FakeTransport();
        $sdk->setTransportForTesting($transport);

        $sdk->index('{"a":1}', 1000);
        $this->assertCount(1, $transport->singleCalls);

        // Nothing buffered, so an explicit flush() must not trigger another send.
        $sdk->flush();

        $this->assertCount(1, $transport->singleCalls);
        $this->assertSame([], $transport->batchCalls);
    }

    public function testImmediateModeTransportErrorEmitsErrorEvent(): void
    {
        $sdk = $this->makeSdk(['batchSize' => 1]);
        $transport = new FakeTransport();
        $transport->throw = true;
        $sdk->setTransportForTesting($transport);

        $caught = null;
        $sdk->on('error', function ($e) use (&$caught) {
            $caught = $e;
        });

        $sdk->index('{"a":1}', 1000);

        $this->assertInstanceOf(TransportException::class, $caught);
    }
}

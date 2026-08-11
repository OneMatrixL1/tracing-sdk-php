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
            'auth' => ['type' => 'apiToken', 'token' => 'test-token'],
        ], $overrides));
    }

    public function testHashReturnsHashAndSigningTime(): void
    {
        $sdk = $this->makeSdk();

        $entry = $sdk->hash('{"secret":"do-not-leak"}', 1234);

        $this->assertSame(['hash', 'signingTime'], array_keys($entry));
        $this->assertSame(1234, $entry['signingTime']);
        $this->assertStringStartsWith('0x', $entry['hash']);
    }

    public function testHashDoesNotTouchTheNetwork(): void
    {
        $sdk = $this->makeSdk();
        $transport = new FakeTransport();
        $sdk->setTransportForTesting($transport);

        $sdk->hash('{"a":1}', 1000);

        $this->assertSame([], $transport->singleCalls);
        $this->assertSame([], $transport->batchCalls);
    }

    public function testHashingSameContentTwiceProducesSameHash(): void
    {
        $sdk = $this->makeSdk();

        $a = $sdk->hash('{"a":1,"b":2}', 1);
        $b = $sdk->hash('{"b":2,"a":1}', 2);

        $this->assertSame($a['hash'], $b['hash']);
    }

    public function testHashMissingSigningTimeThrows(): void
    {
        $sdk = $this->makeSdk();

        $this->expectException(ConfigException::class);

        $sdk->hash('{"a":1}', null);
    }

    public function testHashBatchHashesEachRecord(): void
    {
        $sdk = $this->makeSdk();

        $entries = $sdk->hashBatch([
            ['rawData' => '{"a":1}', 'signingTime' => 1],
            ['rawData' => '{"a":2}', 'signingTime' => 2],
        ]);

        $this->assertCount(2, $entries);
        $this->assertSame(1, $entries[0]['signingTime']);
        $this->assertSame(2, $entries[1]['signingTime']);
        $this->assertNotSame($entries[0]['hash'], $entries[1]['hash']);
    }

    public function testHashBatchRequiresRawDataAndSigningTimeKeys(): void
    {
        $sdk = $this->makeSdk();

        $this->expectException(ConfigException::class);

        $sdk->hashBatch([['rawData' => '{"a":1}']]);
    }

    public function testRawDataTypeHashesInputByteForByteWithoutCanonicalizing(): void
    {
        $sdk = $this->makeSdk(['dataType' => 'raw']);

        // Deliberately not valid JSON/XML — 'raw' must not try to parse it.
        $rawData = '  not json, not xml, just bytes  ';
        $entry = $sdk->hash($rawData, 1000);

        $expectedHash = (new Keccak256Hasher())->hash($rawData);
        $this->assertSame($expectedHash, $entry['hash']);
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

    public function testSendSendsSingleEntry(): void
    {
        $sdk = $this->makeSdk();
        $transport = new FakeTransport();
        $sdk->setTransportForTesting($transport);

        $entry = $sdk->hash('{"a":1}', 1000);
        $result = $sdk->send($entry);

        $this->assertSame(200, $result['statusCode']);
        $this->assertSame([$entry], $transport->singleCalls);
        $this->assertSame([], $transport->batchCalls);
    }

    public function testSendBatchSendsAllEntriesTogether(): void
    {
        $sdk = $this->makeSdk();
        $transport = new FakeTransport();
        $sdk->setTransportForTesting($transport);

        $entries = $sdk->hashBatch([
            ['rawData' => '{"a":1}', 'signingTime' => 1],
            ['rawData' => '{"a":2}', 'signingTime' => 2],
        ]);
        $result = $sdk->sendBatch($entries);

        $this->assertSame(2, $result['recordCount']);
        $this->assertSame([$entries], $transport->batchCalls);
        $this->assertSame([], $transport->singleCalls);
    }

    public function testSendThrowsOnTransportFailure(): void
    {
        $sdk = $this->makeSdk();
        $transport = new FakeTransport();
        $transport->throw = true;
        $sdk->setTransportForTesting($transport);

        $entry = $sdk->hash('{"a":1}', 1000);

        $this->expectException(TransportException::class);

        $sdk->send($entry);
    }

    public function testSendBatchThrowsOnTransportFailure(): void
    {
        $sdk = $this->makeSdk();
        $transport = new FakeTransport();
        $transport->throw = true;
        $sdk->setTransportForTesting($transport);

        $entries = $sdk->hashBatch([['rawData' => '{"a":1}', 'signingTime' => 1]]);

        $this->expectException(TransportException::class);

        $sdk->sendBatch($entries);
    }

    public function testQueryByHashReturnsHashAndTxHash(): void
    {
        $sdk = $this->makeSdk();
        $transport = new FakeTransport();
        $sdk->setTransportForTesting($transport);

        $result = $sdk->queryByHash('0xdeadbeef');

        $this->assertSame(['hash' => '0xdeadbeef', 'txHash' => '0xabc'], $result);
        $this->assertSame(['0xdeadbeef'], $transport->queryCalls);
    }

    public function testQueryByHashRejectsEmptyHash(): void
    {
        $sdk = $this->makeSdk();
        $sdk->setTransportForTesting(new FakeTransport());

        $this->expectException(ConfigException::class);

        $sdk->queryByHash('');
    }

    public function testQueryByHashThrowsOnNonSuccessStatusCode(): void
    {
        $sdk = $this->makeSdk();
        $transport = new FakeTransport();
        $transport->queryResponse = ['statusCode' => 404, 'body' => null];
        $sdk->setTransportForTesting($transport);

        $this->expectException(TransportException::class);

        $sdk->queryByHash('0xdeadbeef');
    }

    public function testQueryByHashThrowsOnMalformedBody(): void
    {
        $sdk = $this->makeSdk();
        $transport = new FakeTransport();
        $transport->queryResponse = ['statusCode' => 200, 'body' => 'not-json-object'];
        $sdk->setTransportForTesting($transport);

        $this->expectException(TransportException::class);

        $sdk->queryByHash('0xdeadbeef');
    }

    public function testQueryByHashThrowsOnTransportFailure(): void
    {
        $sdk = $this->makeSdk();
        $transport = new FakeTransport();
        $transport->throw = true;
        $sdk->setTransportForTesting($transport);

        $this->expectException(TransportException::class);

        $sdk->queryByHash('0xdeadbeef');
    }
}

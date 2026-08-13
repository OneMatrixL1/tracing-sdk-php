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

    public function testSendReturnsHashAndResponse(): void
    {
        $sdk = $this->makeSdk();
        $transport = new FakeTransport();
        $sdk->setTransportForTesting($transport);

        $result = $sdk->send('{"secret":"do-not-leak"}', 1234);

        $this->assertSame(['hash', 'response'], array_keys($result));
        $this->assertStringStartsWith('0x', $result['hash']);
        $this->assertSame(
            ['statusCode' => 200, 'body' => null, 'recordCount' => 1],
            $result['response']
        );
    }

    public function testSendPassesHashAndSigningTimeToTheTransport(): void
    {
        $sdk = $this->makeSdk();
        $transport = new FakeTransport();
        $sdk->setTransportForTesting($transport);

        $result = $sdk->send('{"a":1}', 1000);

        $this->assertSame(
            [['hash' => $result['hash'], 'signingTime' => 1000]],
            $transport->singleCalls
        );
        $this->assertSame([], $transport->batchCalls);
    }

    public function testSendingSameContentTwiceProducesSameHash(): void
    {
        $sdk = $this->makeSdk();
        $sdk->setTransportForTesting(new FakeTransport());

        $a = $sdk->send('{"a":1,"b":2}', 1);
        $b = $sdk->send('{"b":2,"a":1}', 2);

        $this->assertSame($a['hash'], $b['hash']);
    }

    public function testSendMissingSigningTimeThrows(): void
    {
        $sdk = $this->makeSdk();
        $transport = new FakeTransport();
        $sdk->setTransportForTesting($transport);

        try {
            $sdk->send('{"a":1}', null);
            $this->fail('Expected ConfigException');
        } catch (ConfigException $e) {
            $this->assertSame([], $transport->singleCalls);
        }
    }

    public function testSendBatchReturnsOneResultPerRecord(): void
    {
        $sdk = $this->makeSdk();
        $transport = new FakeTransport();
        $sdk->setTransportForTesting($transport);

        $results = $sdk->sendBatch([
            ['rawData' => '{"a":1}', 'signingTime' => 1],
            ['rawData' => '{"a":2}', 'signingTime' => 2],
        ]);

        $this->assertCount(2, $results);
        $this->assertSame(['hash', 'response'], array_keys($results[0]));
        $this->assertNotSame($results[0]['hash'], $results[1]['hash']);
        $this->assertSame(2, $results[0]['response']['recordCount']);
        $this->assertSame($results[0]['response'], $results[1]['response']);
    }

    public function testSendBatchSendsAllEntriesInOneRequest(): void
    {
        $sdk = $this->makeSdk();
        $transport = new FakeTransport();
        $sdk->setTransportForTesting($transport);

        $results = $sdk->sendBatch([
            ['rawData' => '{"a":1}', 'signingTime' => 1],
            ['rawData' => '{"a":2}', 'signingTime' => 2],
        ]);

        $this->assertSame([[
            ['hash' => $results[0]['hash'], 'signingTime' => 1],
            ['hash' => $results[1]['hash'], 'signingTime' => 2],
        ]], $transport->batchCalls);
        $this->assertSame([], $transport->singleCalls);
    }

    public function testSendBatchRequiresRawDataAndSigningTimeKeys(): void
    {
        $sdk = $this->makeSdk();
        $transport = new FakeTransport();
        $sdk->setTransportForTesting($transport);

        try {
            $sdk->sendBatch([['rawData' => '{"a":1}']]);
            $this->fail('Expected ConfigException');
        } catch (ConfigException $e) {
            $this->assertSame([], $transport->batchCalls);
        }
    }

    public function testRawDataTypeHashesInputByteForByteWithoutCanonicalizing(): void
    {
        $sdk = $this->makeSdk(['dataType' => 'raw']);
        $sdk->setTransportForTesting(new FakeTransport());

        // Deliberately not valid JSON/XML — 'raw' must not try to parse it.
        $rawData = '  not json, not xml, just bytes  ';
        $result = $sdk->send($rawData, 1000);

        $expectedHash = (new Keccak256Hasher())->hash($rawData);
        $this->assertSame($expectedHash, $result['hash']);
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

    public function testSendThrowsOnTransportFailure(): void
    {
        $sdk = $this->makeSdk();
        $transport = new FakeTransport();
        $transport->throw = true;
        $sdk->setTransportForTesting($transport);

        $this->expectException(TransportException::class);

        $sdk->send('{"a":1}', 1000);
    }

    public function testSendBatchThrowsOnTransportFailure(): void
    {
        $sdk = $this->makeSdk();
        $transport = new FakeTransport();
        $transport->throw = true;
        $sdk->setTransportForTesting($transport);

        $this->expectException(TransportException::class);

        $sdk->sendBatch([['rawData' => '{"a":1}', 'signingTime' => 1]]);
    }

    public function testQueryByHashReturnsHashAndEveryTxHash(): void
    {
        $sdk = $this->makeSdk();
        $transport = new FakeTransport();
        $sdk->setTransportForTesting($transport);

        $result = $sdk->queryByHash('0xdeadbeef');

        $this->assertSame(['hash' => '0xdeadbeef', 'txHashes' => ['0xabc', '0xdef']], $result);
        $this->assertSame(['0xdeadbeef'], $transport->queryCalls);
    }

    public function testQueryByHashReturnsEmptyTxHashesWhenIndexerReportsNone(): void
    {
        $sdk = $this->makeSdk();
        $transport = new FakeTransport();
        $transport->queryResponse = ['statusCode' => 200, 'body' => ['hash' => '0xdeadbeef', 'txHashes' => []]];
        $sdk->setTransportForTesting($transport);

        $this->assertSame(['hash' => '0xdeadbeef', 'txHashes' => []], $sdk->queryByHash('0xdeadbeef'));
    }

    public function testQueryByHashRejectsScalarTxHashes(): void
    {
        $sdk = $this->makeSdk();
        $transport = new FakeTransport();
        $transport->queryResponse = ['statusCode' => 200, 'body' => ['hash' => '0xdeadbeef', 'txHash' => '0xabc']];
        $sdk->setTransportForTesting($transport);

        $this->expectException(TransportException::class);

        $sdk->queryByHash('0xdeadbeef');
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

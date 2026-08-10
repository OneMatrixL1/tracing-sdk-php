<?php

declare(strict_types=1);

namespace Tracing\Sdk;

use Tracing\Sdk\Auth\ApiTokenAuthenticator;
use Tracing\Sdk\Auth\AuthenticatorInterface;
use Tracing\Sdk\Auth\BasicAuthenticator;
use Tracing\Sdk\Auth\MtlsAuthenticator;
use Tracing\Sdk\Buffer\RecordBuffer;
use Tracing\Sdk\Canonicalize\CanonicalizerInterface;
use Tracing\Sdk\Canonicalize\JsonCanonicalizer;
use Tracing\Sdk\Canonicalize\RawCanonicalizer;
use Tracing\Sdk\Canonicalize\XmlCanonicalizer;
use Tracing\Sdk\Event\EventEmitterTrait;
use Tracing\Sdk\Exception\ConfigException;
use Tracing\Sdk\Hash\HasherInterface;
use Tracing\Sdk\Hash\Keccak256Hasher;
use Tracing\Sdk\Transport\CurlHttpTransport;
use Tracing\Sdk\Transport\HttpTransportInterface;

/**
 * Everything from receiving a record up to the flush decision runs locally,
 * in-process, with no network I/O. The single external dependency is the
 * Indexer HTTP endpoint, contacted only when a flush condition is met
 * (buffer reaches `batchSize`, or `flushInterval` has elapsed).
 *
 * When `batchSize` is 0 or 1, buffering is skipped entirely: each record is
 * sent as soon as it's processed, via POST /api/anchors. Otherwise, records
 * accumulate in the buffer and are sent together via POST /api/anchors/batch.
 */
class TracingSDK
{
    use EventEmitterTrait;

    public const DATA_TYPE_JSON = 'json';
    public const DATA_TYPE_XML = 'xml';
    public const DATA_TYPE_RAW = 'raw';

    /** @var int */
    private $batchSize;

    /** @var bool */
    private $immediateMode;

    /** @var int */
    private $flushInterval;

    /** @var bool */
    private $flushOnShutdown;

    /** @var CanonicalizerInterface */
    private $canonicalizer;

    /** @var HasherInterface */
    private $hasher;

    /** @var RecordBuffer */
    private $buffer;

    /** @var HttpTransportInterface */
    private $transport;

    public function __construct(array $config)
    {
        $this->assertRequiredKeys($config, ['endpoint', 'dataType', 'auth']);

        $dataType = strtolower((string) $config['dataType']);
        $this->batchSize = array_key_exists('batchSize', $config) ? (int) $config['batchSize'] : 20;
        $this->flushInterval = array_key_exists('flushInterval', $config) ? (int) $config['flushInterval'] : 5000;
        $this->flushOnShutdown = array_key_exists('flushOnShutdown', $config) ? (bool) $config['flushOnShutdown'] : true;

        if ($this->batchSize < 0) {
            throw new ConfigException('batchSize must be >= 0');
        }

        if ($this->flushInterval < 0) {
            throw new ConfigException('flushInterval must be >= 0');
        }

        $this->immediateMode = $this->batchSize <= 1;

        $this->canonicalizer = $this->createCanonicalizer($dataType);
        $this->hasher = new Keccak256Hasher();
        $this->buffer = new RecordBuffer();
        $this->transport = new CurlHttpTransport(
            (string) $config['endpoint'],
            $this->createAuthenticator($config['auth'])
        );

        if ($this->flushOnShutdown) {
            register_shutdown_function(function (): void {
                if (!$this->buffer->isEmpty()) {
                    $this->flush();
                }
            });
        }
    }

    /**
     * Index one record — index($rawData, $signingTime) — or a batch:
     * index([['rawData' => ..., 'signingTime' => ...], ...]).
     *
     * @param string|array $rawDataOrRecords
     * @param mixed|null $signingTime
     */
    public function index($rawDataOrRecords, $signingTime = null): self
    {
        foreach ($this->normalizeInput($rawDataOrRecords, $signingTime) as $record) {
            $this->processRecord($record);
        }

        $this->maybeFlush();

        return $this;
    }

    /**
     * Re-check the time-based flush condition without indexing anything new.
     * Useful in long-running workers where index() may not be called often
     * enough on its own to notice that flushInterval has elapsed.
     */
    public function tick(): void
    {
        $this->maybeFlush();
    }

    /**
     * Force an immediate send of whatever is currently buffered, regardless
     * of batchSize/flushInterval.
     */
    public function flush(): void
    {
        if ($this->immediateMode || $this->buffer->isEmpty()) {
            return;
        }

        $batch = $this->buffer->drain();
        $this->buffer->markFlushed();

        try {
            $result = $this->transport->sendBatch($batch);
            $this->emit('sent', $result);
        } catch (\Throwable $e) {
            $this->emit('error', $e);
        }
    }

    public function pendingCount(): int
    {
        return $this->buffer->count();
    }

    /**
     * Swap the transport for a test double. Not part of the SDK's public
     * contract — for unit tests only, so specs can run without a live
     * Indexer endpoint.
     */
    public function setTransportForTesting(HttpTransportInterface $transport): void
    {
        $this->transport = $transport;
    }

    /**
     * @param mixed $rawDataOrRecords
     * @param mixed $signingTime
     * @return array<int, array{rawData: mixed, signingTime: mixed}>
     */
    private function normalizeInput($rawDataOrRecords, $signingTime): array
    {
        if ($signingTime !== null) {
            return [['rawData' => $rawDataOrRecords, 'signingTime' => $signingTime]];
        }

        if (!is_array($rawDataOrRecords)) {
            throw new ConfigException(
                'index() requires either (rawData, signingTime) or an array of {rawData, signingTime} records'
            );
        }

        if (array_key_exists('rawData', $rawDataOrRecords)) {
            return [$rawDataOrRecords];
        }

        return $rawDataOrRecords;
    }

    private function processRecord(array $record): void
    {
        if (!array_key_exists('rawData', $record) || !array_key_exists('signingTime', $record)) {
            throw new ConfigException('Each record requires "rawData" and "signingTime"');
        }

        if ($record['signingTime'] === null) {
            throw new ConfigException('signingTime is required for each record');
        }

        try {
            $canonical = $this->canonicalizer->canonicalize((string) $record['rawData']);
            $hash = $this->hasher->hash($canonical);
        } catch (\Throwable $e) {
            $this->emit('error', $e);
            throw $e;
        }

        $entry = [
            'hash' => $hash,
            'signingTime' => $record['signingTime'],
        ];

        if ($this->immediateMode) {
            $this->sendImmediately($entry);
            return;
        }

        $this->buffer->push($entry);
    }

    /**
     * @param array{hash: string, signingTime: mixed} $entry
     */
    private function sendImmediately(array $entry): void
    {
        try {
            $result = $this->transport->sendSingle($entry);
            $this->emit('sent', $result);
        } catch (\Throwable $e) {
            $this->emit('error', $e);
        }
    }

    private function maybeFlush(): void
    {
        if ($this->immediateMode || $this->buffer->isEmpty()) {
            return;
        }

        $sizeTrigger = $this->buffer->count() >= $this->batchSize;
        $timeTrigger = $this->flushInterval > 0 && $this->buffer->elapsedSinceLastFlushMs() >= $this->flushInterval;

        if ($sizeTrigger || $timeTrigger) {
            $this->flush();
        }
    }

    private function createCanonicalizer(string $dataType): CanonicalizerInterface
    {
        switch ($dataType) {
            case self::DATA_TYPE_JSON:
                return new JsonCanonicalizer();
            case self::DATA_TYPE_XML:
                return new XmlCanonicalizer();
            case self::DATA_TYPE_RAW:
                return new RawCanonicalizer();
            default:
                throw new ConfigException(sprintf('Unsupported dataType "%s", expected "json", "xml", or "raw"', $dataType));
        }
    }

    private function createAuthenticator(array $auth): AuthenticatorInterface
    {
        $this->assertRequiredKeys($auth, ['type']);
        $type = strtolower((string) $auth['type']);

        switch ($type) {
            case 'mtls':
                $this->assertRequiredKeys($auth, ['cert', 'key']);

                return new MtlsAuthenticator(
                    (string) $auth['cert'],
                    (string) $auth['key'],
                    isset($auth['caCert']) ? (string) $auth['caCert'] : null,
                    isset($auth['passphrase']) ? (string) $auth['passphrase'] : null
                );
            case 'basic':
                $this->assertRequiredKeys($auth, ['username', 'password']);

                return new BasicAuthenticator((string) $auth['username'], (string) $auth['password']);
            case 'apitoken':
                $this->assertRequiredKeys($auth, ['token']);

                return new ApiTokenAuthenticator((string) $auth['token']);
            default:
                throw new ConfigException(sprintf('Unsupported auth.type "%s"', $auth['type']));
        }
    }

    private function assertRequiredKeys(array $config, array $keys): void
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $config) || $config[$key] === null || $config[$key] === '') {
                throw new ConfigException(sprintf('Missing required config key "%s"', $key));
            }
        }
    }
}

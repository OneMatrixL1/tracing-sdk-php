<?php

declare(strict_types=1);

namespace Tracing\Sdk;

use Tracing\Sdk\Auth\ApiTokenAuthenticator;
use Tracing\Sdk\Auth\AuthenticatorInterface;
use Tracing\Sdk\Auth\BasicAuthenticator;
use Tracing\Sdk\Auth\MtlsAuthenticator;
use Tracing\Sdk\Canonicalize\CanonicalizerInterface;
use Tracing\Sdk\Canonicalize\JsonCanonicalizer;
use Tracing\Sdk\Canonicalize\RawCanonicalizer;
use Tracing\Sdk\Canonicalize\XmlCanonicalizer;
use Tracing\Sdk\Exception\ConfigException;
use Tracing\Sdk\Exception\TransportException;
use Tracing\Sdk\Hash\HasherInterface;
use Tracing\Sdk\Hash\Keccak256Hasher;
use Tracing\Sdk\Transport\CurlHttpTransport;
use Tracing\Sdk\Transport\HttpTransportInterface;

/**
 * Canonicalizes a record, hashes it with Keccak-256, and sends the resulting
 * { hash, signingTime } entry to the Indexer.
 */
class TracingSDK
{
    public const DATA_TYPE_JSON = 'json';
    public const DATA_TYPE_XML = 'xml';
    public const DATA_TYPE_RAW = 'raw';

    /** @var array<string, CanonicalizerInterface> */
    private $canonicalizers = [];

    /** @var SendOptions */
    private $defaultOptions;

    /** @var HasherInterface */
    private $hasher;

    /** @var HttpTransportInterface */
    private $transport;

    /**
     * @param array{endpoint: string, auth: array, options?: SendOptions} $config
     *        "options" holds the defaults every send()/sendBatch()/hash() call
     *        falls back to when it doesn't pass its own SendOptions.
     */
    public function __construct(array $config)
    {
        $this->assertRequiredKeys($config, ['endpoint', 'auth']);

        if (isset($config['options']) && !$config['options'] instanceof SendOptions) {
            throw new ConfigException('Config key "options" must be a ' . SendOptions::class);
        }

        $this->defaultOptions = $config['options'] ?? new SendOptions();

        if ($this->defaultOptions->getDataType() !== null) {
            // Fail fast on an unsupported default rather than at the first send().
            $this->canonicalizerFor(null);
        }

        $this->hasher = new Keccak256Hasher();
        $this->transport = new CurlHttpTransport(
            (string) $config['endpoint'],
            $this->createAuthenticator($config['auth']),
            $this->defaultOptions->getTimeoutMs() ?? CurlHttpTransport::DEFAULT_TIMEOUT_MS
        );
    }

    /**
     * Canonicalize, hash, and send one record via POST /api/anchors.
     *
     * @param string $rawData
     * @param mixed $signingTime
     * @param SendOptions|null $options per-call overrides; falls back to config
     * @return array{hash: string, response: array{statusCode: int, body: mixed, recordCount: int}}
     * @throws \Tracing\Sdk\Exception\CanonicalizationException
     * @throws ConfigException if signingTime is missing, or no dataType is given here or in config
     * @throws TransportException
     */
    public function send(string $rawData, $signingTime, ?SendOptions $options = null): array
    {
        if ($signingTime === null) {
            throw new ConfigException('signingTime is required');
        }

        $entry = [
            'hash' => $this->hash($rawData, $options),
            'signingTime' => $signingTime,
        ];

        return [
            'hash' => $entry['hash'],
            'response' => $this->transport->sendSingle($entry, $this->timeoutMsFor($options)),
        ];
    }

    /**
     * Canonicalize, hash, and send multiple records via POST /api/anchors/batch.
     *
     * @param array<int, array{rawData: string, signingTime: mixed}> $records
     * @param SendOptions|null $options per-call overrides; falls back to config
     * @return array<int, array{hash: string, response: array{statusCode: int, body: mixed, recordCount: int}}>
     * @throws \Tracing\Sdk\Exception\CanonicalizationException
     * @throws ConfigException if a record is missing "rawData" or "signingTime", either is null,
     *         or no dataType is given here or in config
     * @throws TransportException
     */
    public function sendBatch(array $records, ?SendOptions $options = null): array
    {
        $entries = [];

        foreach ($records as $record) {
            if (!isset($record['rawData']) || !isset($record['signingTime'])) {
                throw new ConfigException('Each record requires "rawData" and "signingTime"');
            }

            $entries[] = [
                'hash' => $this->hash((string) $record['rawData'], $options),
                'signingTime' => $record['signingTime'],
            ];
        }

        $response = $this->transport->sendBatch($entries, $this->timeoutMsFor($options));
        $results = [];

        foreach ($entries as $entry) {
            $results[] = ['hash' => $entry['hash'], 'response' => $response];
        }

        return $results;
    }

    /**
     * @param string $rawData
     * @param SendOptions|null $options per-call overrides; falls back to config
     * @return string
     * @throws \Tracing\Sdk\Exception\CanonicalizationException
     * @throws ConfigException if no dataType is given here or in config
     */
    public function hash(string $rawData, ?SendOptions $options = null): string
    {
        $dataType = $options !== null ? $options->getDataType() : null;
        $canonical = $this->canonicalizerFor($dataType)->canonicalize($rawData);

        return $this->hasher->hash($canonical);
    }

    /**
     * Look up an anchored record by its hash via GET /api/anchors?hash=...
     *
     * @param string $hash
     * @param SendOptions|null $options per-call overrides; falls back to config
     * @return array{hash: string, txHashes: array<int, string>}
     * @throws TransportException if the request fails,
     *         the Indexer returns a non-2xx status, or the response body is
     *         not the expected { hash, txHashes } object
     * @throws ConfigException if hash is empty
     */
    public function queryByHash(string $hash, ?SendOptions $options = null): array
    {
        if ($hash === '') {
            throw new ConfigException('hash is required');
        }

        $response = $this->transport->queryByHash($hash, $this->timeoutMsFor($options));

        if ($response['statusCode'] < 200 || $response['statusCode'] >= 300) {
            throw new TransportException(\sprintf('Query by hash failed with HTTP %d', $response['statusCode']));
        }

        $body = $response['body'];

        if (!\is_array($body) || !isset($body['hash']) || !isset($body['txHashes']) || !\is_array($body['txHashes'])) {
            throw new TransportException('Unexpected response body for query by hash, expected { hash, txHashes }');
        }

        $txHashes = [];

        foreach ($body['txHashes'] as $txHash) {
            $txHashes[] = (string) $txHash;
        }

        return ['hash' => (string) $body['hash'], 'txHashes' => $txHashes];
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
     * Resolve the request timeout for this call: the per-call timeoutMs when
     * one was given, otherwise null so the transport keeps the config default
     * it was constructed with.
     */
    private function timeoutMsFor(?SendOptions $options): ?int
    {
        return $options !== null ? $options->getTimeoutMs() : null;
    }

    /**
     * Resolve the canonicalizer for this call: the per-call dataType when one
     * was given, otherwise the config default. Instances are reused.
     */
    private function canonicalizerFor(?string $dataType): CanonicalizerInterface
    {
        $dataType = $dataType !== null ? $dataType : $this->defaultOptions->getDataType();

        if ($dataType === null) {
            throw new ConfigException('dataType is required, either in the config "options" or per call');
        }

        $dataType = strtolower($dataType);

        if (!isset($this->canonicalizers[$dataType])) {
            $this->canonicalizers[$dataType] = $this->createCanonicalizer($dataType);
        }

        return $this->canonicalizers[$dataType];
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
                throw new ConfigException(\sprintf('Unsupported dataType "%s", expected "json", "xml", or "raw"', $dataType));
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
                throw new ConfigException(\sprintf('Unsupported auth.type "%s"', $auth['type']));
        }
    }

    private function assertRequiredKeys(array $config, array $keys): void
    {
        foreach ($keys as $key) {
            if (!\array_key_exists($key, $config) || $config[$key] === null || $config[$key] === '') {
                throw new ConfigException(\sprintf('Missing required config key "%s"', $key));
            }
        }
    }
}

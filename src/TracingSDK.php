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

    /** @var CanonicalizerInterface */
    private $canonicalizer;

    /** @var HasherInterface */
    private $hasher;

    /** @var HttpTransportInterface */
    private $transport;

    public function __construct(array $config)
    {
        $this->assertRequiredKeys($config, ['endpoint', 'dataType', 'auth']);

        $dataType = strtolower((string) $config['dataType']);

        $this->canonicalizer = $this->createCanonicalizer($dataType);
        $this->hasher = new Keccak256Hasher();
        $this->transport = new CurlHttpTransport(
            (string) $config['endpoint'],
            $this->createAuthenticator($config['auth'])
        );
    }

    /**
     * Canonicalize, hash, and send one record via POST /api/anchors.
     *
     * @param string $rawData
     * @param mixed $signingTime
     * @return array{hash: string, response: array{statusCode: int, body: mixed, recordCount: int}}
     * @throws \Tracing\Sdk\Exception\CanonicalizationException
     * @throws ConfigException if signingTime is missing
     * @throws TransportException
     */
    public function send(string $rawData, $signingTime): array
    {
        $entry = $this->hash($rawData, $signingTime);

        return [
            'hash' => $entry['hash'],
            'response' => $this->transport->sendSingle($entry),
        ];
    }

    /**
     * Canonicalize, hash, and send multiple records via POST /api/anchors/batch.
     *
     * @param array<int, array{rawData: string, signingTime: mixed}> $records
     * @return array<int, array{hash: string, response: array{statusCode: int, body: mixed, recordCount: int}}>
     * @throws \Tracing\Sdk\Exception\CanonicalizationException
     * @throws ConfigException if a record is missing "rawData" or "signingTime"
     * @throws TransportException
     */
    public function sendBatch(array $records): array
    {
        $entries = [];

        foreach ($records as $record) {
            if (!\array_key_exists('rawData', $record) || !\array_key_exists('signingTime', $record)) {
                throw new ConfigException('Each record requires "rawData" and "signingTime"');
            }

            $entries[] = $this->hash((string) $record['rawData'], $record['signingTime']);
        }

        $response = $this->transport->sendBatch($entries);
        $results = [];

        foreach ($entries as $entry) {
            $results[] = ['hash' => $entry['hash'], 'response' => $response];
        }

        return $results;
    }

    /**
     * @param string $rawData
     * @param mixed $signingTime
     * @return array{hash: string, signingTime: mixed}
     */
    private function hash(string $rawData, $signingTime): array
    {
        if ($signingTime === null) {
            throw new ConfigException('signingTime is required');
        }

        $canonical = $this->canonicalizer->canonicalize($rawData);

        return [
            'hash' => $this->hasher->hash($canonical),
            'signingTime' => $signingTime,
        ];
    }

    /**
     * Look up an anchored record by its hash via GET /api/anchors?hash=...
     *
     * @param string $hash
     * @return array{hash: string, txHashes: array<int, string>}
     * @throws TransportException if the request fails,
     *         the Indexer returns a non-2xx status, or the response body is
     *         not the expected { hash, txHashes } object
     * @throws ConfigException if hash is empty
     */
    public function queryByHash(string $hash): array
    {
        if ($hash === '') {
            throw new ConfigException('hash is required');
        }

        $response = $this->transport->queryByHash($hash);

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

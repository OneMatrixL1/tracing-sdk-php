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
use Tracing\Sdk\Rpc\CurlRpcTransport;
use Tracing\Sdk\Rpc\RpcTransportInterface;
use Tracing\Sdk\Transport\CurlHttpTransport;
use Tracing\Sdk\Transport\HttpTransportInterface;
use Tracing\Sdk\Verify\AnchoredEventDecoder;
use Tracing\Sdk\Verify\MerkleProof;

/**
 * Canonicalizes a record, hashes it with Keccak-256, and sends the resulting
 * { hash, signingTime } entry to the Indexer.
 */
class TracingSDK
{
    public const DATA_TYPE_JSON = 'json';
    public const DATA_TYPE_XML = 'xml';
    public const DATA_TYPE_RAW = 'raw';

    /**
     * verify() mode for proofs that are transaction hashes: each proof is a
     * transaction whose Anchored event carries the record hash itself.
     */
    public const MODE_TRANSACTION_HASH = 'transactionHash';

    /**
     * verify() mode for Merkle proofs: the proof is one array whose first
     * element is the transaction hash and whose remaining elements are the
     * record's sibling hashes, leaf to root. The transaction's Anchored event
     * carries the Merkle root (see Verify\MerkleProof for the tree layout).
     */
    public const MODE_MERKLE_PROOF = 'merkleProof';

    /** @var array<string, CanonicalizerInterface> */
    private $canonicalizers = [];

    /** @var SendOptions */
    private $defaultOptions;

    /** @var HasherInterface */
    private $hasher;

    /** @var HttpTransportInterface */
    private $transport;

    /** @var RpcTransportInterface */
    private $rpcTransport;

    /** @var AnchoredEventDecoder */
    private $eventDecoder;

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
        $this->eventDecoder = new AnchoredEventDecoder($this->hasher);
        $this->transport = new CurlHttpTransport(
            (string) $config['endpoint'],
            $this->createAuthenticator($config['auth']),
            $this->defaultOptions->getTimeoutMs() ?? CurlHttpTransport::DEFAULT_TIMEOUT_MS
        );
        $this->rpcTransport = new CurlRpcTransport(
            $this->defaultOptions->getTimeoutMs() ?? CurlRpcTransport::DEFAULT_TIMEOUT_MS
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
     * @return array{hash: string, proof: array<int, string>, proofType: string}
     *         proofType names how each proof should be resolved on chain and
     *         can be passed straight to verify() as its $mode
     * @throws TransportException if the request fails,
     *         the Indexer returns a non-2xx status, or the response body is
     *         not the expected { hash, proof } object
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

        if (!\is_array($body) || !isset($body['hash']) || !isset($body['proof']) || !\is_array($body['proof'])) {
            throw new TransportException('Unexpected response body for query by hash, expected { hash, proof }');
        }

        $proofs = [];

        foreach ($body['proof'] as $element) {
            $proofs[] = (string) $element;
        }

        return [
            'hash' => (string) $body['hash'],
            'proof' => $proofs,
            // Older Indexers answer without a proofType; transaction hashes
            // were the only proof kind then, so that is the safe assumption.
            'proofType' => isset($body['proofType'])
                ? (string) $body['proofType']
                : self::MODE_TRANSACTION_HASH,
        ];
    }

    /**
     * Check a query result against the chain itself: fetch the proof's
     * transaction logs from the configured JSON-RPC endpoint, ABI-decode the
     * ones emitted as Anchored(bytes32,uint64), and report whether one of them
     * carries the expected hash.
     *
     * $proof is either the whole proof array from queryByHash() or one element
     * of it; pass queryByHash()'s proofType as $mode, so
     * verify($anchor['hash'], $anchor['proof'], $anchor['proofType']) works for
     * every proof kind:
     *  - MODE_TRANSACTION_HASH: each element is a transaction, checked in
     *    order until one carries an Anchored event with the data hash itself;
     *  - MODE_MERKLE_PROOF: the array is [transaction hash, sibling hashes...].
     *    The siblings fold the data hash into a Merkle root
     *    (Verify\MerkleProof::computeRoot), and the transaction must carry an
     *    Anchored event with that root. A lone element is a one-leaf tree,
     *    whose root is the data hash itself.
     *
     * A log matches when its topics[0] equals keccak256 of the event
     * signature and its decoded bytes32 argument equals the expected hash. The
     * bytes32 is read from topics[1] when the argument is indexed and from the
     * log data otherwise, so both layouts verify.
     *
     * @param string $dataHash the record hash, as returned by hash()/send()
     * @param string|array<int, string> $proof queryByHash()'s proof, or one
     *        element of it
     * @param string $mode one of the self::MODE_* constants
     * @param SendOptions|null $options per-call overrides; falls back to config.
     *        An rpcUrl must be given here or in the config "options".
     * @return bool true when the chain confirms the record was anchored
     * @throws ConfigException if dataHash or a proof element is empty or not 32
     *         bytes of hex, the proof is empty, the mode is unsupported, or no
     *         rpcUrl is given here or in config
     * @throws TransportException if an RPC call fails or the node does not
     *         know a transaction
     */
    public function verify(
        string $dataHash,
        $proof,
        string $mode = self::MODE_TRANSACTION_HASH,
        ?SendOptions $options = null
    ): bool {
        if ($mode !== self::MODE_TRANSACTION_HASH && $mode !== self::MODE_MERKLE_PROOF) {
            throw new ConfigException(\sprintf(
                'Unsupported verify mode "%s", expected "%s" or "%s"',
                $mode,
                self::MODE_TRANSACTION_HASH,
                self::MODE_MERKLE_PROOF
            ));
        }

        $dataHash = AnchoredEventDecoder::normalizeHash($dataHash, 'dataHash');
        $elements = $this->normalizeProof($proof);
        $rpcUrl = $this->rpcUrlFor($options);

        if ($mode === self::MODE_MERKLE_PROOF) {
            // proof = [transaction hash, sibling hashes leaf-to-root]; the
            // transaction anchors the root the siblings fold the leaf into.
            $root = MerkleProof::computeRoot($dataHash, \array_slice($elements, 1));

            return $this->anchors($rpcUrl, $elements[0], $root, $options);
        }

        foreach ($elements as $txHash) {
            if ($this->anchors($rpcUrl, $txHash, $dataHash, $options)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate a verify() proof — one element or the whole array — as a
     * non-empty list of normalized 32-byte hashes.
     *
     * @param mixed $proof
     * @return array<int, string>
     * @throws ConfigException
     */
    private function normalizeProof($proof): array
    {
        if (\is_string($proof)) {
            return [AnchoredEventDecoder::normalizeHash($proof, 'proof')];
        }

        if (!\is_array($proof)) {
            throw new ConfigException('proof must be a string or an array of strings');
        }

        if ($proof === []) {
            throw new ConfigException('proof is required');
        }

        $elements = [];

        foreach (array_values($proof) as $i => $element) {
            if (!\is_string($element)) {
                throw new ConfigException(\sprintf('proof[%d] must be a string', $i));
            }

            $elements[] = AnchoredEventDecoder::normalizeHash($element, \sprintf('proof[%d]', $i));
        }

        return $elements;
    }

    /**
     * Whether the transaction's receipt holds an Anchored event carrying
     * $anchoredHash.
     *
     * @throws TransportException if the RPC call fails or the node does not
     *         know the transaction
     */
    private function anchors(string $rpcUrl, string $txHash, string $anchoredHash, ?SendOptions $options): bool
    {
        $receipt = $this->rpcTransport->getTransactionReceipt($rpcUrl, $txHash, $this->timeoutMsFor($options));

        if ($receipt === null) {
            throw new TransportException(\sprintf('Transaction %s was not found on the RPC endpoint', $txHash));
        }

        $logs = isset($receipt['logs']) && \is_array($receipt['logs']) ? $receipt['logs'] : [];

        foreach ($logs as $log) {
            $event = $this->eventDecoder->decode($log);

            if ($event !== null && $event['dataHash'] === $anchoredHash) {
                return true;
            }
        }

        return false;
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
     * Swap the JSON-RPC transport for a test double. Test-only, like
     * setTransportForTesting().
     */
    public function setRpcTransportForTesting(RpcTransportInterface $rpcTransport): void
    {
        $this->rpcTransport = $rpcTransport;
    }

    /**
     * Resolve the JSON-RPC endpoint for this call: the per-call rpcUrl when
     * one was given, otherwise the config default.
     *
     * @throws ConfigException if neither supplies one
     */
    private function rpcUrlFor(?SendOptions $options): string
    {
        $rpcUrl = $options !== null ? $options->getRpcUrl() : null;
        $rpcUrl = $rpcUrl !== null ? $rpcUrl : $this->defaultOptions->getRpcUrl();

        if ($rpcUrl === null) {
            throw new ConfigException('rpcUrl is required to verify, either in the config "options" or per call');
        }

        return $rpcUrl;
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
